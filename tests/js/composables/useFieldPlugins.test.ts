import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type {
  CreateFieldPluginPayload,
  FieldPluginResource,
  UpdateFieldPluginPayload,
} from '~/types/field-plugins'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({
  fieldPlugins: { index, get, create, update, delete: destroy },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useFieldPlugins } = await import('~/composables/useFieldPlugins')

const SPACE = 'space-1'
const keys = queryKeys.fieldPlugins(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const plugin = (extra: Record<string, unknown> = {}) =>
  ({
    id: 'fp1',
    name: 'Colour picker',
    handle: 'colour-picker',
    is_active: true,
    sandbox_url: 'https://sandbox.b10cks.test/fp1?sig=abc123',
    ...extra,
  }) as unknown as FieldPluginResource

const mounted: Array<() => void> = []

/** Factories call useQuery/useMutation, so they must be built inside setup(). */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const inSpace = (spaceId: MaybeRef<string> = SPACE) => useFieldPlugins(spaceId)

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, success, error]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
})

describe('useFieldPluginsQuery', () => {
  it('sorts by name ascending by default', async () => {
    mount(() => inSpace().useFieldPluginsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets caller params override the default sort', async () => {
    mount(() => inSpace().useFieldPluginsQuery({ sort: '-created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('forwards an is_active filter alongside the sort', async () => {
    mount(() => inSpace().useFieldPluginsQuery({ is_active: true }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name', is_active: true })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [plugin()], meta: { total: 1 } })

    const query = mount(() => inSpace().useFieldPluginsQuery()).result
    await flush()

    expect(query.data.value?.meta).toEqual({ total: 1 })
  })

  it('caches under the filter-scoped list key', async () => {
    const harness = mount(() => inSpace().useFieldPluginsQuery({ handle: 'colour-picker' }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ handle: 'colour-picker' }))).toBeDefined()
  })

  it('stays idle while the space id is empty', async () => {
    const query = mount(() => inSpace('').useFieldPluginsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useFieldPluginsQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('keeps the previous page visible while the next one loads', async () => {
    const params = ref({ page: 1 })
    index.mockResolvedValue({ data: [plugin()] })
    const harness = mount(() => inSpace().useFieldPluginsQuery(params))
    await flush()

    let release = () => {}
    index.mockImplementation(() => new Promise((resolve) => (release = () => resolve({ data: [] }))))
    params.value = { page: 2 }
    await nextTick()

    expect(harness.result.isPlaceholderData.value).toBe(true)

    release()
    await flush()
  })
})

describe('useFieldPluginQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: plugin() })

    const query = mount(() => inSpace().useFieldPluginQuery('fp1')).result
    await flush()

    expect(get).toHaveBeenCalledWith('fp1')
    expect(query.data.value?.handle).toBe('colour-picker')
  })

  it('surfaces the signed sandbox URL exactly as the server minted it', async () => {
    get.mockResolvedValue({ data: plugin() })

    const query = mount(() => inSpace().useFieldPluginQuery('fp1')).result
    await flush()

    // The signature is server-side; the composable neither builds nor rewrites it.
    expect(query.data.value?.sandbox_url).toBe('https://sandbox.b10cks.test/fp1?sig=abc123')
  })

  it('passes a null sandbox URL through for a plugin with inline code', async () => {
    get.mockResolvedValue({ data: plugin({ sandbox_url: null }) })

    const query = mount(() => inSpace().useFieldPluginQuery('fp1')).result
    await flush()

    expect(query.data.value?.sandbox_url).toBeNull()
  })

  it('stays idle without an id', async () => {
    const query = mount(() => inSpace().useFieldPluginQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('stays idle while the space id is empty', async () => {
    const query = mount(() => inSpace('').useFieldPluginQuery('fp1')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useFieldPluginQuery('fp1', false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })
})

describe('useCreateFieldPluginMutation', () => {
  it('invalidates the lists and names the plugin in the toast', async () => {
    create.mockResolvedValue({ data: plugin() })
    const harness = mount(() => inSpace().useCreateFieldPluginMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const payload = {
      name: 'Colour picker',
      handle: 'colour-picker',
    } as CreateFieldPluginPayload
    const result = await harness.result.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(result.id).toBe('fp1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(success).toHaveBeenCalledWith('Field plugin "Colour picker" created successfully')
  })

  it('sends the handle as given — the composable does not slugify it', async () => {
    create.mockResolvedValue({ data: plugin() })
    const harness = mount(() => inSpace().useCreateFieldPluginMutation())

    await harness.result.mutateAsync({
      name: 'Colour picker',
      handle: 'Colour Picker',
    } as CreateFieldPluginPayload)

    expect(create).toHaveBeenCalledWith({ name: 'Colour picker', handle: 'Colour Picker' })
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('handle taken'))
    const harness = mount(() => inSpace().useCreateFieldPluginMutation())

    await expect(harness.result.mutateAsync({} as CreateFieldPluginPayload)).rejects.toThrow(
      'handle taken'
    )
    expect(error).toHaveBeenCalledWith('Failed to create field plugin: handle taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const harness = mount(() => inSpace().useCreateFieldPluginMutation())

    await harness.result.mutateAsync({} as CreateFieldPluginPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create field plugin: Unknown error')
  })
})

describe('useUpdateFieldPluginMutation', () => {
  it('invalidates the lists and that plugin detail', async () => {
    update.mockResolvedValue({ data: plugin({ name: 'Renamed' }) })
    const harness = mount(() => inSpace().useUpdateFieldPluginMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'fp1', payload: { name: 'Renamed' } })

    expect(update).toHaveBeenCalledWith('fp1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('fp1') })
    expect(success).toHaveBeenCalledWith('Field plugin "Renamed" updated successfully')
  })

  it('strips the immutable handle, so a cast cannot put it on the wire', async () => {
    update.mockResolvedValue({ data: plugin() })
    const harness = mount(() => inSpace().useUpdateFieldPluginMutation())

    // UpdateFieldPluginPayload omits `handle`, so reaching it needs a cast —
    // content is keyed by the handle, so the composable drops it rather than
    // leaving the server as the only gatekeeper.
    await harness.result.mutateAsync({
      id: 'fp1',
      payload: { name: 'Renamed', handle: 'new-handle' } as UpdateFieldPluginPayload,
    })

    expect(update).toHaveBeenCalledWith('fp1', { name: 'Renamed' })
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: plugin({ id: 'server-id' }) })
    const harness = mount(() => inSpace().useUpdateFieldPluginMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'fp1', payload: {} })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('fp1') })
  })

  it('does not invalidate when the update fails', async () => {
    update.mockRejectedValue(new Error('invalid schema'))
    const harness = mount(() => inSpace().useUpdateFieldPluginMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'fp1', payload: {} }).catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to update field plugin: invalid schema')
  })
})

describe('useDeleteFieldPluginMutation', () => {
  it('invalidates the lists and drops the detail cache', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteFieldPluginMutation(), [
      [keys.detail('fp1'), plugin()],
    ])
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('fp1')

    expect(destroy).toHaveBeenCalledWith('fp1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.detail('fp1') })
    // The signed sandbox_url is dropped with it, so no stale URL can be reused.
    expect(harness.queryClient.getQueryData(keys.detail('fp1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Field plugin deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const harness = mount(() => inSpace().useDeleteFieldPluginMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('fp1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete field plugin: in use')
  })
})

describe('query key shape', () => {
  it('scopes every key to the space', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'field-plugins'])
    expect(queryKeys.fieldPlugins('a').lists()).not.toEqual(queryKeys.fieldPlugins('b').lists())
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ is_active: true })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('invalidates only the current space', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace('space-2').useDeleteFieldPluginMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('fp1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.fieldPlugins('space-2').lists() })
  })
})