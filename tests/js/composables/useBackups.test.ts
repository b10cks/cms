import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({
  backups: { index, get, create, update, delete: destroy },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useBackups } = await import('~/composables/useBackups')

const SPACE = 'space-1'
const keys = queryKeys.backups(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useBackups>
type Mutations = {
  create: ReturnType<Composable['useCreateBackupMutation']>
  update: ReturnType<Composable['useUpdateBackupMutation']>
  remove: ReturnType<Composable['useDeleteBackupMutation']>
}

let harness: Harness<Mutations> | undefined

/**
 * useMutation needs an injection context too, so the mutations have to be built
 * inside setup() rather than pulled off the composable in the test body.
 */
const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const backups = useBackups(spaceId)
      return {
        create: backups.useCreateBackupMutation(),
        update: backups.useUpdateBackupMutation(),
        remove: backups.useDeleteBackupMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, success, error]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useBackupsQuery', () => {
  it('defaults to newest first', async () => {
    withSetup(() => useBackups(SPACE).useBackupsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('lets the caller params override the default sort', async () => {
    withSetup(() => useBackups(SPACE).useBackupsQuery({ sort: '+name' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
  })

  it('scopes the API client to the space', async () => {
    withSetup(() => useBackups(SPACE).useBackupsQuery())
    await flush()

    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'b1' }], meta: { total: 1 } })

    const query = withSetup(() => useBackups(SPACE).useBackupsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'b1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useBackups(SPACE).useBackupsQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useBackups('').useBackupsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = withSetup(() => useBackups(SPACE).useBackupsQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() => useBackups(SPACE).useBackupsQuery(params))

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })
})

describe('useBackupQuery', () => {
  it('unwraps the data envelope for a single backup', async () => {
    get.mockResolvedValue({ data: { id: 'b1', name: 'Nightly', state: 'done' } })

    const query = withSetup(() => useBackups(SPACE).useBackupQuery('b1')).result
    await flush()

    expect(query.data.value).toEqual({ id: 'b1', name: 'Nightly', state: 'done' })
    expect(get).toHaveBeenCalledWith('b1')
  })

  it('stays idle without an id', async () => {
    const query = withSetup(() => useBackups(SPACE).useBackupQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('polls every 2s while the backup is pending', async () => {
    const cached = withSetup(() => useBackups(SPACE).useBackupQuery('b1'), {
      seed: [[keys.detail('b1'), { id: 'b1', state: 'pending' }]],
    })
    await flush()

    const query = cached.queryClient.getQueryCache().find({ queryKey: keys.detail('b1') })
    const interval = ((query?.options ?? {}) as {
      refetchInterval?: (q: unknown) => number | false
    }).refetchInterval as (q: unknown) => number | false

    expect(interval({ state: { data: { id: 'b1', state: 'pending' } } })).toBe(2000)
    cached.unmount()
  })

  it.each([['done'], ['failed'], [undefined]])('stops polling in state %s', async (state) => {
    const cached = withSetup(() => useBackups(SPACE).useBackupQuery('b1'), {
      seed: [[keys.detail('b1'), { id: 'b1', state: 'done' }]],
    })
    await flush()

    const query = cached.queryClient.getQueryCache().find({ queryKey: keys.detail('b1') })
    const interval = ((query?.options ?? {}) as {
      refetchInterval?: (q: unknown) => number | false
    }).refetchInterval as (q: unknown) => number | false

    expect(interval({ state: { data: state ? { state } : undefined } })).toBe(false)
    cached.unmount()
  })
})

describe('useCreateBackupMutation', () => {
  it('invalidates the lists and names the backup in the toast', async () => {
    create.mockResolvedValue({ data: { id: 'b1', name: 'Nightly' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ name: 'Nightly' } as CreateBackupPayload)

    expect(create).toHaveBeenCalledWith({ name: 'Nightly' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Backup "Nightly" created successfully')
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('disk full'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync({} as CreateBackupPayload)).rejects.toThrow('disk full')
    expect(error).toHaveBeenCalledWith('Failed to create backup: disk full')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync({} as CreateBackupPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create backup: Unknown error')
  })
})

describe('useUpdateBackupMutation', () => {
  it('invalidates both the lists and that backup detail', async () => {
    update.mockResolvedValue({ data: { id: 'b1', name: 'Renamed' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'b1', payload: { name: 'Renamed' } as UpdateBackupPayload })

    expect(update).toHaveBeenCalledWith('b1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('b1') })
    expect(success).toHaveBeenCalledWith('Backup "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'x' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'b1', payload: {} as UpdateBackupPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('b1') })
  })
})

describe('useDeleteBackupMutation', () => {
  it('invalidates the lists', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('b1')

    expect(destroy).toHaveBeenCalledWith('b1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Backup deleted successfully')
  })

  it('drops the deleted backup detail, so a pending row stops polling a 404', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('b1'), { id: 'b1', state: 'pending' }]])

    await remove.mutateAsync('b1')

    expect(harness?.queryClient.getQueryData(keys.detail('b1'))).toBeUndefined()
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('nope'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('b1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete backup: nope')
  })
})

describe('space scoping', () => {
  it('keys backups per space, so two spaces never share a list', () => {
    expect(queryKeys.backups('a').lists()).not.toEqual(queryKeys.backups('b').lists())
  })

  it('invalidates only the current space on delete', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('b1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.backups('space-2').lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
