import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ICONIFY_HOST, splitIconName } from '~/lib/iconify'

const fetchMock = vi.fn()

// `fetchIconifyCollection` memoises per prefix in module scope, so each test
// gets a fresh module registry instead of inheriting the previous cache.
const loadIconify = async () => {
  vi.resetModules()
  return import('~/lib/iconify')
}

const json = (body: unknown, init: ResponseInit = {}) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'content-type': 'application/json' },
    ...init,
  })

const lastUrl = () => fetchMock.mock.calls.at(-1)?.[0] as string

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.resetModules()
})

describe('ICONIFY_HOST', () => {
  it('points at the public Iconify API over https', () => {
    expect(ICONIFY_HOST).toBe('https://api.iconify.design')
  })
})

describe('fetchIconifyCollections', () => {
  it('normalises the keyed payload into a sorted array', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () =>
      json({
        mdi: { name: 'Material Design Icons', total: 7447, category: 'General' },
        lucide: { name: 'Lucide', total: 1400, category: 'General' },
      })
    )

    expect(await fetchIconifyCollections()).toEqual([
      { prefix: 'lucide', name: 'Lucide', total: 1400, category: 'General' },
      { prefix: 'mdi', name: 'Material Design Icons', total: 7447, category: 'General' },
    ])
    expect(lastUrl()).toBe('https://api.iconify.design/collections')
  })

  it('falls back to the prefix when the name is missing or empty', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ zzz: {}, aaa: { name: '' } }))

    expect(await fetchIconifyCollections()).toEqual([
      { prefix: 'aaa', name: 'aaa', total: 0, category: null },
      { prefix: 'zzz', name: 'zzz', total: 0, category: null },
    ])
  })

  it('coerces a non-numeric total to zero and a missing category to null', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ mdi: { name: 'MDI', total: '7447' } }))

    expect(await fetchIconifyCollections()).toEqual([
      { prefix: 'mdi', name: 'MDI', total: 0, category: null },
    ])
  })

  it('keeps a total of zero rather than treating it as missing', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ mdi: { name: 'MDI', total: 0 } }))

    expect((await fetchIconifyCollections())[0].total).toBe(0)
  })

  it('survives a null meta entry', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ mdi: null }))

    expect(await fetchIconifyCollections()).toEqual([
      { prefix: 'mdi', name: 'mdi', total: 0, category: null },
    ])
  })

  it('returns an empty array for an empty payload', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () => json({}))

    expect(await fetchIconifyCollections()).toEqual([])
  })

  it('throws with the status code on a non-OK response', async () => {
    const { fetchIconifyCollections } = await loadIconify()
    fetchMock.mockImplementation(async () => json({}, { status: 503 }))

    await expect(fetchIconifyCollections()).rejects.toThrow(
      'Iconify collections request failed: 503'
    )
  })
})

describe('fetchIconifyCollection', () => {
  it('flattens uncategorized and categorised names into fully-qualified ids', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () =>
      json({ uncategorized: ['home'], categories: { Nav: ['back', 'forward'] } })
    )

    expect(await fetchIconifyCollection('mdi')).toEqual([
      'mdi:home',
      'mdi:back',
      'mdi:forward',
    ])
  })

  it('URL-encodes the prefix', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({}))

    await fetchIconifyCollection('a b/c')

    expect(lastUrl()).toBe('https://api.iconify.design/collection?prefix=a%20b%2Fc')
  })

  it('forwards the abort signal', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({}))
    const signal = new AbortController().signal

    await fetchIconifyCollection('mdi', signal)

    expect(fetchMock.mock.calls.at(-1)?.[1]).toEqual({ signal })
  })

  it('returns an empty array when neither key is present', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ total: 0 }))

    expect(await fetchIconifyCollection('mdi')).toEqual([])
  })

  it('ignores non-array categories values and a non-array uncategorized', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () =>
      json({ uncategorized: 'home', categories: { Nav: 'back', Ok: ['fine'] } })
    )

    expect(await fetchIconifyCollection('mdi')).toEqual(['mdi:fine'])
  })

  it('ignores a null categories object', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ uncategorized: ['a'], categories: null }))

    expect(await fetchIconifyCollection('mdi')).toEqual(['mdi:a'])
  })

  it('caches per prefix and fires only one request', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ uncategorized: ['home'] }))

    const first = await fetchIconifyCollection('mdi')
    const second = await fetchIconifyCollection('mdi')

    expect(fetchMock).toHaveBeenCalledTimes(1)
    // A fresh copy per caller: sorting or splicing the result must not corrupt
    // the cached list for everyone else.
    expect(second).toEqual(first)
    expect(second).not.toBe(first)

    first.length = 0
    expect(await fetchIconifyCollection('mdi')).toEqual(['mdi:home'])
  })

  it('fires a single request for concurrent callers of one prefix', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ uncategorized: ['home'] }))

    const [a, b] = await Promise.all([
      fetchIconifyCollection('mdi'),
      fetchIconifyCollection('mdi'),
    ])

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(a).toEqual(['mdi:home'])
    expect(b).toEqual(['mdi:home'])
  })

  it('caches each prefix separately', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ uncategorized: ['home'] }))

    await fetchIconifyCollection('mdi')
    await fetchIconifyCollection('lucide')

    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  // An empty answer is a failed or unknown prefix, not a collection: memoising
  // it would keep the picker empty for the rest of the session.
  it('does not cache an empty result', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => json({}))

    expect(await fetchIconifyCollection('mdi')).toEqual([])
    expect(await fetchIconifyCollection('mdi')).toEqual([])
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('can be invalidated', async () => {
    const { fetchIconifyCollection, clearIconifyCollectionCache } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ uncategorized: ['home'] }))

    await fetchIconifyCollection('mdi')
    clearIconifyCollectionCache()
    await fetchIconifyCollection('mdi')

    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('rejects on a failed response rather than parsing the error body', async () => {
    const { fetchIconifyCollection } = await loadIconify()
    fetchMock.mockImplementation(async () => new Response('Gateway Timeout', { status: 504 }))

    await expect(fetchIconifyCollection('mdi')).rejects.toThrow(
      'Iconify collection request failed: 504'
    )
  })
})

describe('searchIconifyIcons', () => {
  it('sends the query with the default limit of 120', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: ['mdi:home'], total: 1 }))

    expect(await searchIconifyIcons('home')).toEqual({ icons: ['mdi:home'], total: 1 })
    expect(lastUrl()).toBe('https://api.iconify.design/search?query=home&limit=120')
  })

  it('honours an explicit limit', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('home', { limit: 10 })

    expect(lastUrl()).toContain('limit=10')
  })

  it('treats limit 0 as explicit rather than falling back to 120', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('home', { limit: 0 })

    expect(lastUrl()).toContain('limit=0')
  })

  it('scopes to a single prefix', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('home', { prefix: 'mdi' })

    expect(lastUrl()).toContain('prefix=mdi')
    expect(lastUrl()).not.toContain('prefixes=')
  })

  it('joins multiple prefixes with commas', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('home', { prefixes: ['mdi', 'lucide'] })

    expect(lastUrl()).toContain('prefixes=mdi%2Clucide')
  })

  it('lets a single prefix win over a prefixes list', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('home', { prefix: 'mdi', prefixes: ['lucide'] })

    expect(lastUrl()).toContain('prefix=mdi')
    expect(lastUrl()).not.toContain('prefixes=')
  })

  it('ignores an empty prefixes array', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('home', { prefixes: [] })

    expect(lastUrl()).not.toContain('prefixes=')
  })

  it('URL-encodes the query', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))

    await searchIconifyIcons('a b&c')

    expect(lastUrl()).toContain('query=a+b%26c')
  })

  it('derives the total from the icon count when the API omits it', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: ['a', 'b'] }))

    expect(await searchIconifyIcons('home')).toEqual({ icons: ['a', 'b'], total: 2 })
  })

  it('falls back to an empty icon list when icons is not an array', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: null, total: 'many' }))

    expect(await searchIconifyIcons('home')).toEqual({ icons: [], total: 0 })
  })

  it('forwards the abort signal', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ icons: [] }))
    const signal = new AbortController().signal

    await searchIconifyIcons('home', {}, signal)

    expect(fetchMock.mock.calls.at(-1)?.[1]).toEqual({ signal })
  })

  // A JSON error body would otherwise be read as data and yield an empty list,
  // which is indistinguishable from "no matches".
  it('rejects on a failed response rather than reading the error body', async () => {
    const { searchIconifyIcons } = await loadIconify()
    fetchMock.mockImplementation(async () => json({ error: 'nope' }, { status: 500 }))

    await expect(searchIconifyIcons('home')).rejects.toThrow('Iconify search request failed: 500')
  })
})

describe('splitIconName', () => {
  it('splits a fully-qualified name at the first colon', () => {
    expect(splitIconName('mdi:home')).toEqual({ prefix: 'mdi', name: 'home' })
  })

  it('keeps later colons in the name', () => {
    expect(splitIconName('b10cks:my:icon')).toEqual({ prefix: 'b10cks', name: 'my:icon' })
  })

  it('reports a null prefix for a bare name', () => {
    expect(splitIconName('home')).toEqual({ prefix: null, name: 'home' })
  })

  it('returns an empty prefix string for a leading colon', () => {
    expect(splitIconName(':home')).toEqual({ prefix: '', name: 'home' })
  })

  it('returns an empty name for a trailing colon', () => {
    expect(splitIconName('mdi:')).toEqual({ prefix: 'mdi', name: '' })
  })

  it('treats the empty string as a bare, empty name', () => {
    expect(splitIconName('')).toEqual({ prefix: null, name: '' })
  })
})
