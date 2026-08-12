import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { spaceEntityKeys } from '~/lib/query-keys'

import { withSetup, type Harness } from '../support/harness'

// A stub `t` rather than the real catalogue: these tests assert *which* message
// key and interpolation values the factory picks, not the copy behind them.
vi.mock('~/plugins/i18n', () => ({
  useI18n: () => ({
    t: (key: string, named?: Record<string, unknown>) =>
      named ? `${key}|${JSON.stringify(named)}` : key,
  }),
}))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { createCrudComposable } = await import('~/lib/crud-composable')

const SPACE = 'space-1'
const keys = spaceEntityKeys('widgets')

interface Widget {
  id: string
  name: string
  state?: string
}

interface WidgetParams {
  sort?: string
  page?: number
}

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const resource = vi.fn(() => ({ index, get, create, update, delete: destroy }))

type Config = Parameters<
  typeof createCrudComposable<
    Widget,
    { data: Widget[] },
    WidgetParams,
    { name: string },
    { name: string }
  >
>[0]

const baseConfig = (): Config => ({
  i18nKey: 'widgets',
  keys,
  resource,
  toastValues: (data) => ({ name: data.name }),
})

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

let harness: Harness<unknown> | undefined

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, success, error]) fn.mockReset()
  resource.mockClear()
  index.mockResolvedValue({ data: [] })
  get.mockResolvedValue({ data: { id: 'w1', name: 'One' } })
  create.mockResolvedValue({ data: { id: 'w1', name: 'One' } })
  update.mockResolvedValue({ data: { id: 'w1', name: 'Renamed' } })
  destroy.mockResolvedValue(undefined)
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('list query', () => {
  const sorted = (): Config => ({ ...baseConfig(), defaultParams: { sort: '+name' } })

  it('merges the default params underneath the caller params', async () => {
    const useCrud = createCrudComposable(sorted())
    withSetup(() => useCrud(SPACE).useListQuery({ page: 2 }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name', page: 2 })
  })

  it('lets the caller override the default sort', async () => {
    const useCrud = createCrudComposable(sorted())
    withSetup(() => useCrud(SPACE).useListQuery({ sort: '-created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('waits for a space id by default', async () => {
    const useCrud = createCrudComposable(baseConfig())
    withSetup(() => useCrud('').useListQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()
  })

  it("fires without a space id when the entity opts out with listGate 'none'", async () => {
    const useCrud = createCrudComposable({ ...baseConfig(), listGate: 'none' })
    withSetup(() => useCrud('').useListQuery())
    await flush()

    expect(index).toHaveBeenCalledTimes(1)
  })

  it('respects the caller-supplied enabled flag', async () => {
    const enabled = ref(false)
    const useCrud = createCrudComposable(baseConfig())
    withSetup(() => useCrud(SPACE).useListQuery({}, enabled))
    await flush()

    expect(index).not.toHaveBeenCalled()
  })

  it('hands the caller the raw response unless selectList narrows it', async () => {
    index.mockResolvedValue({ data: [{ id: 'w1', name: 'One' }] })

    const raw = withSetup(() => createCrudComposable(baseConfig())(SPACE).useListQuery())
    await flush()
    expect(raw.result.data.value).toEqual({ data: [{ id: 'w1', name: 'One' }] })
    raw.unmount()

    const useSelecting = createCrudComposable({
      ...baseConfig(),
      selectList: (response: { data: Widget[] }) => response.data,
    })
    harness = withSetup(() => useSelecting(SPACE).useListQuery())
    await flush()
    expect((harness.result as { data: { value: unknown } }).data.value).toEqual([
      { id: 'w1', name: 'One' },
    ])
  })

  it('re-runs against the new space when the space id changes', async () => {
    const spaceId = ref(SPACE)
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(spaceId).useListQuery())
    await flush()

    spaceId.value = 'space-2'
    await flush()

    expect(resource).toHaveBeenCalledWith('space-2')
  })
})

describe('detail query', () => {
  it('unwraps the resource envelope', async () => {
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useDetailQuery('w1'))
    await flush()

    expect(get).toHaveBeenCalledWith('w1')
    expect((harness.result as { data: { value: unknown } }).data.value).toEqual({
      id: 'w1',
      name: 'One',
    })
  })

  it('waits for an id by default', async () => {
    const useCrud = createCrudComposable(baseConfig())
    withSetup(() => useCrud(SPACE).useDetailQuery(''))
    await flush()

    expect(get).not.toHaveBeenCalled()
  })

  it("fires without an id when the entity opts out with detailGate 'none'", async () => {
    // Some composables never gated their detail query and components rely on it.
    const useCrud = createCrudComposable({ ...baseConfig(), detailGate: 'none' })
    withSetup(() => useCrud('').useDetailQuery(''))
    await flush()

    expect(get).toHaveBeenCalledTimes(1)
  })

  it("detailGate 'space' waits for the space but not the id", async () => {
    const useCrud = createCrudComposable({ ...baseConfig(), detailGate: 'space' })

    withSetup(() => useCrud('').useDetailQuery(''))
    await flush()
    expect(get).not.toHaveBeenCalled()

    harness = withSetup(() => useCrud(SPACE).useDetailQuery(''))
    await flush()
    expect(get).toHaveBeenCalledTimes(1)
  })

  it('polls while detailRefetchInterval says the record is unfinished', async () => {
    get.mockResolvedValue({ data: { id: 'w1', name: 'One', state: 'processing' } })
    const interval = vi.fn((data?: Widget) => (data?.state === 'processing' ? 2000 : false))

    const useCrud = createCrudComposable({ ...baseConfig(), detailRefetchInterval: interval })
    harness = withSetup(() => useCrud(SPACE).useDetailQuery('w1'))
    await flush()

    expect(interval).toHaveBeenCalled()
    expect(interval).toHaveLastReturnedWith(2000)
  })
})

describe('create mutation', () => {
  it('invalidates the entity lists and toasts with the interpolation values', async () => {
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useCreateMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await (harness.result as { mutateAsync: (p: unknown) => Promise<unknown> }).mutateAsync({
      name: 'One',
    })

    expect(create).toHaveBeenCalledWith({ name: 'One' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).lists() })
    expect(success).toHaveBeenCalledWith(
      'composables.widgets.createSuccess|{"name":"One"}'
    )
  })

  it('toasts without interpolation when the entity declares no toastValues', async () => {
    const { toastValues: _drop, ...config } = baseConfig()
    const useCrud = createCrudComposable(config)
    harness = withSetup(() => useCrud(SPACE).useCreateMutation())

    await (harness.result as { mutateAsync: (p: unknown) => Promise<unknown> }).mutateAsync({
      name: 'One',
    })

    expect(success).toHaveBeenCalledWith('composables.widgets.createSuccess')
  })

  it('invalidates the cross-entity keys the operation makes stale', async () => {
    const other = ['spaces', SPACE, 'assets', 'list']
    const invalidateAlso = vi.fn((_spaceId: string, operation: string) =>
      operation === 'create' ? [other] : []
    )
    const useCrud = createCrudComposable({ ...baseConfig(), invalidateAlso })
    harness = withSetup(() => useCrud(SPACE).useCreateMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await (harness.result as { mutateAsync: (p: unknown) => Promise<unknown> }).mutateAsync({
      name: 'One',
    })

    expect(invalidateAlso).toHaveBeenCalledWith(SPACE, 'create')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: other })
  })

  it('reports the failure under the entity error key', async () => {
    create.mockRejectedValue(new Error('boom'))
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useCreateMutation())

    await expect(
      (harness.result as { mutateAsync: (p: unknown) => Promise<unknown> }).mutateAsync({
        name: 'One',
      })
    ).rejects.toThrow('boom')

    expect(error).toHaveBeenCalledWith('composables.widgets.createError|{"error":"boom"}')
    expect(success).not.toHaveBeenCalled()
  })

  it('falls back to "Unknown error" for a failure with no message', async () => {
    create.mockRejectedValue(new Error(''))
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useCreateMutation())

    await expect(
      (harness.result as { mutateAsync: (p: unknown) => Promise<unknown> }).mutateAsync({
        name: 'One',
      })
    ).rejects.toThrow()

    expect(error).toHaveBeenCalledWith('composables.widgets.createError|{"error":"Unknown error"}')
  })
})

describe('update mutation', () => {
  const mutate = (result: unknown, variables: unknown) =>
    (result as { mutateAsync: (v: unknown) => Promise<unknown> }).mutateAsync(variables)

  it('invalidates both the lists and the updated record', async () => {
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useUpdateMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await mutate(harness.result, { id: 'w1', payload: { name: 'Renamed' } })

    expect(update).toHaveBeenCalledWith('w1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).lists() })
    // Keyed off the *response*, so a server-side id change still invalidates.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).detail('w1') })
    expect(success).toHaveBeenCalledWith(
      'composables.widgets.updateSuccess|{"name":"Renamed"}'
    )
  })

  it('invalidates lists, then the record, then the cross-entity keys', async () => {
    // Several composable tests assert this exact order, so it is contract.
    const other = ['spaces', SPACE, 'assets', 'list']
    const useCrud = createCrudComposable({ ...baseConfig(), invalidateAlso: () => [other] })
    harness = withSetup(() => useCrud(SPACE).useUpdateMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await mutate(harness.result, { id: 'w1', payload: { name: 'Renamed' } })

    const queryKeyOf = (options: unknown) =>
      (options as { queryKey?: readonly unknown[] } | undefined)?.queryKey

    expect(invalidate.mock.calls.map(([options]) => queryKeyOf(options))).toEqual([
      keys(SPACE).lists(),
      keys(SPACE).detail('w1'),
      other,
    ])
  })

  it('maps a non-standard variable shape onto { id, payload }', async () => {
    const useCrud = createCrudComposable({
      ...baseConfig(),
      updateVariables: ({ folderId, payload }: { folderId: string; payload: { name: string } }) => ({
        id: folderId,
        payload,
      }),
    })
    harness = withSetup(() => useCrud(SPACE).useUpdateMutation())

    await mutate(harness.result, { folderId: 'f1', payload: { name: 'Renamed' } })

    expect(update).toHaveBeenCalledWith('f1', { name: 'Renamed' })
  })

  it('lets prepareUpdate strip fields the server must not receive', async () => {
    const useCrud = createCrudComposable({
      ...baseConfig(),
      prepareUpdate: ({ name }: { name: string }) => ({ name: name.trim() }),
    })
    harness = withSetup(() => useCrud(SPACE).useUpdateMutation())

    await mutate(harness.result, { id: 'w1', payload: { name: '  Renamed  ' } })

    expect(update).toHaveBeenCalledWith('w1', { name: 'Renamed' })
  })

  it('reports the failure under the entity error key', async () => {
    update.mockRejectedValue(new Error('nope'))
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useUpdateMutation())

    await expect(mutate(harness.result, { id: 'w1', payload: { name: 'x' } })).rejects.toThrow()

    expect(error).toHaveBeenCalledWith('composables.widgets.updateError|{"error":"nope"}')
  })
})

describe('delete mutation', () => {
  const mutate = (result: unknown, id: string) =>
    (result as { mutateAsync: (v: string) => Promise<unknown> }).mutateAsync(id)

  it('invalidates the lists and evicts the detail entry', async () => {
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useDeleteMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await mutate(harness.result, 'w1')

    expect(destroy).toHaveBeenCalledWith('w1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).lists() })
    // Left behind, a polling detail query would keep 404ing after the row is gone.
    expect(remove).toHaveBeenCalledWith({ queryKey: keys(SPACE).detail('w1') })
  })

  it('toasts the delete message without interpolation values', async () => {
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useDeleteMutation())

    await mutate(harness.result, 'w1')

    expect(success).toHaveBeenCalledWith('composables.widgets.deleteSuccess')
  })

  it('reports the failure under the entity error key', async () => {
    destroy.mockRejectedValue(new Error('locked'))
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(SPACE).useDeleteMutation())

    await expect(mutate(harness.result, 'w1')).rejects.toThrow()

    expect(error).toHaveBeenCalledWith('composables.widgets.deleteError|{"error":"locked"}')
  })
})

describe('exposed internals', () => {
  it('exposes invalidateLists so a custom hook can reuse the entity fan-out', async () => {
    const other = ['spaces', SPACE, 'icons', 'tags']
    const useCrud = createCrudComposable({ ...baseConfig(), invalidateAlso: () => [other] })
    harness = withSetup(() => useCrud(SPACE))
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    ;(harness.result as { invalidateLists: (op: string) => void }).invalidateLists('create')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: other })
  })

  it('exposes the resolved space id and keys as computed refs', () => {
    const spaceId = ref(SPACE)
    const useCrud = createCrudComposable(baseConfig())
    harness = withSetup(() => useCrud(spaceId))

    const crud = harness.result as {
      spaceId: { value: string }
      keys: { value: { all: () => readonly unknown[] } }
    }

    expect(crud.spaceId.value).toBe(SPACE)
    spaceId.value = 'space-2'
    expect(crud.keys.value.all()).toEqual(['spaces', 'space-2', 'widgets'])
  })
})
