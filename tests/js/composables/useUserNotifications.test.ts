import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Ref } from 'vue'

import type { User } from '~/types/users'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const user: Ref<Pick<User, 'id'> | null> = ref(null)

vi.mock('~/composables/useAuth', () => ({
  useAuth: () => ({ user }),
}))

/** One handler per channel name, so a whisper can be replayed per channel. */
const notificationHandlers = new Map<string, () => void>()
const privateChannel = vi.fn((name: string) => ({
  notification: (handler: () => void) => {
    notificationHandlers.set(name, handler)
  },
}))
const leave = vi.fn()

const echo = { private: privateChannel, leave } as unknown as Window['Echo']

const { useUserNotifications } = await import('~/composables/useUserNotifications')

const CHANNEL = 'App.Models.User.u1'

let harness: Harness<void> | undefined

const setup = () => {
  harness = withSetup<void>(() => useUserNotifications())
  return harness
}

beforeEach(() => {
  notificationHandlers.clear()
  privateChannel.mockClear()
  leave.mockClear()
  user.value = { id: 'u1' }
  window.Echo = echo
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  delete (window as { Echo?: unknown }).Echo
})

describe('subscription', () => {
  it('subscribes to the authenticated user private channel on mount', () => {
    setup()

    expect(privateChannel).toHaveBeenCalledWith(CHANNEL)
  })

  it('does not subscribe when nobody is signed in', () => {
    user.value = null
    setup()

    expect(privateChannel).not.toHaveBeenCalled()
  })

  it('does not subscribe when the user has no id', () => {
    user.value = { id: '' }
    setup()

    expect(privateChannel).not.toHaveBeenCalled()
  })

  it('leaves the channel on unmount', () => {
    setup()
    harness?.unmount()
    harness = undefined

    expect(leave).toHaveBeenCalledWith(CHANNEL)
  })

  it('does nothing when Echo is not installed', () => {
    delete (window as { Echo?: unknown }).Echo

    expect(() => setup()).not.toThrow()
  })

  it('swallows an Echo that throws on subscribe', () => {
    window.Echo = {
      private: () => {
        throw new Error('no socket')
      },
      leave,
    } as unknown as Window['Echo']

    expect(() => setup()).not.toThrow()
  })
})

describe('incoming notifications', () => {
  it('invalidates the whole notifications namespace on a delivered notification', () => {
    setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    notificationHandlers.get(CHANNEL)?.()

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.notifications.all() })
  })

  it('invalidates once per delivered notification', () => {
    setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    notificationHandlers.get(CHANNEL)?.()
    notificationHandlers.get(CHANNEL)?.()

    expect(invalidate).toHaveBeenCalledTimes(2)
  })
})

describe('switching user', () => {
  it('leaves the old channel and joins the new one', async () => {
    setup()

    user.value = { id: 'u2' }
    await nextTick()

    expect(leave).toHaveBeenCalledWith(CHANNEL)
    expect(privateChannel).toHaveBeenLastCalledWith('App.Models.User.u2')
  })

  it('leaves without rejoining when the user signs out', async () => {
    setup()

    user.value = null
    await nextTick()

    expect(leave).toHaveBeenCalledWith(CHANNEL)
    expect(privateChannel).toHaveBeenCalledTimes(1)
  })

  it('joins without leaving when a signed-out session signs in', async () => {
    user.value = null
    setup()

    user.value = { id: 'u1' }
    await nextTick()

    expect(leave).not.toHaveBeenCalled()
    expect(privateChannel).toHaveBeenCalledWith(CHANNEL)
  })

  /**
   * The new channel's handler must be the live one: after a switch, a whisper on
   * the *old* channel would otherwise still refresh the cache.
   */
  it('routes later notifications through the new channel', async () => {
    setup()
    user.value = { id: 'u2' }
    await nextTick()

    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')
    notificationHandlers.get('App.Models.User.u2')?.()

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.notifications.all() })
  })

  // The unmount hook re-reads the *current* user, so after a switch it leaves
  // the new channel — correct here only because the watcher already left the old
  // one.
  it('leaves the current channel on unmount after a switch', async () => {
    setup()
    user.value = { id: 'u2' }
    await nextTick()
    leave.mockClear()

    harness?.unmount()
    harness = undefined

    expect(leave).toHaveBeenCalledWith('App.Models.User.u2')
  })
})
