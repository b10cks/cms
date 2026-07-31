import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const list = vi.fn()
const unreadCount = vi.fn()
const markAsRead = vi.fn()
const markAsUnread = vi.fn()
const markAllAsRead = vi.fn()
const remove = vi.fn()
const removeAll = vi.fn()

vi.mock('~/api', () => ({
  api: {
    notifications: {
      list,
      unreadCount,
      markAsRead,
      markAsUnread,
      markAllAsRead,
      remove,
      removeAll,
    },
  },
}))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useNotifications } = await import('~/composables/useNotifications')

const keys = queryKeys.notifications

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useNotifications>
type Mutations = {
  read: ReturnType<Composable['useMarkAsReadMutation']>
  unread: ReturnType<Composable['useMarkAsUnreadMutation']>
  allRead: ReturnType<Composable['useMarkAllAsReadMutation']>
  remove: ReturnType<Composable['useDeleteNotificationMutation']>
  clearAll: ReturnType<Composable['useClearAllMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const notifications = useNotifications()
      return {
        read: notifications.useMarkAsReadMutation(),
        unread: notifications.useMarkAsUnreadMutation(),
        allRead: notifications.useMarkAllAsReadMutation(),
        remove: notifications.useDeleteNotificationMutation(),
        clearAll: notifications.useClearAllMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [
    list,
    unreadCount,
    markAsRead,
    markAsUnread,
    markAllAsRead,
    remove,
    removeAll,
    success,
    error,
  ]) {
    fn.mockReset()
  }
  list.mockResolvedValue({ data: [] })
  unreadCount.mockResolvedValue({ count: 0 })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useNotificationsQuery', () => {
  it('sends the caller params verbatim — there is no default filter', async () => {
    withSetup(() => useNotifications().useNotificationsQuery())
    await flush()

    expect(list).toHaveBeenCalledWith({})
  })

  it('forwards the filters it was given', async () => {
    withSetup(() => useNotifications().useNotificationsQuery({ unread_only: true, page: 2 }))
    await flush()

    expect(list).toHaveBeenCalledWith({ unread_only: true, page: 2 })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    list.mockResolvedValue({ data: [{ id: 'n1' }], meta: { total: 1 } })

    const query = withSetup(() => useNotifications().useNotificationsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'n1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useNotifications().useNotificationsQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() => useNotifications().useNotificationsQuery(params))

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  // Notifications are not space-scoped: the key has no space segment, so the
  // badge survives a space switch.
  it('is keyed globally, without a space segment', () => {
    expect(keys.all()).toEqual(['notifications'])
  })
})

describe('useUnreadCountQuery', () => {
  it('unwraps the count out of the response', async () => {
    unreadCount.mockResolvedValue({ count: 7 })

    const query = withSetup(() => useNotifications().useUnreadCountQuery()).result
    await flush()

    expect(query.data.value).toBe(7)
  })

  it('keeps a zero count rather than treating it as missing', async () => {
    unreadCount.mockResolvedValue({ count: 0 })

    const query = withSetup(() => useNotifications().useUnreadCountQuery()).result
    await flush()

    expect(query.data.value).toBe(0)
    expect(query.status.value).toBe('success')
  })

  it('caches under a key the lists() invalidation does not match', () => {
    expect(keys.unreadCount()).toEqual(['notifications', 'unread-count'])
    expect(keys.unreadCount().slice(0, keys.lists().length)).not.toEqual([...keys.lists()])
  })
})

describe('mutations', () => {
  /**
   * Every mutation invalidates the whole `notifications` namespace rather than
   * a narrower key, so the list *and* the unread badge always refresh together.
   */
  const cases = [
    ['read', markAsRead, (m: Mutations) => m.read.mutateAsync('n1'), 'n1'],
    ['unread', markAsUnread, (m: Mutations) => m.unread.mutateAsync('n1'), 'n1'],
    ['allRead', markAllAsRead, (m: Mutations) => m.allRead.mutateAsync(undefined), undefined],
    ['remove', remove, (m: Mutations) => m.remove.mutateAsync('n1'), 'n1'],
    ['clearAll', removeAll, (m: Mutations) => m.clearAll.mutateAsync(undefined), undefined],
  ] as const

  it.each(cases)('%s invalidates the whole notifications namespace', async (_name, _fn, run) => {
    _fn.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await run(mutations)

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.all() })
  })

  it.each(cases)('%s calls its endpoint with the expected argument', async (_name, fn, run, arg) => {
    fn.mockResolvedValue(undefined)
    const mutations = setup()

    await run(mutations)

    if (arg === undefined) {
      expect(fn).toHaveBeenCalledWith()
    } else {
      expect(fn).toHaveBeenCalledWith(arg)
    }
  })

  it.each(cases)('%s shows one generic toast on failure', async (_name, fn, run) => {
    fn.mockRejectedValue(new Error('server exploded'))
    const mutations = setup()

    await run(mutations).catch(() => {})

    // The reason is deliberately swallowed — every failure reads the same.
    expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.')
    expect(error).toHaveBeenCalledTimes(1)
  })

  it.each(cases)('%s does not invalidate when it fails', async (_name, fn, run) => {
    fn.mockRejectedValue(new Error('nope'))
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await run(mutations).catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
  })

  it.each(cases)('%s shows no success toast', async (_name, fn, run) => {
    fn.mockResolvedValue(undefined)
    const mutations = setup()

    await run(mutations)

    expect(success).not.toHaveBeenCalled()
  })

  it('the namespace invalidation matches both the lists and the unread count', async () => {
    markAsRead.mockResolvedValue(undefined)
    const mutations = setup([
      [keys.list({}), { data: [] }],
      [keys.unreadCount(), 3],
    ])

    await mutations.read.mutateAsync('n1')

    const invalidated = harness!.queryClient
      .getQueryCache()
      .findAll({ queryKey: keys.all() })
      .map((query) => query.queryKey)

    expect(invalidated).toContainEqual([...keys.list({})])
    expect(invalidated).toContainEqual([...keys.unreadCount()])
  })
})
