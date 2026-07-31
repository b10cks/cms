import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiClient } from '~/api/client'

import {
  buildTimestampedExportFilename,
  downloadBlob,
  getImportExportExtension,
  requestExportBlob,
  requestImportJson,
} from '~/lib/import-export'

const ensureCsrfCookie = vi.fn(async () => {})

// Only the three members import-export actually calls; the real ApiClient
// wants a full runtime config to construct.
const client = () =>
  ({
    ensureCsrfCookie,
    getBaseUrl: () => 'https://api.b10cks.test',
    getAuthHeaders: () => ({ Authorization: 'Bearer token-1' }),
  }) as unknown as ApiClient

const jsonResponse = (body: unknown, init: ResponseInit = {}) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'content-type': 'application/json' },
    ...init,
  })

const fetchMock = vi.fn()

const lastRequest = () => {
  const [url, init] = fetchMock.mock.calls.at(-1) as [string, RequestInit]

  return { url, init, headers: init.headers as Record<string, string> }
}

beforeEach(() => {
  fetchMock.mockReset()
  ensureCsrfCookie.mockClear()
  vi.stubGlobal('fetch', fetchMock)
  // getXsrfHeaders reads this cookie directly — exercise the real helper
  // rather than mocking it, so a change to the header name shows up here.
  document.cookie = 'XSRF-TOKEN=token%2Fvalue'
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('requestExportBlob', () => {
  const exportBlob = () =>
    requestExportBlob({ client: client(), endpoint: '/exports', payload: { format: 'csv' } })

  it('posts the payload to the client base URL and returns the blob', async () => {
    fetchMock.mockResolvedValue(new Response('a,b\n1,2', { status: 200 }))

    expect(await (await exportBlob()).text()).toBe('a,b\n1,2')
    expect(lastRequest().url).toBe('https://api.b10cks.test/exports')
    expect(lastRequest().init.method).toBe('POST')
    expect(lastRequest().init.body).toBe('{"format":"csv"}')
  })

  it('sends credentials, auth, CSRF and JSON content-type headers', async () => {
    fetchMock.mockResolvedValue(new Response('x', { status: 200 }))

    await exportBlob()

    expect(lastRequest().init.credentials).toBe('include')
    expect(lastRequest().headers).toEqual({
      Authorization: 'Bearer token-1',
      'X-XSRF-TOKEN': 'token/value',
      'Content-Type': 'application/json',
    })
  })

  it('refreshes the CSRF cookie before firing the request', async () => {
    const order: string[] = []

    ensureCsrfCookie.mockImplementationOnce(async () => {
      order.push('csrf')
    })
    fetchMock.mockImplementation(() => {
      order.push('fetch')
      return Promise.resolve(new Response('x', { status: 200 }))
    })

    await exportBlob()

    expect(order).toEqual(['csrf', 'fetch'])
  })

  it('omits the CSRF header when there is no cookie', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
    fetchMock.mockResolvedValue(new Response('x', { status: 200 }))

    await exportBlob()

    expect(lastRequest().headers['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('throws the API message from a JSON error body', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ message: 'Too many rows' }, { status: 422, statusText: 'Unprocessable Entity' })
    )

    await expect(exportBlob()).rejects.toThrow('Too many rows')
  })

  it.each([
    ['a blank message', { message: '   ' }],
    ['a non-string message', { message: 42 }],
    ['no message key', { error: 'nope' }],
  ])('falls back to status text given %s', async (_label, body) => {
    fetchMock.mockResolvedValue(jsonResponse(body, { status: 500, statusText: 'Server Error' }))

    await expect(exportBlob()).rejects.toThrow('Export failed with status 500: Server Error')
  })

  it('falls back to status text for a non-JSON error body', async () => {
    fetchMock.mockResolvedValue(
      new Response('<html>nope</html>', {
        status: 502,
        statusText: 'Bad Gateway',
        headers: { 'content-type': 'text/html' },
      })
    )

    await expect(exportBlob()).rejects.toThrow('Export failed with status 502: Bad Gateway')
  })

  it('falls back to status text when the JSON body is malformed', async () => {
    fetchMock.mockResolvedValue(
      new Response('{not json', {
        status: 500,
        statusText: 'Server Error',
        headers: { 'content-type': 'application/json' },
      })
    )

    await expect(exportBlob()).rejects.toThrow('Export failed with status 500: Server Error')
  })

  it('propagates a network failure untouched', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(exportBlob()).rejects.toThrow('Failed to fetch')
  })
})

describe('requestImportJson', () => {
  const file = new File(['id,name\n1,A'], 'rows.csv', { type: 'text/csv' })

  const importJson = (extraFields?: Record<string, string>) =>
    requestImportJson<{ imported: number }>({
      client: client(),
      endpoint: '/imports',
      file,
      extraFields,
    })

  it('posts the file as multipart form data and returns the parsed JSON', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ imported: 2 }))

    expect(await importJson()).toEqual({ imported: 2 })

    const body = lastRequest().init.body as FormData

    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file')).toBe(file)
  })

  it('appends extra fields alongside the file', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ imported: 0 }))

    await importJson({ mode: 'merge', language: 'de' })

    const body = lastRequest().init.body as FormData

    expect(body.get('mode')).toBe('merge')
    expect(body.get('language')).toBe('de')
  })

  it('never sets Content-Type — the browser has to add the multipart boundary', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ imported: 0 }))

    await importJson()

    expect(lastRequest().headers).toEqual({
      Authorization: 'Bearer token-1',
      'X-XSRF-TOKEN': 'token/value',
    })
  })

  it('refreshes the CSRF cookie before firing the request', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ imported: 0 }))

    await importJson()

    expect(ensureCsrfCookie).toHaveBeenCalledTimes(1)
  })

  it('throws the API message from a JSON error body', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ message: 'Unsupported column' }, { status: 422, statusText: 'Unprocessable' })
    )

    await expect(importJson()).rejects.toThrow('Unsupported column')
  })

  it('falls back to its own label in the status message', async () => {
    fetchMock.mockResolvedValue(new Response('nope', { status: 500, statusText: 'Server Error' }))

    await expect(importJson()).rejects.toThrow('Import failed with status 500: Server Error')
  })
})

describe('getImportExportExtension', () => {
  it.each([
    ['excel', 'xlsx'],
    ['xliff', 'xlf'],
    ['csv', 'csv'],
    ['json', 'json'],
  ] as const)('maps %s to .%s', (format, expected) => {
    expect(getImportExportExtension(format)).toBe(expected)
  })
})

describe('buildTimestampedExportFilename', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T22:30:00.000Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('joins the prefix, the UTC date and the format extension', () => {
    expect(buildTimestampedExportFilename('contents', 'csv')).toBe('contents-2026-07-29.csv')
    expect(buildTimestampedExportFilename('contents', 'excel')).toBe('contents-2026-07-29.xlsx')
  })

  it('stamps the UTC date, not the local one', () => {
    // 22:30 UTC is already the 30th in UTC+2, where this project is developed.
    vi.setSystemTime(new Date('2026-07-29T23:30:00.000Z'))

    expect(buildTimestampedExportFilename('contents', 'csv')).toBe('contents-2026-07-29.csv')
  })
})

describe('downloadBlob', () => {
  const createObjectURL = vi.fn(() => 'blob:fake-url')
  const revokeObjectURL = vi.fn()

  beforeEach(() => {
    createObjectURL.mockClear()
    revokeObjectURL.mockClear()
    // jsdom implements neither.
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
  })

  it('clicks a download link carrying the object URL and filename', () => {
    const blob = new Blob(['x'])
    const clicked: Array<{ href: string; download: string }> = []

    // The link is removed again before downloadBlob returns, so its state has
    // to be captured at click time.
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        clicked.push({ href: this.href, download: this.download })
      })

    downloadBlob(blob, 'contents.csv')

    expect(createObjectURL).toHaveBeenCalledWith(blob)
    expect(clicked).toEqual([{ href: 'blob:fake-url', download: 'contents.csv' }])

    click.mockRestore()
  })

  it('is attached to the document while clicking, and detached afterwards', () => {
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        expect(this.isConnected).toBe(true)
      })

    downloadBlob(new Blob(['x']), 'contents.csv')

    expect(document.querySelector('a')).toBeNull()
    expect(click).toHaveBeenCalledTimes(1)

    click.mockRestore()
  })

  it('revokes the object URL so the blob can be collected', () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    downloadBlob(new Blob(['x']), 'contents.csv')

    expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake-url')

    click.mockRestore()
  })
})
