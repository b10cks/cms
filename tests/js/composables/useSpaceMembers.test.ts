import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const list = vi.fn()
const update = vi.fn()
const remove = vi.fn()
const forSpace = vi.fn(() => ({ members: { list, update, remove } }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const isAuthenticated = ref(true)
vi.mock('~/composables/useAuth', () => ({ useAuth: () => ({ isAuthenticated }) }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useSpaceMembers } = await import('~/composables/useSpaceMembers')

const SPACE = 'space-1'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useSpaceMembers>
type Mutations = {
  updateMember: ReturnType<Composable['useUpdateSpaceMemberMutation']>
  removeMember: ReturnType<Composable['useRemoveSpaceMemberMutation']>
}

let harness: Harness<Mutations> | undefined
let queryHarness: Harness<ReturnType<Composable['useSpaceMembersQuery']>> | undefined

const setupMutations = () => {
  harness = withSetup<Mutations>(() => {
    const members = useSpaceMembers()
    return {
      updateMember: members.useUpdateSpaceMemberMutation(),
      removeMember: members.useRemoveSpaceMemberMutation(),
    }
  })
  return harness.result
}

const setupQuery = (...args: Parameters<Composable['useSpaceMembersQuery']>) => {
  queryHarness = withSetup(() => useSpaceMembers().useSpaceMembersQuery(...args))
  return queryHarness.result
}

beforeEach(() => {
  for (const fn of [list, update, remove, success, error]) fn.mockReset()
  forSpace.mockClear()
  isAuthenticated.value = true
  list.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  queryHarness?.unmount()
  harness = undefined
  queryHarness = undefined
})

describe('useSpaceMembersQuery', () => {
  it('sorts by first name by default', async () => {
    setupQuery(SPACE)
    await flush()

    expect(list).toHaveBeenCalledWith({ sort: '+firstname' })
  })

  it('lets the caller override the sort', async () => {
    setupQuery(SPACE, { sort: '-lastname' } as SpaceMemberQueryParams)
    await flush()

    expect(list).toHaveBeenCalledWith({ sort: '-lastname' })
  })

  it('returns the envelope untouched', async () => {
    list.mockResolvedValue({ data: [{ id: 'u1' }], meta: { total: 1 } })

    const query = setupQuery(SPACE)
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'u1' }], meta: { total: 1 } })
  })

  it('caches under the space members list key', async () => {
    setupQuery(SPACE, { page: 2 } as SpaceMemberQueryParams)
    await flush()

    expect(
      queryHarness?.queryClient.getQueryData(queryKeys.spaceMembers(SPACE).list({ page: 2 }))
    ).toBeDefined()
  })

  it('stays idle while logged out', async () => {
    isAuthenticated.value = false

    const query = setupQuery(SPACE)
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(list).not.toHaveBeenCalled()
  })

  it('stays idle without a space id', async () => {
    expect(setupQuery('').fetchStatus.value).toBe('idle')
  })

  it('stays idle when disabled', async () => {
    expect(setupQuery(SPACE, {}, false).fetchStatus.value).toBe('idle')
  })
})

describe('useUpdateSpaceMemberMutation', () => {
  it('sends the role change for that member', async () => {
    update.mockResolvedValue(undefined)

    await setupMutations().updateMember.mutateAsync({
      spaceId: SPACE,
      userId: 'u1',
      payload: { role: 'editor' } as UpdateSpaceMemberPayload,
    })

    expect(forSpace).toHaveBeenCalledWith(SPACE)
    expect(update).toHaveBeenCalledWith('u1', { role: 'editor' })
  })

  it('invalidates both the members list and the people list', async () => {
    update.mockResolvedValue(undefined)
    const mutations = setupMutations()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateMember.mutateAsync({
      spaceId: SPACE,
      userId: 'u1',
      payload: { role: 'editor' } as UpdateSpaceMemberPayload,
    })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaceMembers(SPACE).lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spacePeople(SPACE).lists() })
  })

  it('names the new role in the toast', async () => {
    update.mockResolvedValue(undefined)

    await setupMutations().updateMember.mutateAsync({
      spaceId: SPACE,
      userId: 'u1',
      payload: { role: 'admin' } as UpdateSpaceMemberPayload,
    })

    expect(success).toHaveBeenCalledWith('Member role updated to admin')
  })

  it('uses its own message when the payload clears the role', async () => {
    update.mockResolvedValue(undefined)

    await setupMutations().updateMember.mutateAsync({
      spaceId: SPACE,
      userId: 'u1',
      payload: {} as UpdateSpaceMemberPayload,
    })

    expect(success).toHaveBeenCalledWith('Member role cleared')
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('cannot demote the last owner'))
    const mutations = setupMutations()

    await mutations.updateMember
      .mutateAsync({
        spaceId: SPACE,
        userId: 'u1',
        payload: { role: 'editor' } as UpdateSpaceMemberPayload,
      })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith(
      'Failed to update member role: cannot demote the last owner'
    )
    expect(success).not.toHaveBeenCalled()
  })

  it('does not invalidate when the update fails', async () => {
    update.mockRejectedValue(new Error('nope'))
    const mutations = setupMutations()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateMember
      .mutateAsync({ spaceId: SPACE, userId: 'u1', payload: {} as UpdateSpaceMemberPayload })
      .catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('useRemoveSpaceMemberMutation', () => {
  it('removes the member and invalidates both lists', async () => {
    remove.mockResolvedValue(undefined)
    const mutations = setupMutations()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.removeMember.mutateAsync({ spaceId: SPACE, userId: 'u1' })

    expect(remove).toHaveBeenCalledWith('u1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaceMembers(SPACE).lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spacePeople(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Member removed from space')
  })

  it('does not touch the space detail or the authorization context', async () => {
    remove.mockResolvedValue(undefined)
    const mutations = setupMutations()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.removeMember.mutateAsync({ spaceId: SPACE, userId: 'u1' })

    // Removing yourself leaves the cached permission context in place.
    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
  })

  it('surfaces a rejected removal, e.g. the last owner', async () => {
    remove.mockRejectedValue(new Error('last owner'))
    const mutations = setupMutations()

    await expect(
      mutations.removeMember.mutateAsync({ spaceId: SPACE, userId: 'u1' })
    ).rejects.toThrow('last owner')
    expect(error).toHaveBeenCalledWith('Failed to remove member from space: last owner')
  })

  it('invalidates only the space it was told about', async () => {
    remove.mockResolvedValue(undefined)
    const mutations = setupMutations()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.removeMember.mutateAsync({ spaceId: 'space-2', userId: 'u1' })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaceMembers('space-2').lists() })
    expect(invalidate).not.toHaveBeenCalledWith({
      queryKey: queryKeys.spaceMembers(SPACE).lists(),
    })
  })
})

describe('query keys', () => {
  it('lists() prefixes list(filters), so the mutations really match the cached pages', () => {
    const keys = queryKeys.spaceMembers(SPACE)
    const page = keys.list({ page: 5 })

    expect(page.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
