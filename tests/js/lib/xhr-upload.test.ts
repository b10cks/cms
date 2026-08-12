import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { UploadError, xhrUpload } from '~/lib/xhr-upload'

/** Minimal XMLHttpRequest double: the test drives load/error/abort by hand. */
class FakeXhr {
  static instances: FakeXhr[] = []

  public status = 200
  public statusText = 'OK'
  public responseText = '{}'
  public withCredentials = false
  public method = ''
  public url = ''
  public headers: Record<string, string> = {}
  public body: FormData | null = null
  public aborted = false

  private listeners = new Map<string, Array<() => void>>()
  private uploadListeners = new Map<string, Array<(event: ProgressEvent) => void>>()

  public upload = {
    addEventListener: (type: string, handler: (event: ProgressEvent) => void) => {
      const existing = this.uploadListeners.get(type) ?? []
      existing.push(handler)
      this.uploadListeners.set(type, existing)
    },
  }

  constructor() {
    FakeXhr.instances.push(this)
  }

  addEventListener(type: string, handler: () => void) {
    const existing = this.listeners.get(type) ?? []
    existing.push(handler)
    this.listeners.set(type, existing)
  }

  open(method: string, url: string) {
    this.method = method
    this.url = url
  }

  setRequestHeader(key: string, value: string) {
    this.headers[key] = value
  }

  send(body: FormData) {
    this.body = body
  }

  abort() {
    this.aborted = true
    this.fire('abort')
  }

  fire(type: 'load' | 'error' | 'abort') {
    for (const handler of this.listeners.get(type) ?? []) handler()
  }

  progress(loaded: number, total: number, lengthComputable = true) {
    for (const handler of this.uploadListeners.get('progress') ?? []) {
      handler({ loaded, total, lengthComputable } as ProgressEvent)
    }
  }
}

const formData = () => {
  const data = new FormData()
  data.append('file', new File(['binary'], 'photo.png', { type: 'image/png' }))

  return data
}

/** The request the helper opened, which it does synchronously. */
const lastXhr = () => FakeXhr.instances.at(-1) as FakeXhr

beforeEach(() => {
  FakeXhr.instances = []
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
  document.cookie = 'XSRF-TOKEN=csrf-token-value'
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('request shaping', () => {
  it('POSTs the form data to the given path with credentials and headers', async () => {
    const data = formData()
    const pending = xhrUpload('/upload', data)
    const xhr = lastXhr()

    expect(xhr.method).toBe('POST')
    expect(xhr.url).toBe('/upload')
    expect(xhr.withCredentials).toBe(true)
    expect(xhr.body).toBe(data)
    expect(xhr.headers).toEqual({
      accept: 'application/json',
      'X-XSRF-TOKEN': 'csrf-token-value',
    })

    xhr.fire('load')
    await pending
  })

  it('honours a custom method', async () => {
    const pending = xhrUpload('/upload', formData(), { method: 'PUT' })

    expect(lastXhr().method).toBe('PUT')

    lastXhr().fire('load')
    await pending
  })

  it('lets caller headers override the defaults', async () => {
    const pending = xhrUpload('/upload', formData(), {
      headers: { 'X-XSRF-TOKEN': 'caller-wins', 'X-Space': 'space-1' },
    })
    const xhr = lastXhr()

    expect(xhr.headers['X-XSRF-TOKEN']).toBe('caller-wins')
    expect(xhr.headers['X-Space']).toBe('space-1')

    xhr.fire('load')
    await pending
  })

  it('sends no XSRF header when the cookie is missing', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
    vi.spyOn(console, 'warn').mockImplementation(() => {})

    const pending = xhrUpload('/upload', formData())
    const xhr = lastXhr()

    expect(xhr.headers).toEqual({ accept: 'application/json' })

    xhr.fire('load')
    await pending
  })
})

describe('progress', () => {
  it('reports rounded whole percentages', async () => {
    const progress: number[] = []
    const pending = xhrUpload('/upload', formData(), { onProgress: (p) => progress.push(p) })
    const xhr = lastXhr()

    xhr.progress(0, 200)
    xhr.progress(50, 200)
    xhr.progress(1, 3)
    xhr.progress(200, 200)

    expect(progress).toEqual([0, 25, 33, 100])

    xhr.fire('load')
    await pending
  })

  it('stays silent for a non-computable length and tolerates no callback', async () => {
    const progress: number[] = []
    const pending = xhrUpload('/upload', formData(), { onProgress: (p) => progress.push(p) })

    lastXhr().progress(50, 0, false)
    expect(progress).toEqual([])

    lastXhr().fire('load')
    await pending

    const second = xhrUpload('/upload', formData())
    expect(() => lastXhr().progress(50, 100)).not.toThrow()

    lastXhr().fire('load')
    await second
  })
})

describe('completion', () => {
  it('resolves with the parsed JSON body', async () => {
    const pending = xhrUpload<{ data: { id: string } }>('/upload', formData())
    const xhr = lastXhr()

    xhr.responseText = '{"data":{"id":"a1"}}'
    xhr.fire('load')

    await expect(pending).resolves.toEqual({ data: { id: 'a1' } })
  })

  it('rejects an unparseable success body', async () => {
    const pending = xhrUpload('/upload', formData())
    const xhr = lastXhr()

    xhr.responseText = '<html>nope</html>'
    xhr.fire('load')

    await expect(pending).rejects.toThrow('Failed to parse server response')
  })
})

describe('failure', () => {
  const failWith = (
    status: number,
    statusText: string,
    responseText: string
  ): Promise<UploadError> => {
    const pending = xhrUpload<never>('/upload', formData())
    const xhr = lastXhr()

    xhr.status = status
    xhr.statusText = statusText
    xhr.responseText = responseText
    xhr.fire('load')

    return pending.catch((e: unknown) => e as UploadError)
  }

  it('surfaces the server message and validation errors', async () => {
    const reason = await failWith(
      422,
      'Unprocessable Entity',
      '{"message":"file too large","errors":{"file":["max 2MB"]}}'
    )

    expect(reason).toBeInstanceOf(UploadError)
    expect(reason.message).toBe('file too large')
    expect(reason.errors).toEqual({ file: ['max 2MB'] })
    expect(reason.status).toBe(422)
  })

  it('falls back to the first field error when the body has no message', async () => {
    const reason = await failWith(422, 'Unprocessable Entity', '{"errors":{"file":["max 2MB"]}}')

    expect(reason.message).toBe('max 2MB')
  })

  it('falls back to the status text for a non-JSON error body', async () => {
    const reason = await failWith(502, 'Bad Gateway', '<html>nginx</html>')

    expect(reason.message).toBe('Upload failed: Bad Gateway')
    expect(reason.status).toBe(502)
    expect(reason.errors).toBeNull()
  })

  it('falls back to the status code when there is no status text', async () => {
    const reason = await failWith(500, '', '{}')

    expect(reason.message).toBe('Upload failed: 500')
  })

  it('honours a caller-supplied fallback message', async () => {
    const pending = xhrUpload('/upload', formData(), {
      fallbackMessage: (status, statusText) => `Upload failed with status ${status}: ${statusText}`,
    })
    const xhr = lastXhr()

    xhr.status = 500
    xhr.statusText = 'Server Error'
    xhr.fire('load')

    await expect(pending).rejects.toThrow('Upload failed with status 500: Server Error')
  })

  it('keeps the parsed body for callers that act on a specific payload', async () => {
    const reason = await failWith(409, 'Conflict', '{"code":"duplicate_asset","message":"dupe"}')

    expect(reason.body).toEqual({ code: 'duplicate_asset', message: 'dupe' })
    expect(reason.status).toBe(409)
  })

  it('reports a network error', async () => {
    const pending = xhrUpload('/upload', formData())

    lastXhr().fire('error')

    await expect(pending).rejects.toThrow('Network error occurred during upload')
  })

  it('reports an abort event', async () => {
    const pending = xhrUpload('/upload', formData())

    lastXhr().fire('abort')

    await expect(pending).rejects.toThrow('Upload was aborted')
  })
})

describe('cancellation', () => {
  it('aborts the in-flight request when the signal fires', async () => {
    const controller = new AbortController()
    const pending = xhrUpload('/upload', formData(), { signal: controller.signal })

    controller.abort()

    await expect(pending).rejects.toThrow('Upload was aborted')
    expect(lastXhr().aborted).toBe(true)
  })

  it('never opens a request for an already-aborted signal', async () => {
    const controller = new AbortController()
    controller.abort()

    const pending = xhrUpload('/upload', formData(), { signal: controller.signal })

    await expect(pending).rejects.toThrow('Upload was aborted')
    expect(FakeXhr.instances).toHaveLength(0)
  })

  it('stops listening to the signal once the upload settled', async () => {
    const controller = new AbortController()
    const pending = xhrUpload('/upload', formData(), { signal: controller.signal })
    const xhr = lastXhr()

    xhr.fire('load')
    await pending

    controller.abort()

    expect(xhr.aborted).toBe(false)
  })
})
