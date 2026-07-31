import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({ migrations: { index, get, create, delete: destroy } }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useMigrations } = await import('~/composables/useMigrations')

const SPACE = 'space-1'
const keys = queryKeys.migrations(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useMigrations>
type Mutations = {
  create: ReturnType<Composable['useCreateMigrationMutation']>
  remove: ReturnType<Composable['useDeleteMigrationMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE) => {
  harness = withSetup<Mutations>(() => {
    const migrations = useMigrations(spaceId)
    return {
      create: migrations.useCreateMigrationMutation(),
      remove: migrations.useDeleteMigrationMutation(),
    }
  })
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, get, create, destroy, success, error]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useMigrationsQuery', () => {
  it('defaults to newest first', async () => {
    withSetup(() => useMigrations(SPACE).useMigrationsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: 'created_at', order: 'desc' })
  })

  it('lets caller params override the default order', async () => {
    withSetup(() => useMigrations(SPACE).useMigrationsQuery({ order: 'asc' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: 'created_at', order: 'asc' })
  })

  it('returns the whole envelope so the table can page', async () => {
    index.mockResolvedValue({ data: [{ id: 'm1' }], meta: { total: 1, last_page: 1 } })

    const local = withSetup(() => useMigrations(SPACE).useMigrationsQuery())
    await flush()

    expect(local.result.data.value).toEqual({ data: [{ id: 'm1' }], meta: { total: 1, last_page: 1 } })
    local.unmount()
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useMigrations(SPACE).useMigrationsQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle without a space id', async () => {
    const local = withSetup(() => useMigrations('').useMigrationsQuery())
    await flush()

    expect(local.result.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
    local.unmount()
  })

  it('stays idle when disabled', async () => {
    const local = withSetup(() => useMigrations(SPACE).useMigrationsQuery({}, false))
    await flush()

    expect(local.result.fetchStatus.value).toBe('idle')
    local.unmount()
  })
})

describe('useMigrationQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: { id: 'm1', state: 'done' } })

    const local = withSetup(() => useMigrations(SPACE).useMigrationQuery('m1'))
    await flush()

    expect(local.result.data.value).toEqual({ id: 'm1', state: 'done' })
    expect(get).toHaveBeenCalledWith('m1')
    local.unmount()
  })

  it('stays idle without an id', async () => {
    const local = withSetup(() => useMigrations(SPACE).useMigrationQuery(''))
    await flush()

    expect(local.result.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
    local.unmount()
  })

  const pollFor = async (state: string | undefined) => {
    const local = withSetup(() => useMigrations(SPACE).useMigrationQuery('m1'), {
      seed: [[keys.detail('m1'), { id: 'm1', state: 'done' }]],
    })
    await flush()

    const query = local.queryClient.getQueryCache().find({ queryKey: keys.detail('m1') })
    const interval = ((query?.options ?? {}) as {
      refetchInterval?: (q: unknown) => number | false
    }).refetchInterval as (q: unknown) => number | false
    const result = interval({ state: { data: state ? { state } : undefined } })

    local.unmount()
    return result
  }

  it.each([['pending'], ['processing']])('polls every 2s while %s', async (state) => {
    expect(await pollFor(state)).toBe(2000)
  })

  it.each([['completed'], ['failed'], [undefined]])('stops polling in state %s', async (state) => {
    expect(await pollFor(state)).toBe(false)
  })
})

describe('useCreateMigrationMutation', () => {
  it('invalidates the lists and confirms the start', async () => {
    create.mockResolvedValue({ data: { id: 'm1' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      target_space_id: 'space-2',
      conflict_strategy: 'skip',
    } as unknown as CreateMigrationPayload)

    expect(create).toHaveBeenCalledWith({ target_space_id: 'space-2', conflict_strategy: 'skip' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Migration started successfully')
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('same space'))
    const { create: mutation } = setup()

    await mutation.mutateAsync({} as CreateMigrationPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to start migration: same space')
  })

  it('falls back to "Unknown error"', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync({} as CreateMigrationPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to start migration: Unknown error')
  })

  it('does not invalidate on failure', async () => {
    create.mockRejectedValue(new Error('boom'))
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({} as CreateMigrationPayload).catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('useDeleteMigrationMutation', () => {
  it('invalidates the lists', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('m1')

    expect(destroy).toHaveBeenCalledWith('m1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Migration deleted successfully')
  })

  it('drops the deleted migration detail, so a running row stops polling a 404', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    harness!.queryClient.setQueryData(keys.detail('m1'), { id: 'm1', state: 'pending' })

    await remove.mutateAsync('m1')

    expect(harness!.queryClient.getQueryData(keys.detail('m1'))).toBeUndefined()
  })

  it('reports the failure reason', async () => {
    destroy.mockRejectedValue(new Error('running'))
    const { remove } = setup()

    await remove.mutateAsync('m1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to delete migration: running')
  })
})

describe('query keys', () => {
  it('lists() prefixes list(filters), so invalidation matches every page', () => {
    const list = keys.list({ page: 4 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('keeps migrations of two spaces apart', () => {
    expect(queryKeys.migrations('a').all()).not.toEqual(queryKeys.migrations('b').all())
  })

  it('scopes the api client to the space it was constructed with', async () => {
    setup('space-9')
    destroy.mockResolvedValue(undefined)

    await harness!.result.remove.mutateAsync('m1')

    expect(forSpace).toHaveBeenCalledWith('space-9')
  })
})
