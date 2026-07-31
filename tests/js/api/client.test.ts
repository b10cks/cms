import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiClient } from '~/api/client'

const CSRF_ENDPOINT = '/auth/v1/csrf-cookie'
const BASE_URL = 'https://api.b10cks.test'

const json = (body: unknown, init: ResponseInit = {}) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'content-type': 'application/json' },
    ...init,
  })

const fetchMock = vi.fn()

let queue: Array<() => Response>
let csrfStatus: number
let csrfFails: boolean

const enqueue = (...responses: Array<() => Response>) => queue.push(...responses)

const isCsrf = (url: unknown) => String(url).endsWith(CSRF_ENDPOINT)

/** Every fetch that is not the CSRF cookie priming call. */
const apiCalls = () =>
  fetchMock.mock.calls.filter(([url]) => !isCsrf(url)) as Array<[string, RequestInit]>

const csrfCalls = () => fetchMock.mock.calls.filter(([url]) => isCsrf(url))

const lastCall = () => {
  const [url, init] = apiCalls().at(-1) as [string, RequestInit]

  return { url, init, headers: init.headers as Record<string, string> }
}

const client = (options: { baseURL?: string; authToken?: string } = {}) =>
  new ApiClient({ baseURL: BASE_URL, ...options })

type ApiError = Error & { status: number; data: Record<string, unknown>; response: Response }

/** The rejection value of a request, typed as the error shape the client builds. */
const rejection = async (promise: Promise<unknown>): Promise<ApiError> => {
  const error = await promise.then(
    () => null,
    (reason: unknown) => reason as ApiError
  )

  if (!error) throw new Error('expected the request to reject')

  return error
}

beforeEach(() => {
  queue = []
  csrfStatus = 204
  csrfFails = false
  fetchMock.mockReset()
  fetchMock.mockImplementation(async (url: unknown) => {
    if (isCsrf(url)) {
      if (csrfFails) throw new TypeError('Failed to fetch')
      return new Response(null, { status: csrfStatus })
    }
    const next = queue.shift()
    return next ? next() : json({ data: 'ok' })
  })
  vi.stubGlobal('fetch', fetchMock)
  // getXsrfHeaders reads the cookie directly; run the real helper so a change
  // to the cookie or header name surfaces here.
  document.cookie = 'XSRF-TOKEN=token%2Fvalue'
  // The csrf helper and ensureCsrfCookie both warn on the unhappy paths.
  vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('URL building', () => {
  it('prefixes a relative endpoint with the base URL', async () => {
    await client().get('/mgmt/v1/spaces')

    expect(lastCall().url).toBe(`${BASE_URL}/mgmt/v1/spaces`)
  })

  it('leaves an absolute URL untouched', async () => {
    await client().get('https://other.test/thing')

    expect(lastCall().url).toBe('https://other.test/thing')
  })

  it('joins by plain concatenation — no slash is inserted or de-duplicated', async () => {
    await client({ baseURL: 'https://api.b10cks.test/' }).get('/v1')

    expect(lastCall().url).toBe('https://api.b10cks.test//v1')
  })

  it('produces a relative URL when no base URL was configured', async () => {
    await new ApiClient().get('/mgmt/v1/spaces')

    expect(lastCall().url).toBe('/mgmt/v1/spaces')
  })
})

describe('query serialization', () => {
  const url = async (query: Record<string, unknown>) => {
    await client().get('/things', query)

    return lastCall().url
  }

  it('appends no question mark for an empty query object', async () => {
    expect(await url({})).toBe(`${BASE_URL}/things`)
  })

  it('serializes strings and numbers', async () => {
    expect(await url({ q: 'hello world', page: 2 })).toBe(`${BASE_URL}/things?q=hello+world&page=2`)
  })

  it('omits undefined and null instead of sending the literal "undefined"', async () => {
    expect(await url({ q: 'a', folder: undefined, parent: null })).toBe(`${BASE_URL}/things?q=a`)
  })

  it('keeps falsy-but-valid values', async () => {
    expect(await url({ page: 0, q: '', archived: false })).toBe(
      `${BASE_URL}/things?page=0&q=&archived=false`
    )
  })

  it('serializes booleans as the strings "true" and "false"', async () => {
    expect(await url({ published: true })).toBe(`${BASE_URL}/things?published=true`)
  })

  it('repeats the key for an array, which is what Laravel parses into a list', async () => {
    // AssetFilter::tags() only takes the array branch for a repeated key; a
    // comma-joined string would be matched as one literal tag.
    expect(await url({ tags: ['a', 'b'] })).toBe(`${BASE_URL}/things?tags%5B%5D=a&tags%5B%5D=b`)
  })

  it('sends nothing at all for an empty array', async () => {
    expect(await url({ tags: [] })).toBe(`${BASE_URL}/things`)
  })

  it('serializes a nested object in bracket notation', async () => {
    // Laravel parses filter[parent_id] into a nested array. (The content
    // filters themselves read top-level keys, so useContentChildrenQuery sends
    // `{ parent_id }` flat — this covers the endpoints that do nest.)
    expect(await url({ filter: { parent_id: 'c1' } })).toBe(
      `${BASE_URL}/things?filter%5Bparent_id%5D=c1`
    )
  })

  it('recurses through deeper nesting and arrays inside objects', async () => {
    expect(await url({ filter: { tags: ['a', 'b'], range: { from: 1 } } })).toBe(
      `${BASE_URL}/things?filter%5Btags%5D%5B%5D=a&filter%5Btags%5D%5B%5D=b&filter%5Brange%5D%5Bfrom%5D=1`
    )
  })

  it('drops null and undefined nested values too', async () => {
    expect(await url({ filter: { parent_id: null, q: 'a' } })).toBe(
      `${BASE_URL}/things?filter%5Bq%5D=a`
    )
  })

  it('serializes a Date as ISO rather than exploding it into brackets', async () => {
    expect(await url({ since: new Date('2026-01-02T03:04:05.000Z') })).toBe(
      `${BASE_URL}/things?since=2026-01-02T03%3A04%3A05.000Z`
    )
  })

  it('appends no question mark when every value was dropped', async () => {
    expect(await url({ folder: undefined })).toBe(`${BASE_URL}/things`)
  })

  it('keeps the last value for a duplicated key', async () => {
    // `params.set`, not `append` — irrelevant for plain objects but it means a
    // Map-like or repeated key can never round-trip.
    expect(await url({ sort: '-name' })).toBe(`${BASE_URL}/things?sort=-name`)
  })
})

describe('headers and credentials', () => {
  it('sends Accept and Content-Type JSON with credentials on a GET', async () => {
    await client().get('/things')

    expect(lastCall().init.credentials).toBe('include')
    expect(lastCall().headers).toEqual({
      Accept: 'application/json',
      'Content-Type': 'application/json',
    })
  })

  it('omits the CSRF header on safe methods', async () => {
    const c = client()

    await c.get('/things')
    expect(lastCall().headers['X-XSRF-TOKEN']).toBeUndefined()

    await c.request('/things', { method: 'HEAD' })
    expect(lastCall().headers['X-XSRF-TOKEN']).toBeUndefined()

    await c.request('/things', { method: 'OPTIONS' })
    expect(lastCall().headers['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('sends the decoded XSRF cookie value on unsafe methods', async () => {
    const c = client()

    await c.post('/things', { a: 1 })
    expect(lastCall().headers['X-XSRF-TOKEN']).toBe('token/value')

    await c.put('/things/1', { a: 1 })
    expect(lastCall().headers['X-XSRF-TOKEN']).toBe('token/value')

    await c.patch('/things/1', { a: 1 })
    expect(lastCall().headers['X-XSRF-TOKEN']).toBe('token/value')

    await c.delete('/things/1')
    expect(lastCall().headers['X-XSRF-TOKEN']).toBe('token/value')
  })

  it('omits the CSRF header when the cookie is missing', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'

    await client().post('/things', { a: 1 })

    expect(lastCall().headers['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('adds a bearer token only once one is set', async () => {
    const c = client()

    await c.get('/things')
    expect(lastCall().headers.Authorization).toBeUndefined()
    expect(c.getAuthHeaders()).toEqual({})

    c.setAuthToken('token-1')
    expect(c.getAuthHeaders()).toEqual({ Authorization: 'Bearer token-1' })

    await c.get('/things')
    expect(lastCall().headers.Authorization).toBe('Bearer token-1')

    c.setAuthToken(undefined)
    await c.get('/things')
    expect(lastCall().headers.Authorization).toBeUndefined()
  })

  it('lets a caller-supplied Authorization header win over the stored token', async () => {
    const c = client({ authToken: 'token-1' })

    await c.request('/things', { method: 'GET', headers: { Authorization: 'Bearer explicit' } })

    expect(lastCall().headers.Authorization).toBe('Bearer explicit')
  })

  it('lets caller headers override the defaults', async () => {
    await client().request('/things', {
      method: 'POST',
      body: { a: 1 },
      headers: { 'Content-Type': 'text/plain', 'X-XSRF-TOKEN': 'explicit' },
    })

    expect(lastCall().headers['Content-Type']).toBe('text/plain')
    expect(lastCall().headers['X-XSRF-TOKEN']).toBe('explicit')
  })

  it('honours an explicit credentials mode', async () => {
    await client().get('/things', {}, { credentials: 'omit' })

    expect(lastCall().init.credentials).toBe('omit')
  })

  it('exposes the configured base URL', () => {
    expect(client().getBaseUrl()).toBe(BASE_URL)
    expect(new ApiClient().getBaseUrl()).toBe('')
  })
})

describe('request body', () => {
  it('JSON-encodes an object body', async () => {
    await client().post('/things', { name: 'A', tags: ['x'] })

    expect(lastCall().init.method).toBe('POST')
    expect(lastCall().init.body).toBe('{"name":"A","tags":["x"]}')
  })

  it('sends no body when none was given', async () => {
    await client().post('/things')

    expect(lastCall().init.body).toBeUndefined()
  })

  it('sends the string "null" for an explicit null body', async () => {
    // ODDITY: only `undefined` is treated as "no body"; null is encoded.
    await client().post('/things', null)

    expect(lastCall().init.body).toBe('null')
  })

  it('passes FormData through and drops the JSON Content-Type', async () => {
    const form = new FormData()
    form.append('file', new File(['x'], 'a.txt'))

    await client().post('/things', form)

    expect(lastCall().init.body).toBe(form)
    expect(lastCall().headers).toEqual({
      Accept: 'application/json',
      'X-XSRF-TOKEN': 'token/value',
    })
  })

  it('uppercases a lowercase method', async () => {
    await client().request('/things', { method: 'post', body: { a: 1 } })

    // The method itself is forwarded verbatim; only the CSRF/retry decision is
    // made on the uppercased copy.
    expect(lastCall().init.method).toBe('post')
    expect(lastCall().headers['X-XSRF-TOKEN']).toBe('token/value')
  })

  it('treats a query passed through options like one passed positionally', async () => {
    await client().request('/things', { method: 'DELETE', query: { force: 1 } })

    expect(lastCall().url).toBe(`${BASE_URL}/things?force=1`)
  })
})

describe('response parsing', () => {
  it('parses a JSON body', async () => {
    enqueue(() => json({ data: { id: 'c1' } }))

    expect(await client().get('/things/c1')).toEqual({ data: { id: 'c1' } })
  })

  it('parses JSON with a charset parameter', async () => {
    enqueue(
      () =>
        new Response('{"data":1}', {
          status: 200,
          headers: { 'content-type': 'application/json; charset=utf-8' },
        })
    )

    expect(await client().get('/things')).toEqual({ data: 1 })
  })

  it('returns an empty string for a 204, not undefined', async () => {
    // ODDITY: `delete()` is typed Promise<void> but resolves with ''.
    enqueue(() => new Response(null, { status: 204 }))

    expect(await client().delete('/things/1')).toBe('')
  })

  it('returns the raw text for a non-JSON body', async () => {
    enqueue(() => new Response('plain', { status: 200, headers: { 'content-type': 'text/plain' } }))

    expect(await client().get('/things')).toBe('plain')
  })

  it('returns text for a JSON body served without a content-type', async () => {
    // ODDITY: the decision is content-type driven, so a mislabelled JSON payload
    // arrives as an unparsed string.
    enqueue(() => new Response('{"data":1}', { status: 200 }))

    expect(await client().get('/things')).toBe('{"data":1}')
  })

  it('rejects when a 200 JSON body is malformed', async () => {
    enqueue(
      () =>
        new Response('{not json', {
          status: 200,
          headers: { 'content-type': 'application/json' },
        })
    )

    await expect(client().get('/things')).rejects.toThrow(/JSON/i)
  })
})

describe('error handling', () => {
  const failing = (status: number, body: unknown, statusText = 'Error') => {
    enqueue(() => json(body, { status, statusText }))

    return rejection(client().get('/things'))
  }

  it('throws an Error carrying status, parsed data and the response', async () => {
    const error = await failing(409, { message: 'Asset is in use', code: 'asset_in_use' })

    expect(error.message).toBe('Asset is in use')
    expect(error.status).toBe(409)
    // Other code branches on exactly this shape.
    expect(error.data.code).toBe('asset_in_use')
    expect(error.response).toBeInstanceOf(Response)
    expect(error.response.status).toBe(409)
  })

  it('preserves the whole validation payload on a 422', async () => {
    const error = await failing(422, {
      message: 'The given data was invalid.',
      errors: { slug: ['The slug has already been taken.'] },
    })

    expect((error.data as { errors: Record<string, string[]> }).errors.slug).toEqual([
      'The slug has already been taken.',
    ])
  })

  it('falls back to the status text when the JSON body has no message', async () => {
    const error = await failing(500, { code: 'oops' }, 'Server Error')

    expect(error.message).toBe('Server Error')
  })

  it('surfaces a non-JSON error body as the message', async () => {
    enqueue(
      () =>
        new Response('<html>gateway</html>', {
          status: 502,
          statusText: 'Bad Gateway',
          headers: { 'content-type': 'text/html' },
        })
    )

    const error = await rejection(client().get('/things'))

    expect(error.message).toBe('<html>gateway</html>')
    expect(error.data).toEqual({ message: '<html>gateway</html>' })
    expect(error.status).toBe(502)
  })

  it('truncates a long non-JSON error body', async () => {
    enqueue(
      () =>
        new Response('x'.repeat(2000), {
          status: 502,
          headers: { 'content-type': 'text/html' },
        })
    )

    const error = await rejection(client().get('/things'))

    expect(error.message).toHaveLength(500)
  })

  it('falls back to the status text when the non-JSON body is blank', async () => {
    enqueue(
      () =>
        new Response('   ', {
          status: 502,
          statusText: 'Bad Gateway',
          headers: { 'content-type': 'text/html' },
        })
    )

    expect((await rejection(client().get('/things'))).message).toBe('Bad Gateway')
  })

  it('parses an application/problem+json error body', async () => {
    enqueue(
      () =>
        new Response(JSON.stringify({ message: 'Quota exceeded', code: 'quota' }), {
          status: 429,
          headers: { 'content-type': 'application/problem+json' },
        })
    )

    const error = await rejection(client().get('/things'))

    expect(error.message).toBe('Quota exceeded')
    expect(error.data.code).toBe('quota')
  })

  it('still reports status when the JSON error body is malformed', async () => {
    enqueue(
      () =>
        new Response('{not json', {
          status: 500,
          statusText: 'Server Error',
          headers: { 'content-type': 'application/json' },
        })
    )

    const error = await rejection(client().get('/things'))

    expect(error.status).toBe(500)
    expect(error.data).toEqual({})
    expect(error.message).toBe('Server Error')
  })

  it('falls back to "HTTP <status>" when neither a message nor a status text exists', async () => {
    // fetch omits statusText over HTTP/2, and `toast.error(error.message)` must
    // never render an empty toast.
    enqueue(() => json({}, { status: 500 }))

    const error = await rejection(client().get('/things'))

    expect(error.message).toBe('HTTP 500')
    expect(error.status).toBe(500)
  })

  it('propagates a network failure untouched', async () => {
    enqueue(() => {
      throw new TypeError('Failed to fetch')
    })

    await expect(client().get('/things')).rejects.toThrow('Failed to fetch')
  })
})

describe('ensureCsrfCookie', () => {
  it('primes the cookie once before the first unsafe request', async () => {
    const c = client()

    await c.post('/things', { a: 1 })
    await c.post('/things', { a: 2 })
    await c.delete('/things/1')

    expect(csrfCalls()).toHaveLength(1)
  })

  it('requests the cookie endpoint with credentials and no CSRF header', async () => {
    await client().post('/things', { a: 1 })

    const [url, init] = csrfCalls()[0] as [string, RequestInit]

    // Primed against the configured host — a cross-origin client must not set
    // the cookie on the app's own origin.
    expect(url).toBe(`${BASE_URL}${CSRF_ENDPOINT}`)
    expect(init.method).toBe('GET')
    expect(init.credentials).toBe('include')
    expect(init.headers).toEqual({ Accept: 'application/json' })
  })

  it('never primes for safe methods', async () => {
    const c = client()

    await c.get('/things')
    await c.request('/things', { method: 'HEAD' })

    expect(csrfCalls()).toHaveLength(0)
  })

  it('primes before firing the request', async () => {
    const order: string[] = []

    fetchMock.mockImplementation(async (url: unknown) => {
      order.push(isCsrf(url) ? 'csrf' : 'request')
      return isCsrf(url) ? new Response(null, { status: 204 }) : json({ data: 'ok' })
    })

    await client().post('/things', { a: 1 })

    expect(order).toEqual(['csrf', 'request'])
  })

  it('re-primes when forced', async () => {
    const c = client()

    await c.ensureCsrfCookie()
    await c.ensureCsrfCookie()
    expect(csrfCalls()).toHaveLength(1)

    await c.ensureCsrfCookie(true)
    expect(csrfCalls()).toHaveLength(2)
  })

  it('stays unprimed when the cookie endpoint fails, so the next request retries it', async () => {
    csrfStatus = 500
    const c = client()

    await c.post('/things', { a: 1 })
    await c.post('/things', { a: 2 })

    expect(csrfCalls()).toHaveLength(2)
    // The request itself is still sent — a failed priming call is only warned about.
    expect(apiCalls()).toHaveLength(2)
  })

  it('stays unprimed when the endpoint answers but no cookie arrives', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
    const c = client()

    await c.ensureCsrfCookie()
    await c.ensureCsrfCookie()

    expect(csrfCalls()).toHaveLength(2)
  })

  it('swallows a network error from the cookie endpoint', async () => {
    csrfFails = true

    await expect(client().post('/things', { a: 1 })).resolves.toEqual({ data: 'ok' })
  })
})

describe('419 CSRF retry', () => {
  const expired = () => json({ message: 'CSRF token mismatch.' }, { status: 419 })

  it('refreshes the cookie and retries exactly once', async () => {
    enqueue(expired, () => json({ data: 'ok' }))

    expect(await client().post('/things', { a: 1 })).toEqual({ data: 'ok' })
    expect(apiCalls()).toHaveLength(2)
    // Primed once up front, then force-refreshed for the retry.
    expect(csrfCalls()).toHaveLength(2)
  })

  it('re-reads the cookie for the retry rather than reusing the stale header', async () => {
    enqueue(() => {
      document.cookie = 'XSRF-TOKEN=fresh-token'
      return expired()
    }, () => json({ data: 'ok' }))

    await client().post('/things', { a: 1 })

    expect((apiCalls()[0][1].headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('token/value')
    expect((apiCalls()[1][1].headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('fresh-token')
  })

  it('gives up after the single retry and does not loop', async () => {
    enqueue(expired, expired)

    await expect(client().post('/things', { a: 1 })).rejects.toMatchObject({ status: 419 })
    expect(apiCalls()).toHaveLength(2)
  })

  it('does not retry a 419 on a safe method', async () => {
    enqueue(expired)

    await expect(client().get('/things')).rejects.toMatchObject({ status: 419 })
    expect(apiCalls()).toHaveLength(1)
    expect(csrfCalls()).toHaveLength(0)
  })

  it('does not retry a 401', async () => {
    enqueue(() => json({ message: 'Unauthenticated.' }, { status: 401 }))

    await expect(client().post('/things', { a: 1 })).rejects.toMatchObject({ status: 401 })
    expect(apiCalls()).toHaveLength(1)
  })

  it('does not retry other error statuses', async () => {
    enqueue(() => json({ message: 'Conflict' }, { status: 409 }))

    await expect(client().post('/things', { a: 1 })).rejects.toMatchObject({ status: 409 })
    expect(apiCalls()).toHaveLength(1)
  })

  it('replays the retried request body and URL unchanged', async () => {
    enqueue(expired, () => json({ data: 'ok' }))

    await client().patch('/things/1', { name: 'A' })

    expect(apiCalls().map(([url, init]) => [url, init.body])).toEqual([
      [`${BASE_URL}/things/1`, '{"name":"A"}'],
      [`${BASE_URL}/things/1`, '{"name":"A"}'],
    ])
  })
})

describe('auth handler', () => {
  const handler = (retry: boolean) => ({
    handleUnauthorized: vi.fn(async () => ({ retry })),
  })

  it('is consulted on a 401 and rethrows when it declines to retry', async () => {
    const auth = handler(false)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(() => json({ message: 'Unauthenticated.' }, { status: 401 }))

    await expect(c.post('/things', { a: 1 })).rejects.toMatchObject({ status: 401 })
    expect(auth.handleUnauthorized).toHaveBeenCalledTimes(1)
    expect(apiCalls()).toHaveLength(1)
  })

  it('receives the endpoint and the original options', async () => {
    const auth = handler(false)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(() => json({}, { status: 401 }))

    await c.get('/users/me', { include: 'roles' }).catch(() => {})

    expect(auth.handleUnauthorized).toHaveBeenCalledWith('/users/me', {
      method: 'GET',
      query: { include: 'roles' },
    })
  })

  it('replays the request once when the handler asks for a retry', async () => {
    const auth = handler(true)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(() => json({}, { status: 401 }), () => json({ data: 'ok' }))

    expect(await c.get('/users/me')).toEqual({ data: 'ok' })
    expect(apiCalls()).toHaveLength(2)
  })

  it('does not loop when the replay fails again', async () => {
    const auth = handler(true)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(() => json({}, { status: 401 }), () => json({}, { status: 401 }))

    await expect(c.get('/users/me')).rejects.toMatchObject({ status: 401 })
    expect(auth.handleUnauthorized).toHaveBeenCalledTimes(1)
    expect(apiCalls()).toHaveLength(2)
  })

  it('is ignored for non-auth statuses', async () => {
    const auth = handler(true)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(() => json({ message: 'Conflict' }, { status: 409 }))

    await expect(c.post('/things', { a: 1 })).rejects.toMatchObject({ status: 409 })
    expect(auth.handleUnauthorized).not.toHaveBeenCalled()
  })

  it('allows a third attempt when a retried 419 hands over to the auth handler', async () => {
    // ODDITY: the documented "retry once" budget is two attempts, but a handler
    // that answers `{ retry: true }` gets a third request out of the same call.
    const auth = handler(true)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(
      () => json({}, { status: 419 }),
      () => json({}, { status: 419 }),
      () => json({ data: 'ok' })
    )

    expect(await c.post('/things', { a: 1 })).toEqual({ data: 'ok' })
    expect(apiCalls()).toHaveLength(3)
  })

  it('resets the primed flag on an auth error so the next request re-primes', async () => {
    const auth = handler(false)
    const c = client()

    c.setAuthHandler(auth)
    enqueue(() => json({}, { status: 401 }))

    await c.post('/things', { a: 1 }).catch(() => {})
    expect(csrfCalls()).toHaveLength(1)

    await c.post('/things', { a: 2 })
    expect(csrfCalls()).toHaveLength(2)
  })
})
