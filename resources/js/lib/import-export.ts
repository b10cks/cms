import type { ApiClient } from '~/api/client'
import { getXsrfHeaders } from '~/lib/csrf'
import type { ImportExportFormat } from '~/types/import-export'

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

export async function requestImportJson<T>({
  client,
  endpoint,
  file,
  extraFields,
}: {
  client: ApiClient
  endpoint: string
  file: File
  extraFields?: Record<string, string>
}): Promise<T> {
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

  const response = await fetch(`${client.getBaseUrl()}${endpoint}`, {
    method: 'POST',
    headers: {
      ...client.getAuthHeaders(),
      ...getXsrfHeaders(),
    },
    credentials: 'include',
    body: formData,
  })

  if (!response.ok) {
    throw new Error(await parseErrorMessage(response, 'Import failed'))
  }

  return response.json() as Promise<T>
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
