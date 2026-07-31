import type { Ref } from 'vue'

import { useApiClient } from '~/composables/useApiClient'
import { getXsrfHeaders } from '~/lib/csrf'

interface UploadOptions {
  url: string
  fieldName?: string // default: 'file'
  headers?: Record<string, string>
  onProgress?: (progress: number) => void
}

/**
 * Upload failure carrying the HTTP status and the server's validation body, so a
 * caller's `catch (e) { e.message }` reads the real reason instead of `undefined`.
 */
export class UploadError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]> | null

  constructor(message: string, status = 0, errors: Record<string, string[]> | null = null) {
    super(message)
    this.name = 'UploadError'
    this.status = status
    this.errors = errors
  }
}

/** Observable state of a single in-flight upload, plus its cancel handle. */
export interface UploadTask<T = any> {
  readonly file: File
  readonly progress: Ref<number>
  readonly isUploading: Ref<boolean>
  readonly error: Ref<UploadError | null>
  readonly promise: Promise<T>
  abort: () => void
}

/** Turn a non-2xx response into an error that keeps the server's own wording. */
const toUploadError = (xhr: XMLHttpRequest): UploadError => {
  const fallback = `Upload failed: ${xhr.statusText || xhr.status}`
  try {
    const body = JSON.parse(xhr.responseText) as {
      message?: string
      errors?: Record<string, string[]>
    }
    const errors = body?.errors ?? null
    const firstFieldError = errors ? Object.values(errors)[0]?.[0] : undefined
    return new UploadError(body?.message || firstFieldError || fallback, xhr.status, errors)
  } catch {
    return new UploadError(fallback, xhr.status)
  }
}

export function useFileUpload() {
  // shallowRef: a deep ref would proxy each task and unwrap its inner refs, which
  // breaks both `task.progress.value` and identity comparison against the array.
  const uploads = shallowRef<UploadTask[]>([])
  const lastError = ref<UploadError | null>(null)
  const { client: apiClient } = useApiClient()

  /** Any upload still in flight — not just the most recently started one. */
  const isUploading = computed(() => uploads.value.length > 0)
  /** The most recent failure, cleared once an upload succeeds. */
  const error = computed(() => lastError.value?.message ?? null)

  const startUpload = <T = any>(file: File, options: UploadOptions): UploadTask<T> => {
    const progress = ref(0)
    const taskIsUploading = ref(true)
    const taskError = ref<UploadError | null>(null)
    let xhr: XMLHttpRequest | null = null
    let abortRequested = false

    const promise = (async (): Promise<T> => {
      await apiClient.ensureCsrfCookie()
      const formData = new FormData()
      formData.append(options.fieldName || 'file', file)

      return await new Promise<T>((resolve, reject) => {
        const settle = (failure: UploadError | null, value?: T) => {
          taskIsUploading.value = false
          uploads.value = uploads.value.filter((entry) => entry !== task)
          if (failure) {
            taskError.value = failure
            lastError.value = failure
            reject(failure)
            return
          }
          lastError.value = null
          resolve(value as T)
        }

        if (abortRequested) {
          settle(new UploadError('Upload was aborted'))
          return
        }

        xhr = new XMLHttpRequest()
        const request = xhr
        request.upload.addEventListener('progress', (event) => {
          if (!event.lengthComputable) return
          progress.value = Math.round((event.loaded / event.total) * 100)
          options.onProgress?.(progress.value)
        })
        request.addEventListener('load', () => {
          if (request.status >= 200 && request.status < 300) {
            try {
              settle(null, JSON.parse(request.responseText) as T)
            } catch {
              settle(new UploadError('Failed to parse server response', request.status))
            }
            return
          }
          settle(toUploadError(request))
        })
        request.addEventListener('error', () => {
          settle(new UploadError('Network error occurred during upload'))
        })
        request.addEventListener('abort', () => {
          settle(new UploadError('Upload was aborted'))
        })
        request.open('POST', options.url)
        request.withCredentials = true
        // Set headers
        request.setRequestHeader('accept', 'application/json')
        const xsrfHeaders = getXsrfHeaders()
        Object.entries(xsrfHeaders).forEach(([key, value]) => {
          request.setRequestHeader(key, value)
        })
        if (options.headers) {
          Object.entries(options.headers).forEach(([key, value]) => {
            request.setRequestHeader(key, value)
          })
        }
        request.send(formData)
      })
    })()

    const task: UploadTask<T> = {
      file,
      progress,
      isUploading: taskIsUploading,
      error: taskError,
      promise,
      abort: () => {
        abortRequested = true
        xhr?.abort()
      },
    }

    uploads.value = [...uploads.value, task]

    return task
  }

  const upload = <T = any>(file: File, options: UploadOptions): Promise<T> =>
    startUpload<T>(file, options).promise

  return {
    isUploading,
    error,
    upload,
    uploads,
    startUpload,
  }
}
