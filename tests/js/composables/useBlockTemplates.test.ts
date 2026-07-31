import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const blockTemplates = vi.fn(() => ({ index, get, create, update, delete: destroy }))
const forSpace = vi.fn(() => ({ blockTemplates }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useBlockTemplates } = await import('~/composables/useBlockTemplates')

const SPACE = 'space-1'
const BLOCK = 'block-1'
const keys = queryKeys.blockTemplates(SPACE, BLOCK)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * The composable wraps its ids in computeds, so the *recorded* invalidation key
 * holds refs rather than strings. vue-query's QueryClient deep-unrefs before it
 * matches, so compare against the unwrapped shape.
 */
const invalidatedKeys = (spy: MockInstance) =>
  spy.mock.calls.map((call) =>
    ((call[0] as { queryKey: unknown[] }).queryKey ?? []).map((part) => unref(part))
  )

const template = (id: string, name = id, content: Record<string, unknown> = {}) =>
  ({ id, name, content }) as BlockTemplate

type Composable = ReturnType<typeof useBlockTemplates>
type Mutations = {
  create: ReturnType<Composable['useCreateBlockTemplateMutation']>
  update: ReturnType<Composable['useUpdateBlockTemplateMutation']>
  remove: ReturnType<Composable['useDeleteBlockTemplateMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (
  spaceId: MaybeRefOrGetter<string> = SPACE,
  blockId: MaybeRefOrGetter<string> = BLOCK,
  seed?: Array<[readonly unknown[], unknown]>
) => {
  harness = withSetup<Mutations>(
    () => {
      const templates = useBlockTemplates(spaceId, blockId)
      return {
        create: templates.useCreateBlockTemplateMutation(),
        update: templates.useUpdateBlockTemplateMutation(),
        remove: templates.useDeleteBlockTemplateMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, success, error]) fn.mockReset()
  forSpace.mockClear()
  blockTemplates.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useBlockTemplatesQuery', () => {
  it('sorts newest first by default and unwraps the envelope', async () => {
    index.mockResolvedValue({ data: [template('t1')] })

    const query = withSetup(() => useBlockTemplates(SPACE, BLOCK).useBlockTemplatesQuery()).result
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
    expect(query.data.value).toEqual([template('t1')])
  })

  it('scopes the templates API to the space and the block', async () => {
    withSetup(() => useBlockTemplates(SPACE, BLOCK).useBlockTemplatesQuery())
    await flush()

    expect(forSpace).toHaveBeenCalledWith(SPACE)
    expect(blockTemplates).toHaveBeenCalledWith(BLOCK)
  })

  it('lets the caller override the default sort', async () => {
    withSetup(() => useBlockTemplates(SPACE, BLOCK).useBlockTemplatesQuery({ sort: '+name' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() =>
      useBlockTemplates(SPACE, BLOCK).useBlockTemplatesQuery({ page: 2 })
    )
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle without a space id', async () => {
    const query = withSetup(() => useBlockTemplates('', BLOCK).useBlockTemplatesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle without a block id', async () => {
    const query = withSetup(() => useBlockTemplates(SPACE, '').useBlockTemplatesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  /** Callers pass getters, which is why the composable wraps them in computeds. */
  it('accepts plain getters for the ids', async () => {
    const local = withSetup(() =>
      useBlockTemplates(
        () => SPACE,
        () => BLOCK
      ).useBlockTemplatesQuery()
    )
    await flush()

    expect(local.queryClient.getQueryData(keys.list({}))).toBeDefined()
    local.unmount()
  })

  it('rekeys when the block changes, so two blocks never share a template list', async () => {
    const blockId = ref(BLOCK)
    const local = withSetup(() => useBlockTemplates(SPACE, blockId).useBlockTemplatesQuery())

    await flush()
    blockId.value = 'block-2'
    await nextTick()
    await flush()

    expect(blockTemplates).toHaveBeenLastCalledWith('block-2')
    expect(
      local.queryClient.getQueryData(queryKeys.blockTemplates(SPACE, 'block-2').list({}))
    ).toBeDefined()
    local.unmount()
  })
})

describe('useBlockTemplateQuery', () => {
  it('unwraps the data envelope for a single template', async () => {
    get.mockResolvedValue({ data: template('t1', 'Hero', { headline: 'Hi' }) })

    const query = withSetup(() => useBlockTemplates(SPACE, BLOCK).useBlockTemplateQuery('t1'))
      .result
    await flush()

    expect(query.data.value).toEqual(template('t1', 'Hero', { headline: 'Hi' }))
    expect(get).toHaveBeenCalledWith('t1')
  })

  // No `enabled` guard on the detail query, unlike the list query.
  it('fires even without an id', async () => {
    get.mockResolvedValue({ data: null })

    withSetup(() => useBlockTemplates(SPACE, BLOCK).useBlockTemplateQuery(''))
    await flush()

    expect(get).toHaveBeenCalledWith('')
  })
})

describe('useCreateBlockTemplateMutation', () => {
  const payload = {
    name: 'Hero',
    content: { headline: 'Hi', items: [{ _uid: 'x', component: 'card' }] },
  } as CreateBlockTemplatePayload

  it('invalidates the template lists and names the template in the toast', async () => {
    create.mockResolvedValue({ data: template('t1', 'Hero') })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(invalidatedKeys(invalidate)).toContainEqual([...keys.lists()])
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(success).toHaveBeenCalledWith('Template "Hero" created successfully')
  })

  /** A template snapshots block content, so nested structure must survive verbatim. */
  it('sends the captured content untouched, nested arrays included', async () => {
    create.mockResolvedValue({ data: template('t1', 'Hero') })
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(create.mock.calls[0][0].content).toEqual({
      headline: 'Hi',
      items: [{ _uid: 'x', component: 'card' }],
    })
  })

  it('keeps an empty content object rather than dropping it', async () => {
    create.mockResolvedValue({ data: template('t1', 'Empty') })
    const { create: mutation } = setup()

    await mutation.mutateAsync({ name: 'Empty', content: {} } as CreateBlockTemplatePayload)

    expect(create).toHaveBeenCalledWith({ name: 'Empty', content: {} })
  })

  // The error handler is typed `{ message: string }`, not Error — a rejection
  // without a message property would produce "undefined" rather than the
  // fallback, so the fallback only covers an empty string.
  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('name taken'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('name taken')
    expect(error).toHaveBeenCalledWith('Failed to create template: name taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue({ message: '' })
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create template: Unknown error')
  })
})

describe('useUpdateBlockTemplateMutation', () => {
  it('invalidates the lists and that template detail', async () => {
    update.mockResolvedValue({ data: template('t1', 'Renamed') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      id: 't1',
      payload: { name: 'Renamed' } as UpdateBlockTemplatePayload,
    })

    expect(update).toHaveBeenCalledWith('t1', { name: 'Renamed' })
    expect(invalidatedKeys(invalidate)).toContainEqual([...keys.lists()])
    expect(invalidatedKeys(invalidate)).toContainEqual([...keys.detail('t1')])
    expect(success).toHaveBeenCalledWith('Template "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: template('server-id') })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 't1', payload: {} as UpdateBlockTemplatePayload })

    expect(invalidatedKeys(invalidate)).toContainEqual([...keys.detail('server-id')])
    expect(invalidatedKeys(invalidate)).not.toContainEqual([...keys.detail('t1')])
  })

  // UpdateBlockTemplatePayload carries no `content`: once captured, a template's
  // content can only be replaced by creating a new one.
  it('updates metadata only — content is not part of the update payload', async () => {
    update.mockResolvedValue({ data: template('t1', 'Renamed') })
    const { update: mutation } = setup()

    await mutation.mutateAsync({
      id: 't1',
      payload: { name: 'Renamed', description: 'why', icon: null } as UpdateBlockTemplatePayload,
    })

    expect(update.mock.calls[0][1]).not.toHaveProperty('content')
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('conflict'))
    const { update: mutation } = setup()

    await mutation.mutateAsync({ id: 't1', payload: {} as UpdateBlockTemplatePayload }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update template: conflict')
  })
})

describe('useDeleteBlockTemplateMutation', () => {
  it('invalidates the lists', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('t1')

    expect(destroy).toHaveBeenCalledWith('t1')
    expect(invalidatedKeys(invalidate)).toContainEqual([...keys.lists()])
    expect(success).toHaveBeenCalledWith('Template deleted successfully')
  })

  // Delete never calls removeQueries, so the deleted template stays readable
  // from the cache until it is garbage collected.
  it('leaves the deleted template detail in the cache — no removeQueries', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, BLOCK, [[keys.detail('t1'), template('t1')]])
    const removeQueries = vi.spyOn(harness!.queryClient, 'removeQueries')

    await remove.mutateAsync('t1')

    expect(removeQueries).not.toHaveBeenCalled()
    expect(harness?.queryClient.getQueryData(keys.detail('t1'))).toEqual(template('t1'))
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('t1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete template: in use')
  })
})

describe('key shape', () => {
  it('nests templates under their block', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'blocks', BLOCK, 'templates'])
  })

  it('keys templates per block, so two blocks never share a list', () => {
    expect(queryKeys.blockTemplates(SPACE, 'a').lists()).not.toEqual(
      queryKeys.blockTemplates(SPACE, 'b').lists()
    )
  })

  // Templates and versions both hang off the block, but under distinct leaves,
  // so invalidating one never refetches the other.
  it('does not collide with the block versions namespace', () => {
    expect(keys.all()).not.toEqual([...queryKeys.blockVersions(SPACE, BLOCK).all()])
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
