import { getXsrfHeaders } from '~/lib/csrf'

/**
 * Upload failure carrying the HTTP status and the server's validation body, so a
 * caller's `catch (e) { e.message }` reads the real reason instead of `undefined`.
 * `body` keeps the parsed response for callers that act on a specific payload
 * (e.g. a 409 duplicate-asset response).
 */
export class UploadError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]> | null
  readonly body: unknown

  constructor(
    message: string,
    status = 0,
    errors: Record<string, string[]> | null = null,
    body: unknown = null
  ) {
    super(message)
    this.name = 'UploadError'
    this.status = status
    this.errors = errors
    this.body = body
  }
}

export interface XhrUploadOptions {
  /** Defaults to POST. */
  method?: string
  /** Applied last, so a caller can override the XSRF or accept header. */
  headers?: Record<string, string>
  onProgress?: (progress: number) => void
  /** Aborts the request; aborting before it starts prevents it entirely. */
  signal?: AbortSignal
  /** Message for a non-2xx response whose body carries no usable message. */
  fallbackMessage?: (status: number, statusText: string) => string
}

interface ErrorResponseBody {
  message?: string
  errors?: Record<string, string[]>
}

const defaultFallbackMessage = (status: number, statusText: string): string =>
  `Upload failed: ${statusText || status}`

/** Turn a non-2xx response into an error that keeps the server's own wording. */
const toUploadError = (xhr: XMLHttpRequest, fallbackMessage: XhrUploadOptions['fallbackMessage']) => {
  const fallback = (fallbackMessage ?? defaultFallbackMessage)(xhr.status, xhr.statusText)

  try {
    const body = JSON.parse(xhr.responseText) as ErrorResponseBody
    const errors = body?.errors ?? null
    const firstFieldError = errors ? Object.values(errors)[0]?.[0] : undefined

    return new UploadError(body?.message || firstFieldError || fallback, xhr.status, errors, body)
  } catch {
    return new UploadError(fallback, xhr.status)
  }
}

/**
 * One XMLHttpRequest upload: progress events, credentialed request with the XSRF
 * header, 2xx JSON parsing and a typed {@link UploadError} for everything else.
 * XHR rather than fetch because it is the only way to observe upload progress.
 */
export function xhrUpload<T>(
  path: string,
  formData: FormData,
  options: XhrUploadOptions = {}
): Promise<T> {
  const { method = 'POST', headers, onProgress, signal, fallbackMessage } = options

  return new Promise<T>((resolve, reject) => {
    if (signal?.aborted) {
      reject(new UploadError('Upload was aborted'))

      return
    }

    const xhr = new XMLHttpRequest()
    const onAbortSignal = () => xhr.abort()

    const settle = (run: () => void) => {
      signal?.removeEventListener('abort', onAbortSignal)
      run()
    }

    xhr.upload.addEventListener('progress', (event) => {
      if (!event.lengthComputable) return
      onProgress?.(Math.round((event.loaded / event.total) * 100))
    })
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const parsed = JSON.parse(xhr.responseText) as T
          settle(() => resolve(parsed))
        } catch {
          settle(() =>
            reject(new UploadError('Failed to parse server response', xhr.status))
          )
        }

        return
      }

      settle(() => reject(toUploadError(xhr, fallbackMessage)))
    })
    xhr.addEventListener('error', () =>
      settle(() => reject(new UploadError('Network error occurred during upload')))
    )
    xhr.addEventListener('abort', () => settle(() => reject(new UploadError('Upload was aborted'))))

    xhr.open(method, path)
    xhr.withCredentials = true
    xhr.setRequestHeader('accept', 'application/json')

    Object.entries({ ...getXsrfHeaders(), ...headers }).forEach(([key, value]) => {
      xhr.setRequestHeader(key, value)
    })

    signal?.addEventListener('abort', onAbortSignal)
    xhr.send(formData)
  })
}
