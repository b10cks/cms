import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiClient } from '~/api/client'
import { BaseResource } from '~/api/resources/base-resource'

const CSRF_ENDPOINT = '/auth/v1/csrf-cookie'
const BASE_URL = 'https://api.b10cks.test'
const BASE_PATH = '/mgmt/v1/spaces/space-1/things'

interface Thing {
  id: string
  name: string
}

interface ThingQueryParams extends BaseQueryParams {
  q?: string
}

class Things extends BaseResource<Thing, { name: string }, { name?: string }, ThingQueryParams> {
  protected basePath = BASE_PATH

  // getPath is protected and only reachable through `custom()`; expose it so the
  // joining rules can be asserted directly.
  public path(endpoint?: string): string {
    return this.getPath(endpoint)
  }
}

const json = (body: unknown, init: ResponseInit = {}) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'content-type': 'application/json' },
    ...init,
  })

const fetchMock = vi.fn()

const isCsrf = (url: unknown) => String(url).endsWith(CSRF_ENDPOINT)

const apiCalls = () =>
  fetchMock.mock.calls.filter(([url]) => !isCsrf(url)) as Array<[string, RequestInit]>

const lastCall = () => {
  const [url, init] = apiCalls().at(-1) as [string, RequestInit]

  return { url, method: init.method, body: init.body }
}

let things: Things

beforeEach(() => {
  fetchMock.mockReset()
  fetchMock.mockImplementation(async (url: unknown) =>
    isCsrf(url) ? new Response(null, { status: 204 }) : json({ data: { id: 't1', name: 'A' } })
  )
  vi.stubGlobal('fetch', fetchMock)
  document.cookie = 'XSRF-TOKEN=token'
  vi.spyOn(console, 'warn').mockImplementation(() => {})
  things = new Things(new ApiClient({ baseURL: BASE_URL }))
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('index', () => {
  it('GETs the base path with no query string', async () => {
    await things.index()

    expect(lastCall()).toMatchObject({
      url: `${BASE_URL}${BASE_PATH}`,
      method: 'GET',
      body: undefined,
    })
  })

  it('forwards pagination and sort params', async () => {
    await things.index({ page: 3, per_page: 50, sort: '-updated_at' })

    expect(lastCall().url).toBe(`${BASE_URL}${BASE_PATH}?page=3&per_page=50&sort=-updated_at`)
  })

  it('drops undefined params so an unset filter is not sent', async () => {
    await things.index({ q: undefined, page: 1 })

    expect(lastCall().url).toBe(`${BASE_URL}${BASE_PATH}?page=1`)
  })

  it('returns the collection envelope as received', async () => {
    const collection = { data: [{ id: 't1', name: 'A' }], links: {}, meta: { total: 1 } }

    fetchMock.mockImplementation(async () => json(collection))

    expect(await things.index()).toEqual(collection)
  })
})

describe('get', () => {
  it('appends the id to the base path', async () => {
    await things.get('t1')

    expect(lastCall()).toMatchObject({ url: `${BASE_URL}${BASE_PATH}/t1`, method: 'GET' })
  })

  it('appends a query alongside the id', async () => {
    await things.get('t1', { q: 'x' })

    expect(lastCall().url).toBe(`${BASE_URL}${BASE_PATH}/t1?q=x`)
  })

  it('URL-encodes the id so a slash or space cannot address another route', async () => {
    // Ids are not always ULIDs — block tags are addressed by name.
    await things.get('a b/c')

    expect(lastCall().url).toBe(`${BASE_URL}${BASE_PATH}/a%20b%2Fc`)
  })

  it('leaves a trailing slash for an empty id', async () => {
    await things.get('')

    expect(lastCall().url).toBe(`${BASE_URL}${BASE_PATH}/`)
  })

  it('returns the single-resource envelope', async () => {
    expect(await things.get('t1')).toEqual({ data: { id: 't1', name: 'A' } })
  })
})

describe('create', () => {
  it('POSTs the payload as JSON to the base path', async () => {
    await things.create({ name: 'A' })

    expect(lastCall()).toEqual({
      url: `${BASE_URL}${BASE_PATH}`,
      method: 'POST',
      body: '{"name":"A"}',
    })
  })

  it('primes the CSRF cookie before writing', async () => {
    await things.create({ name: 'A' })

    expect(fetchMock.mock.calls.filter(([url]) => isCsrf(url))).toHaveLength(1)
  })

  it('returns the server response verbatim, so a non-envelope shape survives', async () => {
    fetchMock.mockImplementation(async (url: unknown) =>
      isCsrf(url)
        ? new Response(null, { status: 204 })
        : json({ token: { id: 't1' }, plain_text_token: 'secret' })
    )

    expect(await things.create({ name: 'A' })).toEqual({
      token: { id: 't1' },
      plain_text_token: 'secret',
    })
  })
})

describe('update', () => {
  it('PATCHes the payload to the id path', async () => {
    await things.update('t1', { name: 'B' })

    expect(lastCall()).toEqual({
      url: `${BASE_URL}${BASE_PATH}/t1`,
      method: 'PATCH',
      body: '{"name":"B"}',
    })
  })

  it('sends an empty object rather than nothing for an empty payload', async () => {
    await things.update('t1', {})

    expect(lastCall().body).toBe('{}')
  })
})

describe('replace', () => {
  it('PUTs the payload to the id path', async () => {
    await things.replace('t1', { name: 'B' })

    expect(lastCall()).toEqual({
      url: `${BASE_URL}${BASE_PATH}/t1`,
      method: 'PUT',
      body: '{"name":"B"}',
    })
  })
})

describe('delete', () => {
  it('DELETEs the id path with no body', async () => {
    await things.delete('t1')

    expect(lastCall()).toEqual({
      url: `${BASE_URL}${BASE_PATH}/t1`,
      method: 'DELETE',
      body: undefined,
    })
  })

  it('resolves with the empty body of a 204 despite its void return type', async () => {
    fetchMock.mockImplementation(async () => new Response(null, { status: 204 }))

    expect(await things.delete('t1')).toBe('' as unknown as void)
  })

  it('rejects with the status and data of a refused delete', async () => {
    fetchMock.mockImplementation(async (url: unknown) =>
      isCsrf(url)
        ? new Response(null, { status: 204 })
        : json({ message: 'Asset is in use', code: 'asset_in_use' }, { status: 409 })
    )

    // The 409/`asset_in_use` pair other code branches on has to survive the
    // BaseResource layer untouched.
    await expect(things.delete('t1')).rejects.toMatchObject({
      status: 409,
      data: { code: 'asset_in_use' },
    })
  })
})

describe('getPath', () => {
  it('returns the base path for an omitted or empty endpoint', () => {
    expect(things.path()).toBe(BASE_PATH)
    expect(things.path('')).toBe(BASE_PATH)
  })

  it('joins a relative endpoint with a slash', () => {
    expect(things.path('export')).toBe(`${BASE_PATH}/export`)
  })

  it('concatenates an endpoint that already starts with a slash', () => {
    expect(things.path('/export')).toBe(`${BASE_PATH}/export`)
  })

  it('does not collapse a doubled slash', () => {
    expect(things.path('//export')).toBe(`${BASE_PATH}//export`)
  })

  it('keeps query strings and nested segments as given', () => {
    expect(things.path('t1/versions?page=2')).toBe(`${BASE_PATH}/t1/versions?page=2`)
  })
})

describe('custom', () => {
  it.each([
    ['POST', '{"a":1}'],
    ['PUT', '{"a":1}'],
    ['PATCH', '{"a":1}'],
  ] as const)('%s sends the payload to the joined path', async (method, body) => {
    await things.custom(method, 'bulk', { a: 1 })

    expect(lastCall()).toEqual({ url: `${BASE_URL}${BASE_PATH}/bulk`, method, body })
  })

  it.each(['GET', 'DELETE'] as const)('%s silently discards the payload', async (method) => {
    // ODDITY: `custom` accepts a payload for every method but only forwards it on
    // POST/PUT/PATCH — a GET or DELETE payload vanishes without a warning.
    await things.custom(method, 'bulk', { a: 1 })

    expect(lastCall()).toEqual({ url: `${BASE_URL}${BASE_PATH}/bulk`, method, body: undefined })
  })

  it('supports no payload at all', async () => {
    await things.custom('POST', 'publish')

    expect(lastCall().body).toBeUndefined()
  })

  it('has no way to pass a query string other than inside the endpoint', async () => {
    await things.custom('GET', 'export?as=csv')

    expect(lastCall().url).toBe(`${BASE_URL}${BASE_PATH}/export?as=csv`)
  })
})

describe('client sharing', () => {
  it('routes every resource built on one client through that client', async () => {
    const client = new ApiClient({ baseURL: 'https://other.test' })

    class Others extends Things {
      protected basePath = '/mgmt/v1/others'
    }

    await new Others(client).index()

    expect(lastCall().url).toBe('https://other.test/mgmt/v1/others')
  })
})
