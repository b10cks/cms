import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import type { PresenceUser } from '~/composables/usePresence'

import {
  useContentPresence,
  usePresence,
  useSpacePresence,
} from '~/composables/usePresence'

import { presenceUser } from '../support/presence'
import { withSetup, type Harness } from '../support/harness'

interface FakeChannel {
  name: string
  whispers: Array<{ event: string; payload: unknown }>
  whisperListeners: Array<{ event: string; callback: (payload: unknown) => void }>
  fireHere: (members: PresenceUser[]) => void
  fireJoining: (member: PresenceUser) => void
  fireLeaving: (member: PresenceUser) => void
  fireError: (error: Error) => void
}

/**
 * `useEcho()` is a one-liner over `window.Echo`, so the honest transport seam
 * is the global itself — no module mocking, and the composable's own
 * try/catch/null handling stays under test.
 */
const createFakeEcho = () => {
  const joined: string[] = []
  const left: string[] = []
  const channels: FakeChannel[] = []

  const createChannel = (name: string): FakeChannel => {
    const handlers: Record<string, ((payload: never) => void) | undefined> = {}
    const channel = {
      name,
      whispers: [] as Array<{ event: string; payload: unknown }>,
      whisperListeners: [] as Array<{ event: string; callback: (payload: unknown) => void }>,
      here: (callback: (members: PresenceUser[]) => void) => {
        handlers.here = callback as (payload: never) => void
        return channel
      },
      joining: (callback: (member: PresenceUser) => void) => {
        handlers.joining = callback as (payload: never) => void
        return channel
      },
      leaving: (callback: (member: PresenceUser) => void) => {
        handlers.leaving = callback as (payload: never) => void
        return channel
      },
      error: (callback: (error: Error) => void) => {
        handlers.error = callback as (payload: never) => void
        return channel
      },
      whisper: (event: string, payload: unknown) => {
        channel.whispers.push({ event, payload })
      },
      listenForWhisper: (event: string, callback: (payload: unknown) => void) => {
        channel.whisperListeners.push({ event, callback })
        return channel
      },
      stopListeningForWhisper: (event: string, callback: (payload: unknown) => void) => {
        channel.whisperListeners = channel.whisperListeners.filter(
          (entry) => entry.event !== event || entry.callback !== callback
        )
        return channel
      },
      fireHere: (members: PresenceUser[]) =>
        (handlers.here as ((members: PresenceUser[]) => void) | undefined)?.(members),
      fireJoining: (member: PresenceUser) =>
        (handlers.joining as ((member: PresenceUser) => void) | undefined)?.(member),
      fireLeaving: (member: PresenceUser) =>
        (handlers.leaving as ((member: PresenceUser) => void) | undefined)?.(member),
      fireError: (error: Error) =>
        (handlers.error as ((error: Error) => void) | undefined)?.(error),
    }

    return channel as unknown as FakeChannel & typeof channel
  }

  const echo = {
    join: (name: string) => {
      joined.push(name)
      const channel = createChannel(name)
      channels.push(channel)
      return channel
    },
    leave: (name: string) => {
      left.push(name)
    },
  }

  return { echo, joined, left, channels }
}

type FakeEcho = ReturnType<typeof createFakeEcho>

const installEcho = (echo: FakeEcho['echo']) => {
  window.Echo = echo as unknown as typeof window.Echo
}

const uninstallEcho = () => {
  Reflect.deleteProperty(window, 'Echo')
}

let fake: FakeEcho
let harness: Harness<ReturnType<typeof usePresence>> | undefined

const lastChannel = () => fake.channels[fake.channels.length - 1]

const setup = (
  channel: Parameters<typeof usePresence>[0],
  options: Parameters<typeof usePresence>[1] = {}
) => {
  harness = withSetup(() => usePresence(channel, options))
  return harness
}

beforeEach(() => {
  fake = createFakeEcho()
  installEcho(fake.echo)
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  uninstallEcho()
  vi.useRealTimers()
})

describe('connecting', () => {
  it('joins the channel named on mount', () => {
    setup(ref('presence-spaces.s1'))

    expect(fake.joined).toEqual(['presence-spaces.s1'])
  })

  it('reports connected once the channel answers with its members', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    expect(result.isConnecting.value).toBe(true)
    expect(result.isConnected.value).toBe(false)

    lastChannel().fireHere([presenceUser('me'), presenceUser('peer')])

    expect(result.isConnected.value).toBe(true)
    expect(result.isConnecting.value).toBe(false)
    expect(result.users.value.map((user) => user.id)).toEqual(['me', 'peer'])
    expect(result.count.value).toBe(2)
  })

  it('adds a joining member', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    lastChannel().fireHere([presenceUser('me')])
    lastChannel().fireJoining(presenceUser('peer'))

    expect(result.users.value.map((user) => user.id)).toEqual(['me', 'peer'])
  })

  it('ignores a joining member it already knows', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    lastChannel().fireHere([presenceUser('peer')])
    lastChannel().fireJoining(presenceUser('peer'))

    expect(result.users.value).toHaveLength(1)
  })

  it('removes a leaving member', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    lastChannel().fireHere([presenceUser('me'), presenceUser('peer')])
    lastChannel().fireLeaving(presenceUser('peer'))

    expect(result.users.value.map((user) => user.id)).toEqual(['me'])
  })

  it('does not join when there is no channel name', () => {
    const { result } = setup(ref(null))

    expect(fake.joined).toEqual([])
    expect(result.error.value).toBeNull()
  })

  it('reports an error instead of joining when Echo is not installed', () => {
    uninstallEcho()

    const { result } = setup(ref('presence-spaces.s1'))

    expect(result.error.value?.message).toBe('Echo not initialized')
    expect(result.isConnecting.value).toBe(false)
  })

  it('degrades gracefully when reading the Echo global throws', () => {
    uninstallEcho()
    Object.defineProperty(window, 'Echo', {
      configurable: true,
      get: () => {
        throw new Error('Echo blew up')
      },
    })

    const { result } = setup(ref('presence-spaces.s1'))

    expect(result.error.value?.message).toBe('Echo not initialized')
  })

  it('captures a join failure as the error state', () => {
    fake.echo.join = () => {
      throw new Error('join refused')
    }

    const { result } = setup(ref('presence-spaces.s1'))

    expect(result.error.value?.message).toBe('join refused')
    expect(result.isConnecting.value).toBe(false)
  })

  it('records a channel error and drops the connected flag', () => {
    vi.useFakeTimers()

    const { result } = setup(ref('presence-spaces.s1'))

    lastChannel().fireHere([presenceUser('me')])
    lastChannel().fireError(new Error('channel error'))

    expect(result.error.value?.message).toBe('channel error')
    expect(result.isConnected.value).toBe(false)
  })
})

describe('reconnecting', () => {
  it('rejoins after the configured delay', () => {
    vi.useFakeTimers()

    setup(ref('presence-spaces.s1'), { reconnectDelay: 1000 })
    lastChannel().fireError(new Error('boom'))

    vi.advanceTimersByTime(999)
    expect(fake.joined).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(fake.joined).toEqual(['presence-spaces.s1', 'presence-spaces.s1'])
    // The rejoin leaves the old subscription first.
    expect(fake.left).toEqual(['presence-spaces.s1'])
  })

  it('coalesces a burst of errors into a single reconnect', () => {
    vi.useFakeTimers()

    setup(ref('presence-spaces.s1'), { reconnectDelay: 1000, maxReconnectAttempts: 5 })
    const channel = lastChannel()

    channel.fireError(new Error('1'))
    channel.fireError(new Error('2'))
    channel.fireError(new Error('3'))

    vi.advanceTimersByTime(10_000)

    // Only one reconnect may be in flight, so N errors cannot leave N pending
    // timers firing N rejoins.
    expect(fake.joined).toHaveLength(2)
  })

  it('enforces the cap across reconnects, so a dead server stops being rejoined', () => {
    vi.useFakeTimers()

    setup(ref('presence-spaces.s1'), { reconnectDelay: 1000, maxReconnectAttempts: 1 })

    for (let attempt = 0; attempt < 4; attempt++) {
      lastChannel().fireError(new Error('boom'))
      vi.advanceTimersByTime(1000)
    }

    // The counter survives the disconnect() the reconnect timer performs, so
    // one attempt is one attempt in total — not one per error burst.
    expect(fake.joined).toHaveLength(2)
  })

  it('spends the full budget when the channel keeps failing', () => {
    vi.useFakeTimers()

    setup(ref('presence-spaces.s1'), { reconnectDelay: 1000, maxReconnectAttempts: 3 })

    for (let attempt = 0; attempt < 6; attempt++) {
      lastChannel().fireError(new Error('boom'))
      vi.advanceTimersByTime(1000)
    }

    expect(fake.joined).toHaveLength(4)
  })

  it('starts the reconnect budget over once the channel answers', () => {
    vi.useFakeTimers()

    setup(ref('presence-spaces.s1'), { reconnectDelay: 1000, maxReconnectAttempts: 1 })

    lastChannel().fireError(new Error('boom'))
    vi.advanceTimersByTime(1000)
    // A successful subscription — not disconnect() — is what clears the counter.
    lastChannel().fireHere([presenceUser('me')])
    lastChannel().fireError(new Error('again'))
    vi.advanceTimersByTime(1000)

    expect(fake.joined).toHaveLength(3)
  })

  it('starts the reconnect budget over on an explicit refresh', () => {
    vi.useFakeTimers()

    const { result } = setup(ref('presence-spaces.s1'), {
      reconnectDelay: 1000,
      maxReconnectAttempts: 1,
    })

    lastChannel().fireError(new Error('boom'))
    vi.advanceTimersByTime(1000)
    result.refresh()
    lastChannel().fireError(new Error('again'))
    vi.advanceTimersByTime(1000)

    expect(fake.joined).toHaveLength(4)
  })

  it('stops whispering into the channel that errored', () => {
    vi.useFakeTimers()

    const { result } = setup(ref('presence-spaces.s1'), { reconnectDelay: 1000 })
    const dead = lastChannel()

    dead.fireError(new Error('boom'))
    result.whisper('ping', { at: 1 })

    // Until the reconnect lands there is nowhere to send: writing into the dead
    // channel object would silently drop the whisper anyway.
    expect(dead.whispers).toEqual([])
  })

  it('still leaves the errored channel when reconnecting', () => {
    vi.useFakeTimers()

    setup(ref('presence-spaces.s1'), { reconnectDelay: 1000 })
    lastChannel().fireError(new Error('boom'))
    vi.advanceTimersByTime(1000)

    expect(fake.left).toEqual(['presence-spaces.s1'])
  })

  it('drops a pending reconnect when the caller disconnects', () => {
    vi.useFakeTimers()

    const { result } = setup(ref('presence-spaces.s1'), { reconnectDelay: 1000 })

    lastChannel().fireError(new Error('boom'))
    result.disconnect()
    vi.advanceTimersByTime(10_000)

    expect(fake.joined).toHaveLength(1)
  })

  it('rejoins on refresh', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    lastChannel().fireHere([presenceUser('me')])
    result.refresh()

    expect(fake.left).toEqual(['presence-spaces.s1'])
    expect(fake.joined).toHaveLength(2)
    expect(result.users.value).toEqual([])
  })
})

describe('whispers', () => {
  it('forwards an outgoing whisper to the joined channel', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    result.whisper('ping', { at: 1 })

    expect(lastChannel().whispers).toEqual([{ event: 'ping', payload: { at: 1 } }])
  })

  it('silently drops a whisper when there is no channel', () => {
    const { result } = setup(ref(null))

    expect(() => result.whisper('ping', {})).not.toThrow()
  })

  it('registers an incoming listener on the joined channel', () => {
    const received: unknown[] = []
    const { result } = setup(ref('presence-spaces.s1'))

    result.onWhisper('ping', (payload) => received.push(payload))
    lastChannel().whisperListeners.forEach((entry) => entry.callback({ at: 1 }))

    expect(received).toEqual([{ at: 1 }])
  })

  it('replays listeners registered before a channel existed', async () => {
    const channelName = ref<string | null>(null)
    const { result } = setup(channelName)

    result.onWhisper('ping', () => {})
    result.onWhisper('pong', () => {})

    channelName.value = 'presence-spaces.s1'
    await nextTick()

    expect(lastChannel().whisperListeners.map((entry) => entry.event)).toEqual(['ping', 'pong'])
  })

  it('replays listeners onto the channel created by a reconnect', () => {
    vi.useFakeTimers()

    const { result } = setup(ref('presence-spaces.s1'), { reconnectDelay: 1000 })

    result.onWhisper('ping', () => {})
    lastChannel().fireError(new Error('boom'))
    vi.advanceTimersByTime(1000)

    expect(fake.channels).toHaveLength(2)
    expect(lastChannel().whisperListeners.map((entry) => entry.event)).toEqual(['ping'])
  })

  it('stops replaying a listener that has been unsubscribed', async () => {
    const channelName = ref<string | null>(null)
    const { result } = setup(channelName)

    const stop = result.onWhisper('ping', () => {})
    stop()

    channelName.value = 'presence-spaces.s1'
    await nextTick()

    expect(lastChannel().whisperListeners).toEqual([])
  })

  it('detaches an unsubscribed listener from the live channel', () => {
    const received: unknown[] = []
    const { result } = setup(ref('presence-spaces.s1'))

    const stop = result.onWhisper('ping', (payload) => received.push(payload))
    stop()
    lastChannel().whisperListeners.forEach((entry) => entry.callback({ at: 1 }))

    expect(lastChannel().whisperListeners).toEqual([])
    expect(received).toEqual([])
  })

  it('does not deliver twice after an unsubscribe and resubscribe', () => {
    const received: unknown[] = []
    const { result } = setup(ref('presence-spaces.s1'))

    result.onWhisper('ping', (payload) => received.push(payload))()
    result.onWhisper('ping', (payload) => received.push(payload))
    lastChannel().whisperListeners.forEach((entry) => entry.callback({ at: 1 }))

    // The realistic trigger: a consumer remounting on the same channel.
    expect(received).toEqual([{ at: 1 }])
  })

  it('leaves other listeners for the same event attached', () => {
    const received: string[] = []
    const { result } = setup(ref('presence-spaces.s1'))

    const stop = result.onWhisper('ping', () => received.push('first'))
    result.onWhisper('ping', () => received.push('second'))
    stop()
    lastChannel().whisperListeners.forEach((entry) => entry.callback({}))

    expect(received).toEqual(['second'])
  })
})

describe('switching channels', () => {
  it('leaves the OLD channel, not the new one', async () => {
    const channelName = ref<string | null>('presence-spaces.s1')
    setup(channelName)

    channelName.value = 'presence-spaces.s2'
    await nextTick()

    expect(fake.left).toEqual(['presence-spaces.s1'])
    expect(fake.joined).toEqual(['presence-spaces.s1', 'presence-spaces.s2'])
  })

  it('leaves without rejoining when the name becomes null', async () => {
    const channelName = ref<string | null>('presence-spaces.s1')
    const { result } = setup(channelName)

    lastChannel().fireHere([presenceUser('me')])
    channelName.value = null
    await nextTick()

    expect(fake.left).toEqual(['presence-spaces.s1'])
    expect(fake.joined).toHaveLength(1)
    expect(result.users.value).toEqual([])
  })

  it('joins when a name arrives after mounting without one', async () => {
    const channelName = ref<string | null>(null)
    setup(channelName)

    channelName.value = 'presence-spaces.s1'
    await nextTick()

    expect(fake.joined).toEqual(['presence-spaces.s1'])
    expect(fake.left).toEqual([])
  })
})

describe('teardown', () => {
  it('leaves the channel and clears state on unmount', () => {
    const { result } = setup(ref('presence-spaces.s1'))

    lastChannel().fireHere([presenceUser('me')])
    harness?.unmount()

    expect(fake.left).toEqual(['presence-spaces.s1'])
    expect(result.users.value).toEqual([])
    expect(result.isConnected.value).toBe(false)
  })

  it('does not attempt to leave when nothing was ever joined', () => {
    setup(ref(null))
    harness?.unmount()

    expect(fake.left).toEqual([])
  })

  it('swallows an Echo that disappears before teardown', () => {
    setup(ref('presence-spaces.s1'))
    uninstallEcho()

    expect(() => harness?.unmount()).not.toThrow()
  })
})

describe('useSpacePresence', () => {
  let spaceHarness: Harness<ReturnType<typeof useSpacePresence>> | undefined

  afterEach(() => {
    spaceHarness?.unmount()
    spaceHarness = undefined
  })

  it('joins the space presence channel', () => {
    spaceHarness = withSetup(() => useSpacePresence('space-1'))

    expect(fake.joined).toEqual(['presence-spaces.space-1'])
  })

  it('joins nothing without a space id', () => {
    spaceHarness = withSetup(() => useSpacePresence(ref(null)))

    expect(fake.joined).toEqual([])
  })

  it('follows the space id to a new channel', async () => {
    const spaceId = ref<string | null>('space-1')
    spaceHarness = withSetup(() => useSpacePresence(spaceId))

    spaceId.value = 'space-2'
    await nextTick()

    expect(fake.left).toEqual(['presence-spaces.space-1'])
    expect(fake.joined).toEqual(['presence-spaces.space-1', 'presence-spaces.space-2'])
  })
})

describe('useContentPresence', () => {
  let contentHarness: Harness<ReturnType<typeof useContentPresence>> | undefined

  afterEach(() => {
    contentHarness?.unmount()
    contentHarness = undefined
  })

  it('joins the per-content presence channel', () => {
    contentHarness = withSetup(() => useContentPresence('space-1', 'content-1'))

    expect(fake.joined).toEqual(['presence-spaces.space-1.content.content-1'])
  })

  it('waits for both ids before joining', async () => {
    const contentId = ref<string | null>(null)
    contentHarness = withSetup(() => useContentPresence('space-1', contentId))

    expect(fake.joined).toEqual([])

    contentId.value = 'content-1'
    await nextTick()

    expect(fake.joined).toEqual(['presence-spaces.space-1.content.content-1'])
  })
})
