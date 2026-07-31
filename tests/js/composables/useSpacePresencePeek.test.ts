import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { SpacePresenceInfo } from '~/composables/useSpacePresencePeek'

import { useSpacePresencePeek } from '~/composables/useSpacePresencePeek'

import { presenceUser } from '../support/presence'

/**
 * The composable dynamically imports `~/api`, so the seam is `fetch` rather
 * than the api module: vitest hands a concurrently imported mocked module back
 * unmocked, which silently let the real client through.
 */
const fetchMock = vi.fn<(url: string) => Promise<Response>>()

const info = (spaceId: string, userIds: string[] = ['peer']): SpacePresenceInfo => ({
  spaceId,
  users: userIds.map((id) => presenceUser(id)),
  count: userIds.length,
})

const jsonResponse = (body: unknown) =>
  new Response(JSON.stringify(body), { headers: { 'content-type': 'application/json' } })

const respondWith = (resolver: (url: string) => unknown) =>
  fetchMock.mockImplementation(async (url: string) => jsonResponse(resolver(url)))

const urlsCalled = () => fetchMock.mock.calls.map(([url]) => String(url))

let consoleError: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  consoleError.mockRestore()
  vi.unstubAllGlobals()
})

describe('peekSpacePresence', () => {
  it('stores what the presence endpoint reports', async () => {
    respondWith(() => ({ data: info('space-1') }))

    const peek = useSpacePresencePeek()
    await peek.peekSpacePresence('space-1')

    expect(urlsCalled()).toEqual(['/mgmt/v1/spaces/space-1/presence'])
    expect(peek.getSpacePresence('space-1')?.count).toBe(1)
    expect(peek.getSpaceUsers('space-1').map((user) => user.id)).toEqual(['peer'])
  })

  it('reports loading while the request is in flight', async () => {
    // The deferred is built up front: the composable dynamically imports `~/api`
    // before it fetches, so the mock body has not run yet when the test resolves it.
    let release: (response: Response) => void = () => {}
    const inFlight = new Promise<Response>((resolve) => {
      release = resolve
    })
    fetchMock.mockImplementation(() => inFlight)

    const peek = useSpacePresencePeek()
    const pending = peek.peekSpacePresence('space-1')

    expect(peek.isLoading.value).toBe(true)

    release(jsonResponse({ data: info('space-1') }))
    await pending

    expect(peek.isLoading.value).toBe(false)
  })

  it('keeps the previous entry when a later request fails', async () => {
    respondWith(() => ({ data: info('space-1') }))

    const peek = useSpacePresencePeek()
    await peek.peekSpacePresence('space-1')

    fetchMock.mockRejectedValue(new Error('offline'))
    await peek.peekSpacePresence('space-1')

    expect(peek.getSpacePresence('space-1')?.count).toBe(1)
  })

  it('reports a failed request on the error ref', async () => {
    fetchMock.mockRejectedValue(new Error('offline'))

    const peek = useSpacePresencePeek()
    await peek.peekSpacePresence('space-1')

    expect(peek.error.value).toBeInstanceOf(Error)
    expect(peek.getSpacePresence('space-1')).toBeNull()
    expect(peek.isLoading.value).toBe(false)
  })

  it('clears a previous error on the next successful peek', async () => {
    fetchMock.mockRejectedValue(new Error('offline'))

    const peek = useSpacePresencePeek()
    await peek.peekSpacePresence('space-1')

    respondWith(() => ({ data: info('space-1') }))
    await peek.peekSpacePresence('space-1')

    expect(peek.error.value).toBeNull()
  })

  it('stores nothing when the endpoint answers with no data', async () => {
    respondWith(() => ({ data: null }))

    const peek = useSpacePresencePeek()
    await peek.peekSpacePresence('space-1')

    expect(peek.getSpacePresence('space-1')).toBeNull()
  })

  it('treats an error status as a failed peek', async () => {
    fetchMock.mockImplementation(
      async () => new Response('nope', { status: 403, statusText: 'Forbidden' })
    )

    const peek = useSpacePresencePeek()
    await peek.peekSpacePresence('space-1')

    expect(peek.getSpacePresence('space-1')).toBeNull()
  })
})

describe('peekMultipleSpaces', () => {
  it('stores an entry per space, keyed by the id asked for', async () => {
    respondWith((url) => ({ data: info(url.includes('space-1') ? 'space-1' : 'space-2') }))

    const peek = useSpacePresencePeek()
    await peek.peekMultipleSpaces(['space-1', 'space-2'])

    expect(peek.getSpacePresence('space-1')?.spaceId).toBe('space-1')
    expect(peek.getSpacePresence('space-2')?.spaceId).toBe('space-2')
  })

  it('keeps the surviving spaces when one request fails', async () => {
    fetchMock.mockImplementation(async (url: string) => {
      if (url.includes('space-1')) throw new Error('offline')
      return jsonResponse({ data: info('space-2', ['a', 'b']) })
    })

    const peek = useSpacePresencePeek()
    await peek.peekMultipleSpaces(['space-1', 'space-2'])

    // The results are zipped back onto the ids by index, so a hole must not
    // shift the surviving space onto the failed one's key.
    expect(peek.getSpacePresence('space-1')).toBeNull()
    expect(peek.getSpaceUsers('space-2')).toHaveLength(2)
    // A single failing space is logged rather than failing the whole batch.
    expect(consoleError).toHaveBeenCalled()
    expect(peek.error.value).toBeNull()
  })

  it('handles an empty list', async () => {
    const peek = useSpacePresencePeek()
    await peek.peekMultipleSpaces([])

    expect(fetchMock).not.toHaveBeenCalled()
    expect(peek.isLoading.value).toBe(false)
  })

  it('requests every space exactly once', async () => {
    respondWith((url) => ({ data: info(url) }))

    await useSpacePresencePeek().peekMultipleSpaces(['space-1', 'space-2', 'space-3'])

    expect(urlsCalled()).toEqual([
      '/mgmt/v1/spaces/space-1/presence',
      '/mgmt/v1/spaces/space-2/presence',
      '/mgmt/v1/spaces/space-3/presence',
    ])
  })
})

describe('reads before any peek', () => {
  it('reports no presence for an unknown space', () => {
    const peek = useSpacePresencePeek()

    expect(peek.getSpacePresence('space-x')).toBeNull()
    expect(peek.getSpaceUsers('space-x')).toEqual([])
    expect(peek.presenceData.value.size).toBe(0)
  })

  it('gives every caller its own store, unlike the clipboard composables', async () => {
    respondWith(() => ({ data: info('space-1') }))

    const first = useSpacePresencePeek()
    await first.peekSpacePresence('space-1')

    expect(useSpacePresencePeek().getSpacePresence('space-1')).toBeNull()
  })
})
