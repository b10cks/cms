import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { withSetup, type Harness } from '../support/harness'

const ensureCsrfCookie = vi.fn(async () => {})

vi.mock('~/api', () => ({ api: { client: { ensureCsrfCookie } } }))

const { useFileUpload } = await import('~/composables/useFileUpload')

type UploadError = InstanceType<
  typeof import('~/composables/useFileUpload')['UploadError']
>

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

const file = (name = 'photo.png') => new File(['binary'], name, { type: 'image/png' })

const mounted: Array<() => void> = []

const mount = (): Harness<ReturnType<typeof useFileUpload>> => {
  const harness = withSetup(() => useFileUpload())
  mounted.push(harness.unmount)
  return harness
}

/** Waits for the XHR the composable creates after its awaited CSRF priming. */
const nextXhr = async () => {
  await Promise.resolve()
  await Promise.resolve()
  return FakeXhr.instances.at(-1) as FakeXhr
}

beforeEach(() => {
  ensureCsrfCookie.mockClear()
  FakeXhr.instances = []
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
  document.cookie = 'XSRF-TOKEN=csrf-token-value'
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('request shaping', () => {
  it('primes the CSRF cookie before opening the request', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()

    expect(ensureCsrfCookie).toHaveBeenCalledTimes(1)
    expect(xhr.method).toBe('POST')
    expect(xhr.url).toBe('/upload')

    xhr.fire('load')
    await pending
  })

  it('sends the file under the "file" field by default', async () => {
    const upload = mount().result
    const target = file()

    const pending = upload.upload(target, { url: '/upload' })
    const xhr = await nextXhr()

    expect(xhr.body?.get('file')).toBe(target)

    xhr.fire('load')
    await pending
  })

  it('honours a custom field name', async () => {
    const upload = mount().result
    const target = file()

    const pending = upload.upload(target, { url: '/upload', fieldName: 'attachment' })
    const xhr = await nextXhr()

    expect(xhr.body?.get('attachment')).toBe(target)
    expect(xhr.body?.get('file')).toBeNull()

    xhr.fire('load')
    await pending
  })

  it('sends cookies and both the accept and the XSRF header', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()

    expect(xhr.withCredentials).toBe(true)
    expect(xhr.headers).toEqual({
      accept: 'application/json',
      'X-XSRF-TOKEN': 'csrf-token-value',
    })

    xhr.fire('load')
    await pending
  })

  it('adds caller headers on top', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), {
      url: '/upload',
      headers: { 'X-Space': 'space-1' },
    })
    const xhr = await nextXhr()

    expect(xhr.headers['X-Space']).toBe('space-1')

    xhr.fire('load')
    await pending
  })

  it('lets a caller header override the XSRF one', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), {
      url: '/upload',
      headers: { 'X-XSRF-TOKEN': 'caller-wins' },
    })
    const xhr = await nextXhr()

    // Caller headers are applied last, so they win.
    expect(xhr.headers['X-XSRF-TOKEN']).toBe('caller-wins')

    xhr.fire('load')
    await pending
  })

  it('sends no XSRF header when the cookie is missing', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()

    expect(xhr.headers).toEqual({ accept: 'application/json' })

    xhr.fire('load')
    await pending
  })
})

describe('progress', () => {
  it('reports whole percentages', async () => {
    const upload = mount().result
    const progress: number[] = []

    const pending = upload.upload(file(), { url: '/upload', onProgress: (p) => progress.push(p) })
    const xhr = await nextXhr()

    xhr.progress(0, 200)
    xhr.progress(50, 200)
    xhr.progress(200, 200)

    expect(progress).toEqual([0, 25, 100])

    xhr.fire('load')
    await pending
  })

  it('rounds rather than truncates', async () => {
    const upload = mount().result
    const progress: number[] = []

    const pending = upload.upload(file(), { url: '/upload', onProgress: (p) => progress.push(p) })
    const xhr = await nextXhr()

    xhr.progress(1, 3)

    expect(progress).toEqual([33])

    xhr.fire('load')
    await pending
  })

  it('stays silent for a non-computable length', async () => {
    const upload = mount().result
    const progress: number[] = []

    const pending = upload.upload(file(), { url: '/upload', onProgress: (p) => progress.push(p) })
    const xhr = await nextXhr()

    xhr.progress(50, 0, false)

    expect(progress).toEqual([])

    xhr.fire('load')
    await pending
  })

  it('tolerates no callback at all', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()

    expect(() => xhr.progress(50, 100)).not.toThrow()

    xhr.fire('load')
    await pending
  })
})

describe('completion', () => {
  it('resolves with the parsed JSON body', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.responseText = '{"data":{"id":"a1"}}'
    xhr.fire('load')

    await expect(pending).resolves.toEqual({ data: { id: 'a1' } })
    expect(upload.error.value).toBeNull()
  })

  it('flips isUploading while the request is in flight', async () => {
    const harness = mount()
    const upload = harness.result

    expect(upload.isUploading.value).toBe(false)

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()

    expect(upload.isUploading.value).toBe(true)

    xhr.fire('load')
    await pending

    expect(upload.isUploading.value).toBe(false)
  })

  it('keeps the last failure visible until an upload succeeds', async () => {
    const upload = mount().result

    const failing = upload.upload(file(), { url: '/upload' })
    const first = await nextXhr()
    first.status = 500
    first.statusText = 'Server Error'
    first.fire('load')
    await failing.catch(() => {})

    expect(upload.error.value).toBe('Upload failed: Server Error')

    const pending = upload.upload(file(), { url: '/upload' })
    const second = await nextXhr()

    // Merely starting another upload no longer erases the earlier failure.
    expect(upload.error.value).toBe('Upload failed: Server Error')

    second.fire('load')
    await pending

    expect(upload.error.value).toBeNull()
  })

  it.each([[200], [201], [204]])('treats %i as success', async (status) => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.status = status
    xhr.responseText = '{}'
    xhr.fire('load')

    await expect(pending).resolves.toEqual({})
  })
})

describe('failure', () => {
  it('rejects with a real Error, so a caller can read e.message', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.status = 422
    xhr.statusText = 'Unprocessable Entity'
    xhr.fire('load')

    const reason = await pending.catch((e: unknown) => e)

    expect(reason).toBeInstanceOf(Error)
    expect((reason as UploadError).message).toBe('Upload failed: Unprocessable Entity')
    expect((reason as UploadError).status).toBe(422)
    expect(upload.error.value).toBe('Upload failed: Unprocessable Entity')
    expect(upload.isUploading.value).toBe(false)
  })

  it('surfaces the server validation body instead of the status text', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.status = 422
    xhr.statusText = 'Unprocessable Entity'
    xhr.responseText = '{"message":"file too large","errors":{"file":["max 2MB"]}}'
    xhr.fire('load')

    const reason = (await pending.catch((e: unknown) => e)) as UploadError

    expect(reason.message).toBe('file too large')
    expect(reason.errors).toEqual({ file: ['max 2MB'] })
    expect(reason.status).toBe(422)
  })

  it('falls back to the first field error when the body has no message', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.status = 422
    xhr.responseText = '{"errors":{"file":["max 2MB"]}}'
    xhr.fire('load')

    await expect(pending).rejects.toThrow('max 2MB')
  })

  it('falls back to the status text for a non-JSON error body', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.status = 502
    xhr.statusText = 'Bad Gateway'
    xhr.responseText = '<html>nginx</html>'
    xhr.fire('load')

    const reason = (await pending.catch((e: unknown) => e)) as UploadError

    expect(reason.message).toBe('Upload failed: Bad Gateway')
    expect(reason.status).toBe(502)
    expect(reason.errors).toBeNull()
  })

  it('reports unparseable success bodies', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.responseText = '<html>nope</html>'
    xhr.fire('load')

    await expect(pending).rejects.toThrow('Failed to parse server response')
    expect(upload.error.value).toBe('Failed to parse server response')
    expect(upload.isUploading.value).toBe(false)
  })

  it('reports a network error', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.fire('error')

    await expect(pending).rejects.toThrow('Network error occurred during upload')
    expect(upload.error.value).toBe('Network error occurred during upload')
    expect(upload.isUploading.value).toBe(false)
  })

  it('reports an abort', async () => {
    const upload = mount().result

    const pending = upload.upload(file(), { url: '/upload' })
    const xhr = await nextXhr()
    xhr.fire('abort')

    await expect(pending).rejects.toThrow('Upload was aborted')
    expect(upload.error.value).toBe('Upload was aborted')
    expect(upload.isUploading.value).toBe(false)
  })
})

describe('cancellation', () => {
  it('hands back a task whose abort() cancels the request', async () => {
    const upload = mount().result

    const task = upload.startUpload(file(), { url: '/upload' })
    const xhr = await nextXhr()

    task.abort()

    await expect(task.promise).rejects.toThrow('Upload was aborted')
    expect(xhr.aborted).toBe(true)
    expect(task.isUploading.value).toBe(false)
  })

  it('cancels an upload aborted before its CSRF priming resolved', async () => {
    const upload = mount().result

    const task = upload.startUpload(file(), { url: '/upload' })
    task.abort()

    await expect(task.promise).rejects.toThrow('Upload was aborted')
    // The request never went out at all.
    expect(FakeXhr.instances).toHaveLength(0)
  })
})

describe('several files at once', () => {
  it('uploads each file independently, so one failure spares the others', async () => {
    const upload = mount().result

    const first = upload.upload(file('a.png'), { url: '/upload' })
    const firstXhr = await nextXhr()
    const second = upload.upload(file('b.png'), { url: '/upload' })
    const secondXhr = await nextXhr()

    firstXhr.status = 500
    firstXhr.statusText = 'Server Error'
    firstXhr.fire('load')
    secondXhr.responseText = '{"data":{"id":"b"}}'
    secondXhr.fire('load')

    const results = await Promise.allSettled([first, second])

    expect(results[0].status).toBe('rejected')
    expect(results[1]).toEqual({ status: 'fulfilled', value: { data: { id: 'b' } } })
  })

  it('stays busy until the last file is done', async () => {
    const upload = mount().result

    const first = upload.upload(file('a.png'), { url: '/upload' })
    const firstXhr = await nextXhr()
    const second = upload.upload(file('b.png'), { url: '/upload' })
    const secondXhr = await nextXhr()

    firstXhr.fire('load')
    await first

    // b.png is still in flight, so the composable must not look idle yet.
    expect(upload.isUploading.value).toBe(true)

    secondXhr.fire('load')
    await second

    expect(upload.isUploading.value).toBe(false)
  })

  it('tracks progress and failure per file, not per composable', async () => {
    const upload = mount().result

    const first = upload.startUpload(file('a.png'), { url: '/upload' })
    const firstXhr = await nextXhr()
    const second = upload.startUpload(file('b.png'), { url: '/upload' })
    const secondXhr = await nextXhr()

    expect(upload.uploads.value.map((task) => task.file.name)).toEqual(['a.png', 'b.png'])

    secondXhr.progress(1, 4)
    firstXhr.status = 500
    firstXhr.statusText = 'Server Error'
    firstXhr.fire('load')
    await first.promise.catch(() => {})

    expect(first.error.value?.message).toBe('Upload failed: Server Error')
    expect(second.error.value).toBeNull()
    expect(second.progress.value).toBe(25)
    expect(second.isUploading.value).toBe(true)
    // Only the settled upload leaves the in-flight list.
    expect(upload.uploads.value.map((task) => task.file.name)).toEqual(['b.png'])

    secondXhr.fire('load')
    await second.promise
  })

  it('primes the CSRF cookie once per call', async () => {
    const upload = mount().result

    const first = upload.upload(file('a.png'), { url: '/upload' })
    const firstXhr = await nextXhr()
    const second = upload.upload(file('b.png'), { url: '/upload' })
    const secondXhr = await nextXhr()

    expect(ensureCsrfCookie).toHaveBeenCalledTimes(2)

    firstXhr.fire('load')
    secondXhr.fire('load')
    await Promise.all([first, second])
  })

  it('keeps each instance of the composable separate', async () => {
    const a = mount().result
    const b = mount().result

    const pending = a.upload(file(), { url: '/upload' })
    await nextXhr()

    expect(a.isUploading.value).toBe(true)
    expect(b.isUploading.value).toBe(false)

    ;(await nextXhr()).fire('load')
    await pending
  })
})
