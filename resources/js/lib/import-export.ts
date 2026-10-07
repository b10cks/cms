import type { ApiClient } from '~/api/client'
import { getXsrfHeaders } from '~/lib/csrf'
import { consumeSseStream, parseStreamErrorResponse } from '~/lib/sse'
import type { ImportExportFormat, ImportProgress } from '~/types/import-export'

const parseErrorMessage = async (response: Response, fallback: string) => {
  const contentType = response.headers.get('content-type') ?? ''

  if (contentType.includes('application/json')) {
    try {
      const payload = await response.json()
      if (typeof payload?.message === 'string' && payload.message.trim() !== '') {
        return payload.message
      }
    } catch {
      // Ignore response parsing issues and fall back to the default message.
    }
  }

  return `${fallback} with status ${response.status}: ${response.statusText}`
}

export async function requestExportBlob({
  client,
  endpoint,
  payload,
}: {
  client: ApiClient
  endpoint: string
  payload: unknown
}): Promise<Blob> {
  if (typeof window === 'undefined') {
    throw new Error('Export is only available in the browser')
  }

  await client.ensureCsrfCookie()

  const response = await fetch(`${client.getBaseUrl()}${endpoint}`, {
    method: 'POST',
    headers: {
      ...client.getAuthHeaders(),
      ...getXsrfHeaders(),
      'Content-Type': 'application/json',
    },
    credentials: 'include',
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    throw new Error(await parseErrorMessage(response, 'Export failed'))
  }

  return response.blob()
}

interface ImportRequest {
  client: ApiClient
  endpoint: string
  file: File
  extraFields?: Record<string, string>
}

async function postImport(
  { client, endpoint, file, extraFields }: ImportRequest,
  headers: Record<string, string> = {}
): Promise<Response> {
  if (typeof window === 'undefined') {
    throw new Error('Import is only available in the browser')
  }

  const formData = new FormData()
  formData.append('file', file)

  if (extraFields) {
    for (const [key, value] of Object.entries(extraFields)) {
      formData.append(key, value)
    }
  }

  await client.ensureCsrfCookie()

  return fetch(`${client.getBaseUrl()}${endpoint}`, {
    method: 'POST',
    headers: {
      ...client.getAuthHeaders(),
      ...getXsrfHeaders(),
      ...headers,
    },
    credentials: 'include',
    body: formData,
  })
}

export async function requestImportJson<T>(request: ImportRequest): Promise<T> {
  const response = await postImport(request)

  if (!response.ok) {
    throw new Error(await parseErrorMessage(response, 'Import failed'))
  }

  return response.json() as Promise<T>
}

/**
 * Import over server-sent events: the endpoint reports `{processed, total}` while it
 * works and sends the result with the final `done` event. Used where a large file
 * outlives a plain request's time limit.
 */
export async function requestImportStream<T>(
  request: ImportRequest & { onProgress?: (progress: ImportProgress) => void }
): Promise<T> {
  const response = await postImport(request, { Accept: 'text/event-stream' })

  if (!response.ok) {
    throw new Error((await parseStreamErrorResponse(response)).message)
  }

  const reader = response.body?.getReader()
  if (!reader) throw new Error('No response body')

  let result: T | undefined
  let failure: string | undefined

  await consumeSseStream(reader, {
    onStatus: (message) => {
      const progress: unknown = JSON.parse(message)
      if (
        progress &&
        typeof progress === 'object' &&
        'processed' in progress &&
        'total' in progress &&
        typeof progress.processed === 'number' &&
        typeof progress.total === 'number'
      ) {
        request.onProgress?.({ processed: progress.processed, total: progress.total })
      }
    },
    onDone: (_content, data) => {
      result = data as T
    },
    onError: (message) => {
      failure = message
    },
  })

  if (result === undefined) {
    throw new Error(failure ?? 'Import failed')
  }

  return result
}

export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

export function getImportExportExtension(format: ImportExportFormat): string {
  return format === 'excel' ? 'xlsx' : format === 'xliff' ? 'xlf' : format
}

export function buildTimestampedExportFilename(prefix: string, format: ImportExportFormat): string {
  const timestamp = new Date().toISOString().split('T')[0]

  return `${prefix}-${timestamp}.${getImportExportExtension(format)}`
}
