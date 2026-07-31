import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, reactive, ref } from 'vue'

import { createPresenceController, presenceUser, type PresenceController } from '../support/presence'
import { withSetup, type Harness } from '../support/harness'

let presence: PresenceController

const route = reactive<{ params: Record<string, string | undefined> }>({ params: {} })

vi.mock('~/composables/usePresence', async () => {
  const actual = await vi.importActual<typeof import('~/composables/usePresence')>(
    '~/composables/usePresence'
  )

  return { ...actual, usePresence: () => presence }
})

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return { ...actual, useRoute: () => route }
})

const { useContentMenuPresence } = await import('~/composables/useContentMenuPresence')

const LOCATION_EVENT = 'content-location'
const LOCATION_REQUEST_EVENT = 'content-location-request'

let harness: Harness<ReturnType<typeof useContentMenuPresence>> | undefined

const setup = (spaceId = 'space-1') => {
  harness = withSetup(() => useContentMenuPresence(spaceId))
  return harness
}

const sentOf = (event: string) => presence.sent.filter((entry) => entry.event === event)

const peerAt = (userId: string, contentId: string | null) =>
  presence.fire(LOCATION_EVENT, { userId, contentId })

beforeEach(() => {
  presence = createPresenceController('me')
  route.params = {}
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  vi.useRealTimers()
})

describe('announcing this client', () => {
  it('whispers its location and asks for everyone else on connect', () => {
    setup()

    expect(sentOf(LOCATION_EVENT)).toEqual([
      { event: LOCATION_EVENT, payload: { userId: 'me', contentId: null } },
    ])
    expect(sentOf(LOCATION_REQUEST_EVENT)).toEqual([
      { event: LOCATION_REQUEST_EVENT, payload: { userId: 'me' } },
    ])
  })

  it('reports the content the route is on', () => {
    route.params = { contentId: 'content-9' }
    setup()

    expect(sentOf(LOCATION_EVENT)[0].payload).toEqual({ userId: 'me', contentId: 'content-9' })
  })

  it('re-announces on navigation', async () => {
    setup()
    route.params = { contentId: 'content-1' }
    await nextTick()

    expect(sentOf(LOCATION_EVENT).map((entry) => entry.payload)).toEqual([
      { userId: 'me', contentId: null },
      { userId: 'me', contentId: 'content-1' },
    ])
  })

  it('stays quiet while the channel is not connected', () => {
    presence.isConnected.value = false
    setup()

    expect(presence.sent).toEqual([])
  })

  it('announces as soon as the channel connects', async () => {
    presence.isConnected.value = false
    setup()

    presence.isConnected.value = true
    await nextTick()

    expect(sentOf(LOCATION_EVENT)).toHaveLength(1)
    expect(sentOf(LOCATION_REQUEST_EVENT)).toHaveLength(1)
  })

  it('drops a navigation announcement while disconnected', async () => {
    presence.isConnected.value = false
    setup()

    route.params = { contentId: 'content-1' }
    await nextTick()

    expect(sentOf(LOCATION_EVENT)).toEqual([])
  })

  it('says nothing without an identified user', () => {
    presence.currentUser.value = null
    setup()

    expect(presence.sent).toEqual([])
  })
})

describe('tracking peers', () => {
  it('records where a peer is editing', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    peerAt('peer', 'content-1')

    expect(result.getUsersForContent('content-1').map((user) => user.id)).toEqual(['peer'])
  })

  it('groups several peers on the same content', () => {
    presence.setUsers([presenceUser('me'), presenceUser('a'), presenceUser('b')])

    const { result } = setup()

    peerAt('a', 'content-1')
    peerAt('b', 'content-1')

    expect(result.presenceMap.value['content-1'].map((user) => user.id)).toEqual(['a', 'b'])
  })

  it('moves a peer that navigates to other content', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    peerAt('peer', 'content-1')
    peerAt('peer', 'content-2')

    expect(result.getUsersForContent('content-1')).toEqual([])
    expect(result.getUsersForContent('content-2').map((user) => user.id)).toEqual(['peer'])
  })

  it('forgets a peer that reports no content', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    peerAt('peer', 'content-1')
    peerAt('peer', null)

    expect(result.presenceMap.value).toEqual({})
  })

  it('never lists the current user', () => {
    presence.setUsers([presenceUser('me')])

    const { result } = setup()

    peerAt('me', 'content-1')

    expect(result.presenceMap.value).toEqual({})
  })

  it('ignores an empty payload', () => {
    const { result } = setup()

    expect(() => presence.fire(LOCATION_EVENT, null)).not.toThrow()
    expect(result.presenceMap.value).toEqual({})
  })

  it('omits a peer whose location is known but who is not on the channel', () => {
    presence.setUsers([presenceUser('me')])

    const { result } = setup()

    peerAt('ghost', 'content-1')

    expect(result.getUsersForContent('content-1')).toEqual([])
  })

  it('prunes locations when a member leaves the channel', async () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    peerAt('peer', 'content-1')
    presence.setUsers([presenceUser('me')])
    await nextTick()

    expect(result.presenceMap.value).toEqual({})
  })

  it('returns an empty list for content nobody is on', () => {
    expect(setup().result.getUsersForContent('content-x')).toEqual([])
  })
})

describe('answering location requests', () => {
  it('replies with its own location after a short jittered delay', () => {
    vi.useFakeTimers()
    route.params = { contentId: 'content-1' }

    setup()
    presence.sent.length = 0

    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'joiner' })
    expect(sentOf(LOCATION_EVENT)).toEqual([])

    vi.advanceTimersByTime(300)

    expect(sentOf(LOCATION_EVENT)).toEqual([
      { event: LOCATION_EVENT, payload: { userId: 'me', contentId: 'content-1' } },
    ])
  })

  it('stays silent when it is not on any content', () => {
    vi.useFakeTimers()

    setup()
    presence.sent.length = 0

    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'joiner' })
    vi.advanceTimersByTime(300)

    expect(sentOf(LOCATION_EVENT)).toEqual([])
  })

  it('ignores its own request echoed back', () => {
    vi.useFakeTimers()
    route.params = { contentId: 'content-1' }

    setup()
    presence.sent.length = 0

    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'me' })
    vi.advanceTimersByTime(300)

    expect(sentOf(LOCATION_EVENT)).toEqual([])
  })

  it('ignores an empty request payload', () => {
    vi.useFakeTimers()
    route.params = { contentId: 'content-1' }

    setup()
    presence.sent.length = 0

    presence.fire(LOCATION_REQUEST_EVENT, null)
    vi.advanceTimersByTime(300)

    expect(sentOf(LOCATION_EVENT)).toEqual([])
  })

  it('collapses repeated requests from one peer into a single reply', () => {
    vi.useFakeTimers()
    route.params = { contentId: 'content-1' }

    setup()
    presence.sent.length = 0

    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'joiner' })
    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'joiner' })
    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'joiner' })
    vi.advanceTimersByTime(300)

    expect(sentOf(LOCATION_EVENT)).toHaveLength(1)
  })

  it('replies once per requesting peer', () => {
    vi.useFakeTimers()
    route.params = { contentId: 'content-1' }

    setup()
    presence.sent.length = 0

    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'a' })
    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'b' })
    vi.advanceTimersByTime(300)

    expect(sentOf(LOCATION_EVENT)).toHaveLength(2)
  })
})

describe('teardown', () => {
  it('stops listening for peer locations', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    harness?.unmount()
    harness = undefined
    peerAt('peer', 'content-1')

    expect(result.presenceMap.value).toEqual({})
  })

  it('cancels a pending reply', () => {
    vi.useFakeTimers()
    route.params = { contentId: 'content-1' }

    setup()
    presence.fire(LOCATION_REQUEST_EVENT, { userId: 'joiner' })
    presence.sent.length = 0

    harness?.unmount()
    harness = undefined
    vi.advanceTimersByTime(300)

    expect(presence.sent).toEqual([])
  })
})

describe('space id', () => {
  it('accepts a ref space id', () => {
    const spaceId = ref('space-1')
    harness = withSetup(() => useContentMenuPresence(spaceId))

    expect(harness.result.presenceMap.value).toEqual({})
  })
})
