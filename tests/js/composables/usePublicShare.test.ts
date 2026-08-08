import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const show = vi.fn()
const unlock = vi.fn()
const assets = vi.fn()
const download = vi.fn()
const downloadAsset = vi.fn()

/** Records how the composable addresses the public endpoint. */
const constructed: Array<{ spaceId: string; token: string }> = []

vi.mock('~/api', () => ({ api: { client: { id: 'shared-client' } } }))

vi.mock('~/api/resources/public-share', () => ({
  PublicShare: class {
    constructor(_client: unknown, spaceId: string, token: string) {
      constructed.push({ spaceId, token })
    }
    show = show
    unlock = unlock
    assets = assets
    download = download
    downloadAsset = downloadAsset
  },
}))

const { usePublicShare } = await import('~/composables/usePublicShare')

const SPACE = 'space-1'
const TOKEN = 'tok_abc'
const keys = queryKeys.publicShare(SPACE, TOKEN)
const STORAGE_KEY = `b10cks:share:${SPACE}:${TOKEN}:access`

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const shareMeta = (extra: Record<string, unknown> = {}) => ({
  name: 'Press kit',
  is_protected: false,
  ...extra,
})

const assetPage = (page: number, lastPage: number) => ({
  data: [{ id: `a${page}` }],
  meta: { current_page: page, last_page: lastPage, per_page: 48, total: lastPage },
})

const mounted: Array<() => void> = []

const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const forShare = (spaceId: MaybeRef<string> = SPACE, token: MaybeRef<string> = TOKEN) =>
  usePublicShare(spaceId, token)

type Share = ReturnType<typeof usePublicShare>

/**
 * The composable and whatever factory a test needs must share one mount — each
 * withSetup() call builds its own QueryClient.
 */
const mountShare = <T>(build: (share: Share) => T) =>
  mount(() => {
    const share = forShare()
    return { share, extra: build(share) }
  })

const apiError = (status: number) => Object.assign(new Error(`HTTP ${status}`), { status })

beforeEach(() => {
  for (const fn of [show, unlock, assets, download, downloadAsset]) fn.mockReset()
  constructed.length = 0
  sessionStorage.clear()
  show.mockResolvedValue({ data: shareMeta() })
  assets.mockResolvedValue(assetPage(1, 1))
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('live updates', () => {
  interface FakeChannel {
    name: string
    listeners: Map<string, () => void>
  }

  const left: string[] = []
  const channels: FakeChannel[] = []

  const fakeEcho = {
    channel: (name: string) => {
      const channel: FakeChannel = { name, listeners: new Map() }
      channels.push(channel)
      return {
        listen: (event: string, callback: () => void) => {
          channel.listeners.set(event, callback)
        },
      }
    },
    leave: (name: string) => {
      left.push(name)
    },
  }

  beforeEach(() => {
    left.length = 0
    channels.length = 0
    window.Echo = fakeEcho as unknown as typeof window.Echo
  })

  afterEach(() => {
    Reflect.deleteProperty(window, 'Echo')
  })

  it('subscribes to the public channel named by space and token', () => {
    mount(() => forShare())

    expect(channels.map((channel) => channel.name)).toEqual([`public-share.${SPACE}.${TOKEN}`])
    expect(Array.from(channels[0].listeners.keys())).toEqual(['.share:updated'])
  })

  it('refetches the share on a ping', () => {
    const harness = mount(() => forShare())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    channels[0].listeners.get('.share:updated')?.()

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('leaves the channel on unmount', () => {
    mount(() => forShare())

    mounted.pop()?.()

    expect(left).toEqual([`public-share.${SPACE}.${TOKEN}`])
  })

  it('resubscribes when the viewed share changes', async () => {
    const token = ref(TOKEN)
    mount(() => usePublicShare(SPACE, token))

    token.value = 'tok_two'
    await flush()

    expect(left).toEqual([`public-share.${SPACE}.${TOKEN}`])
    expect(channels.map((channel) => channel.name)).toEqual([
      `public-share.${SPACE}.${TOKEN}`,
      `public-share.${SPACE}.tok_two`,
    ])
  })

  it('works without Echo', () => {
    Reflect.deleteProperty(window, 'Echo')

    expect(() => {
      mount(() => forShare())
      mounted.pop()?.()
    }).not.toThrow()
  })
})

describe('access token storage', () => {
  it('starts with no token for a fresh visitor', () => {
    expect(mount(() => forShare()).result.accessToken.value).toBeNull()
  })

  it('picks up a token stored earlier in the same session', () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')

    expect(mount(() => forShare()).result.accessToken.value).toBe('access-1')
  })

  it('namespaces the storage key by space and token', () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')

    // A different share on the same origin must not inherit the access.
    expect(mount(() => forShare(SPACE, 'other-token')).result.accessToken.value).toBeNull()
    expect(mount(() => forShare('space-2', TOKEN)).result.accessToken.value).toBeNull()
  })

  it('re-reads storage when the share being viewed changes', async () => {
    sessionStorage.setItem(`b10cks:share:${SPACE}:tok_two:access`, 'access-2')
    const token = ref(TOKEN)
    const share = mount(() => forShare(SPACE, token)).result

    expect(share.accessToken.value).toBeNull()

    token.value = 'tok_two'
    await nextTick()

    expect(share.accessToken.value).toBe('access-2')
  })

  it('survives sessionStorage throwing, e.g. in private mode', () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })

    expect(mount(() => forShare()).result.accessToken.value).toBeNull()
    getItem.mockRestore()
  })

  it('clearAccess wipes both the ref and the stored copy', () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')
    const share = mount(() => forShare()).result

    share.clearAccess()

    expect(share.accessToken.value).toBeNull()
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })
})

describe('addressing', () => {
  it('scopes the API to the space and the token, because shares live per space DB', async () => {
    mount(() => forShare().useShareQuery())
    await flush()

    expect(constructed).toEqual([{ spaceId: SPACE, token: TOKEN }])
  })

  it('builds no client until something actually requests', () => {
    // shareAPI is a lazy computed, so merely calling the composable is free.
    mount(() => forShare())

    expect(constructed).toEqual([])
  })

  it('rebuilds the client when the token being viewed changes', async () => {
    const token = ref(TOKEN)
    mount(() => forShare(SPACE, token).useShareQuery())
    await flush()

    token.value = 'tok_two'
    await nextTick()
    await flush()

    expect(constructed).toEqual([
      { spaceId: SPACE, token: TOKEN },
      { spaceId: SPACE, token: 'tok_two' },
    ])
  })
})

describe('useShareQuery', () => {
  it('fetches the metadata with no access token for an open share', async () => {
    const query = mount(() => forShare().useShareQuery()).result
    await flush()

    expect(show).toHaveBeenCalledWith(null)
    expect(query.data.value).toEqual(shareMeta())
  })

  it('sends the stored access token once the share is unlocked', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')

    mount(() => forShare().useShareQuery())
    await flush()

    expect(show).toHaveBeenCalledWith('access-1')
  })

  it('keys the cache on the access token, so locked and unlocked never mix', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')
    const harness = mount(() => forShare().useShareQuery())
    await flush()

    expect(harness.queryClient.getQueryData([...keys.meta(), 'access-1'])).toEqual(shareMeta())
    expect(harness.queryClient.getQueryData([...keys.meta(), null])).toBeUndefined()
  })

  it('drops a rejected access token and retries as a locked visitor', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'stale')
    show
      .mockRejectedValueOnce(apiError(403))
      .mockResolvedValueOnce({ data: shareMeta({ is_protected: true }) })

    const harness = mountShare((share) => share.useShareQuery())
    await flush()
    await flush()

    expect(show).toHaveBeenNthCalledWith(1, 'stale')
    expect(show).toHaveBeenNthCalledWith(2, null)
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
    expect(harness.result.share.accessToken.value).toBeNull()
  })

  it('spends exactly two metered requests on a stale token', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'stale')
    show
      .mockRejectedValueOnce(apiError(403))
      .mockResolvedValueOnce({ data: shareMeta({ is_protected: true }) })

    mountShare((share) => share.useShareQuery())
    await flush()
    await flush()

    // The rejection rekeys the query (the token is part of the key), and that
    // rekeyed fetch is the anonymous retry — no third round trip from queryFn.
    expect(show).toHaveBeenCalledTimes(2)
    expect(show).toHaveBeenNthCalledWith(2, null)
  })

  it('gives up immediately on a 404 — an unknown share will not appear', async () => {
    show.mockRejectedValue(apiError(404))

    const harness = mountShare((share) => share.useShareQuery())
    await flush()

    expect(show).toHaveBeenCalledTimes(1)
    expect(harness.result.extra.isError.value).toBe(true)
  })

  it.each([[410], [500]])('retries a %i twice before failing', async (status) => {
    const harness = mountShare((share) => share.useShareQuery())
    await flush()

    const cached = harness.queryClient
      .getQueryCache()
      .find({ queryKey: [...keys.meta(), null] })
    const retry = cached?.options.retry as (count: number, error: unknown) => boolean

    expect(retry(0, apiError(status))).toBe(true)
    expect(retry(1, apiError(status))).toBe(true)
    expect(retry(2, apiError(status))).toBe(false)
  })

  it.each([[404], [403]])('never retries a %i, whatever the attempt count', async (status) => {
    const harness = mountShare((share) => share.useShareQuery())
    await flush()

    const cached = harness.queryClient
      .getQueryCache()
      .find({ queryKey: [...keys.meta(), null] })
    const retry = cached?.options.retry as (count: number, error: unknown) => boolean

    // An unknown share will not appear, and a rejected token will not be accepted.
    expect(retry(0, apiError(status))).toBe(false)
    expect(retry(1, apiError(status))).toBe(false)
  })

  it('retries an error with no status at all', async () => {
    const harness = mountShare((share) => share.useShareQuery())
    await flush()

    const cached = harness.queryClient
      .getQueryCache()
      .find({ queryKey: [...keys.meta(), null] })
    const retry = cached?.options.retry as (count: number, error: unknown) => boolean

    expect(retry(0, new Error('offline'))).toBe(true)
  })
})

describe('useShareAssetsQuery', () => {
  it('requests the first page with the default page size', async () => {
    mount(() => forShare().useShareAssetsQuery())
    await flush()

    expect(assets).toHaveBeenCalledWith({ page: 1, per_page: 48 }, null)
  })

  it('honours a custom page size', async () => {
    mount(() => forShare().useShareAssetsQuery(12))
    await flush()

    expect(assets).toHaveBeenCalledWith({ page: 1, per_page: 12 }, null)
  })

  it('passes the access token on every page', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')
    assets.mockResolvedValue(assetPage(1, 2))

    const query = mount(() => forShare().useShareAssetsQuery()).result
    await flush()

    assets.mockResolvedValue(assetPage(2, 2))
    await query.fetchNextPage()
    await flush()

    expect(assets).toHaveBeenNthCalledWith(1, { page: 1, per_page: 48 }, 'access-1')
    expect(assets).toHaveBeenNthCalledWith(2, { page: 2, per_page: 48 }, 'access-1')
  })

  it('stops paging on the last page', async () => {
    assets.mockResolvedValue(assetPage(1, 1))

    const query = mount(() => forShare().useShareAssetsQuery()).result
    await flush()

    expect(query.hasNextPage.value).toBe(false)
  })

  it('offers a next page while there is one', async () => {
    assets.mockResolvedValue(assetPage(1, 3))

    const query = mount(() => forShare().useShareAssetsQuery()).result
    await flush()

    expect(query.hasNextPage.value).toBe(true)
  })

  it('stays idle when disabled, e.g. while the share is still locked', async () => {
    const query = mount(() => forShare().useShareAssetsQuery(48, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(assets).not.toHaveBeenCalled()
  })

  it('keys the pages on the access token as well', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')
    const harness = mount(() => forShare().useShareAssetsQuery())
    await flush()

    const key = [...keys.assetsList({ per_page: 48 }), 'access-1']

    expect(harness.queryClient.getQueryData(key)).toBeDefined()
  })
})

describe('useUnlockMutation', () => {
  it('stores the returned access token and refreshes the share', async () => {
    unlock.mockResolvedValue({ access_token: 'access-1' })
    const harness = mountShare((share) => share.useUnlockMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.extra.mutateAsync('hunter2')

    expect(unlock).toHaveBeenCalledWith('hunter2')
    expect(harness.result.share.accessToken.value).toBe('access-1')
    expect(sessionStorage.getItem(STORAGE_KEY)).toBe('access-1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('keeps the token in memory when sessionStorage refuses to write', async () => {
    unlock.mockResolvedValue({ access_token: 'access-1' })
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    const harness = mountShare((share) => share.useUnlockMutation())

    await harness.result.extra.mutateAsync('hunter2')

    expect(harness.result.share.accessToken.value).toBe('access-1')
    setItem.mockRestore()
  })

  it('leaves the token untouched on a wrong password', async () => {
    unlock.mockRejectedValue(apiError(422))
    const harness = mountShare((share) => share.useUnlockMutation())

    await expect(harness.result.extra.mutateAsync('wrong')).rejects.toThrow('HTTP 422')

    expect(harness.result.share.accessToken.value).toBeNull()
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
    // No onError handler and no toast import: the unlock form renders the error.
  })
})

describe('downloadAll', () => {
  it('returns the signed URL straight away and refreshes the share', async () => {
    download.mockResolvedValue({ url: 'https://cdn.test/kit.zip', expires_at: null })
    const share = mount(() => forShare())
    const invalidate = vi.spyOn(share.queryClient, 'invalidateQueries')

    const result = await share.result.downloadAll()

    expect(download).toHaveBeenCalledWith(null)
    expect(result).toEqual({ url: 'https://cdn.test/kit.zip', expires_at: null })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('sends the access token for a protected share', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')
    download.mockResolvedValue({ url: 'https://cdn.test/kit.zip' })

    await mount(() => forShare()).result.downloadAll()

    expect(download).toHaveBeenCalledWith('access-1')
  })

  it('polls the 202 building responses and reports progress', async () => {
    vi.useFakeTimers()
    download
      .mockResolvedValueOnce({ state: 'building', progress: 10 })
      .mockResolvedValueOnce({ state: 'building', progress: 80 })
      .mockResolvedValueOnce({ url: 'https://cdn.test/kit.zip' })
    const progress: Array<number | null> = []

    const pending = mount(() => forShare()).result.downloadAll((value) => progress.push(value))
    await vi.advanceTimersByTimeAsync(6000)

    expect(await pending).toEqual({ url: 'https://cdn.test/kit.zip' })
    expect(progress).toEqual([10, 80])
    expect(download).toHaveBeenCalledTimes(3)
  })

  it('reports a missing progress value as null rather than undefined', async () => {
    vi.useFakeTimers()
    download
      .mockResolvedValueOnce({ state: 'pending' })
      .mockResolvedValueOnce({ url: 'https://cdn.test/kit.zip' })
    const progress: Array<number | null> = []

    const pending = mount(() => forShare()).result.downloadAll((value) => progress.push(value))
    await vi.advanceTimersByTimeAsync(3000)
    await pending

    expect(progress).toEqual([null])
  })

  it('waits 3s between polls', async () => {
    vi.useFakeTimers()
    download
      .mockResolvedValueOnce({ state: 'building', progress: 1 })
      .mockResolvedValueOnce({ url: 'https://cdn.test/kit.zip' })

    const pending = mount(() => forShare()).result.downloadAll()

    await vi.advanceTimersByTimeAsync(2900)
    expect(download).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(100)
    await pending
    expect(download).toHaveBeenCalledTimes(2)
  })

  it('surfaces a failed build instead of waiting for the server cooldown', async () => {
    download.mockResolvedValue({ state: 'failed' })

    await expect(mount(() => forShare()).result.downloadAll()).rejects.toThrow(
      'Package build failed'
    )
    expect(download).toHaveBeenCalledTimes(1)
  })

  it('gives up after 200 attempts rather than polling forever', async () => {
    vi.useFakeTimers()
    download.mockResolvedValue({ state: 'building', progress: 0 })

    const pending = mount(() => forShare())
      .result.downloadAll()
      .catch((reason: Error) => reason.message)

    await vi.advanceTimersByTimeAsync(3000 * 200)

    expect(await pending).toBe('Package build timed out')
    expect(download).toHaveBeenCalledTimes(200)
  })

  it('propagates a transport failure without retrying', async () => {
    download.mockRejectedValue(apiError(403))

    await expect(mount(() => forShare()).result.downloadAll()).rejects.toThrow('HTTP 403')
    expect(download).toHaveBeenCalledTimes(1)
  })

  it('does not invalidate the share when the build fails', async () => {
    download.mockResolvedValue({ state: 'failed' })
    const share = mount(() => forShare())
    const invalidate = vi.spyOn(share.queryClient, 'invalidateQueries')

    await share.result.downloadAll().catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('downloadAsset', () => {
  it('asks for the single-asset URL with the current access token', async () => {
    sessionStorage.setItem(STORAGE_KEY, 'access-1')
    downloadAsset.mockResolvedValue({ url: 'https://cdn.test/a1.png', expires_at: null })

    const result = await mount(() => forShare()).result.downloadAsset('a1')

    expect(downloadAsset).toHaveBeenCalledWith('a1', 'access-1')
    expect(result.url).toBe('https://cdn.test/a1.png')
  })

  it('sends null for an open share', async () => {
    downloadAsset.mockResolvedValue({ url: 'https://cdn.test/a1.png' })

    await mount(() => forShare()).result.downloadAsset('a1')

    expect(downloadAsset).toHaveBeenCalledWith('a1', null)
  })

  it('refreshes the share, because a single download is metered too', async () => {
    downloadAsset.mockResolvedValue({ url: 'https://cdn.test/a1.png' })
    const share = mount(() => forShare())
    const invalidate = vi.spyOn(share.queryClient, 'invalidateQueries')

    await share.result.downloadAsset('a1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it('does not refresh the share when the download fails', async () => {
    downloadAsset.mockRejectedValue(apiError(429))
    const share = mount(() => forShare())
    const invalidate = vi.spyOn(share.queryClient, 'invalidateQueries')

    await share.result.downloadAsset('a1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
  })

  it('propagates a 429 once the download limit is hit', async () => {
    downloadAsset.mockRejectedValue(apiError(429))

    await expect(mount(() => forShare()).result.downloadAsset('a1')).rejects.toThrow('HTTP 429')
  })
})

describe('anonymous transport', () => {
  // PublicShare is module-mocked above for the composable tests; this block
  // pins what actually goes over the wire, so it loads the real class and a
  // real ApiClient against a stubbed fetch.
  const realShare = async () => {
    const { PublicShare } = await vi.importActual<typeof import('~/api/resources/public-share')>(
      '~/api/resources/public-share'
    )
    const { ApiClient } = await vi.importActual<typeof import('~/api/client')>('~/api/client')

    return new PublicShare(new ApiClient(), SPACE, TOKEN)
  }

  const fetchMock = vi.fn(
    async (_url: string, _init: RequestInit) =>
      new Response('{"data":{}}', {
        status: 200,
        headers: { 'content-type': 'application/json' },
      })
  )

  const calls = () => fetchMock.mock.calls

  beforeEach(() => {
    fetchMock.mockClear()
    vi.stubGlobal('fetch', fetchMock)
    // A logged-in visitor carries this cookie; the share page must not use it.
    document.cookie = 'XSRF-TOKEN=visitor-session'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  })

  it('sends every share request without credentials, keeping the session cookie at home', async () => {
    const share = await realShare()

    await share.show()
    await share.unlock('hunter2')
    await share.assets()
    await share.download()
    await share.downloadAsset('a1')

    expect(calls()).toHaveLength(5)
    for (const [, init] of calls()) {
      expect(init.credentials).toBe('omit')
    }
  })

  it('unlocks without priming the CSRF cookie or sending an X-XSRF-TOKEN header', async () => {
    const share = await realShare()

    await share.unlock('hunter2')

    // An anonymous visitor must never be bounced through /auth/v1/csrf-cookie.
    expect(calls()).toHaveLength(1)
    const [url, init] = calls()[0]

    expect(String(url)).toBe(`/mgmt/v1/shares/${SPACE}/${TOKEN}/unlock`)
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('still sends the unlock access token as an explicit bearer header', async () => {
    const share = await realShare()

    await share.show('access-1')

    expect((calls()[0][1].headers as Record<string, string>).Authorization).toBe('Bearer access-1')
  })
})

describe('query key shape', () => {
  it('scopes the key to the space and the token', () => {
    expect(keys.all()).toEqual(['public-share', SPACE, TOKEN])
    expect(queryKeys.publicShare(SPACE, 'a').all()).not.toEqual(
      queryKeys.publicShare(SPACE, 'b').all()
    )
  })

  it('keeps meta and assets under one all() prefix, so unlock invalidates both', () => {
    expect(keys.meta().slice(0, keys.all().length)).toEqual([...keys.all()])
    expect(keys.assets().slice(0, keys.all().length)).toEqual([...keys.all()])
  })

  it('stays outside the authenticated spaces namespace', () => {
    expect(keys.all()[0]).toBe('public-share')
  })
})
