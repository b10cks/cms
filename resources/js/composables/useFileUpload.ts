import type { Ref } from 'vue'

import { useApiClient } from '~/composables/useApiClient'
import { UploadError, xhrUpload } from '~/lib/xhr-upload'

export { UploadError } from '~/lib/xhr-upload'

interface UploadOptions {
  url: string
  fieldName?: string // default: 'file'
  headers?: Record<string, string>
  onProgress?: (progress: number) => void
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
    const controller = new AbortController()

    const settle = (failure: UploadError | null) => {
      taskIsUploading.value = false
      uploads.value = uploads.value.filter((entry) => entry !== task)
      taskError.value = failure
      lastError.value = failure
    }

    const promise = (async (): Promise<T> => {
      await apiClient.ensureCsrfCookie()
      const formData = new FormData()
      formData.append(options.fieldName || 'file', file)

      try {
        const result = await xhrUpload<T>(options.url, formData, {
          headers: options.headers,
          signal: controller.signal,
          onProgress: (value) => {
            progress.value = value
            options.onProgress?.(value)
          },
        })

        settle(null)

        return result
      } catch (e) {
        const failure = e instanceof UploadError ? e : new UploadError((e as Error).message)
        settle(failure)

        throw failure
      }
    })()

    const task: UploadTask<T> = {
      file,
      progress,
      isUploading: taskIsUploading,
      error: taskError,
      promise,
      abort: () => controller.abort(),
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
