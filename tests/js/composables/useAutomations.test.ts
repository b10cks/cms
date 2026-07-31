import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const trigger = vi.fn()
const triggerCatalog = vi.fn()

const forSpace = vi.fn(() => ({
  automations: { index, get, create, update, delete: destroy, trigger, triggerCatalog },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAutomations } = await import('~/composables/useAutomations')

const SPACE = 'space-1'
const keys = queryKeys.automations(SPACE)
const actionKeys = queryKeys.automationActions(SPACE)
const executionKeys = queryKeys.automationExecutions(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useAutomations>
type Mutations = {
  create: ReturnType<Composable['useCreateAutomationMutation']>
  update: ReturnType<Composable['useUpdateAutomationMutation']>
  remove: ReturnType<Composable['useDeleteAutomationMutation']>
  run: ReturnType<Composable['useTriggerAutomationMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const automations = useAutomations(spaceId)
      return {
        create: automations.useCreateAutomationMutation(),
        update: automations.useUpdateAutomationMutation(),
        remove: automations.useDeleteAutomationMutation(),
        run: automations.useTriggerAutomationMutation(),
      }
    },
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, get, create, update, destroy, trigger, triggerCatalog, success, error]) {
    fn.mockReset()
  }
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useAutomationsQuery', () => {
  it('sends the caller params verbatim — there is no default sort', async () => {
    withSetup(() => useAutomations(SPACE).useAutomationsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({})
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('forwards the filters it was given', async () => {
    withSetup(() =>
      useAutomations(SPACE).useAutomationsQuery({ trigger_type: 'on_update', is_active: true })
    )
    await flush()

    expect(index).toHaveBeenCalledWith({ trigger_type: 'on_update', is_active: true })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'a1' }], meta: { total: 1 } })

    const query = withSetup(() => useAutomations(SPACE).useAutomationsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'a1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() => useAutomations(SPACE).useAutomationsQuery({ page: 2 }))
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useAutomations('').useAutomationsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() => useAutomations(SPACE).useAutomationsQuery(params))

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })
})

describe('useAutomationQuery', () => {
  it('unwraps the data envelope for a single automation', async () => {
    get.mockResolvedValue({ data: { id: 'a1', name: 'Purge cache' } })

    const query = withSetup(() => useAutomations(SPACE).useAutomationQuery('a1')).result
    await flush()

    expect(query.data.value).toEqual({ id: 'a1', name: 'Purge cache' })
    expect(get).toHaveBeenCalledWith('a1')
  })

  it('stays idle without an id', async () => {
    const query = withSetup(() => useAutomations(SPACE).useAutomationQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('useAutomationTriggerCatalogQuery', () => {
  it('unwraps the catalog envelope', async () => {
    triggerCatalog.mockResolvedValue({ data: { tables: [{ table: 'contents', columns: [] }] } })

    const query = withSetup(() =>
      useAutomations(SPACE).useAutomationTriggerCatalogQuery()
    ).result
    await flush()

    expect(query.data.value).toEqual({ tables: [{ table: 'contents', columns: [] }] })
  })

  /**
   * The catalog reuses the *list* key namespace with a sentinel filter, so a
   * `lists()` invalidation from any automation mutation also refetches it.
   */
  it('caches under the automations list namespace with a triggerCatalog sentinel', async () => {
    triggerCatalog.mockResolvedValue({ data: { tables: [] } })

    const local = withSetup(() => useAutomations(SPACE).useAutomationTriggerCatalogQuery())
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ triggerCatalog: true }))).toEqual({
      tables: [],
    })
    local.unmount()
  })

  it('is dropped by a lists() invalidation, despite a 5 minute staleTime', async () => {
    triggerCatalog.mockResolvedValue({ data: { tables: [] } })

    const local = withSetup(() => useAutomations(SPACE).useAutomationTriggerCatalogQuery())
    await flush()

    const matches = local.queryClient
      .getQueryCache()
      .findAll({ queryKey: keys.lists() })
      .map((query) => query.queryKey)

    expect(matches).toContainEqual([...keys.list({ triggerCatalog: true })])
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useAutomations('').useAutomationTriggerCatalogQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(triggerCatalog).not.toHaveBeenCalled()
  })
})

describe('useCreateAutomationMutation', () => {
  const payload = { name: 'Purge cache', trigger_type: 'manual' } as unknown as CreateAutomationPayload

  it('invalidates the lists and names the automation in the toast', async () => {
    create.mockResolvedValue({ data: { id: 'a1', name: 'Purge cache' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(success).toHaveBeenCalledWith('Automation "Purge cache" created successfully')
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('invalid cron'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('invalid cron')
    expect(error).toHaveBeenCalledWith('Failed to create automation: invalid cron')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create automation: Unknown error')
  })
})

describe('useUpdateAutomationMutation', () => {
  it('invalidates the lists and that automation detail', async () => {
    update.mockResolvedValue({ data: { id: 'a1', name: 'Renamed' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      id: 'a1',
      payload: { name: 'Renamed' } as UpdateAutomationPayload,
    })

    expect(update).toHaveBeenCalledWith('a1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('a1') })
    expect(success).toHaveBeenCalledWith('Automation "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'x' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'a1', payload: {} as UpdateAutomationPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('a1') })
  })

  // Actions belong to an automation, but changing the automation does not
  // refresh the action lists — only the reverse relationship is invalidated.
  it('does not invalidate the action lists', async () => {
    update.mockResolvedValue({ data: { id: 'a1', name: 'x' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'a1', payload: {} as UpdateAutomationPayload })

    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: actionKeys.lists() })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('conflict'))
    const { update: mutation } = setup()

    await mutation.mutateAsync({ id: 'a1', payload: {} as UpdateAutomationPayload }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update automation: conflict')
  })
})

describe('useDeleteAutomationMutation', () => {
  it('invalidates the lists and evicts the detail entry', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('a1'), { id: 'a1' }]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('a1')

    expect(destroy).toHaveBeenCalledWith('a1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(harness?.queryClient.getQueryData(keys.detail('a1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Automation deleted successfully')
  })

  // Deleting an automation orphans its executions in the cache.
  it('leaves the execution lists untouched', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('a1')

    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: executionKeys.lists() })
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('nope'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('a1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete automation: nope')
  })
})

describe('useTriggerAutomationMutation', () => {
  it('fans the invalidation out over automations, actions and executions', async () => {
    trigger.mockResolvedValue({ data: { id: 'a1', name: 'Purge cache' } })
    const { run } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await run.mutateAsync({ id: 'a1', payload: { payload: { foo: 'bar' } } })

    expect(trigger).toHaveBeenCalledWith('a1', { payload: { foo: 'bar' } })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('a1') })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: actionKeys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: executionKeys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(4)
  })

  it('passes undefined through when no payload is supplied', async () => {
    trigger.mockResolvedValue({ data: { id: 'a1', name: 'Purge cache' } })
    const { run } = setup()

    await run.mutateAsync({ id: 'a1' })

    expect(trigger).toHaveBeenCalledWith('a1', undefined)
  })

  // The copy says "queued", not "ran": triggering only enqueues the job.
  it('reports the automation as queued, not executed', async () => {
    trigger.mockResolvedValue({ data: { id: 'a1', name: 'Purge cache' } })
    const { run } = setup()

    await run.mutateAsync({ id: 'a1' })

    expect(success).toHaveBeenCalledWith('Automation "Purge cache" was queued successfully')
  })

  it('reports the failure reason', async () => {
    trigger.mockRejectedValue(new Error('automation is inactive'))
    const { run } = setup()

    await run.mutateAsync({ id: 'a1' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to trigger automation: automation is inactive')
  })
})

describe('space scoping', () => {
  it('keys automations per space, so two spaces never share a list', () => {
    expect(queryKeys.automations('a').lists()).not.toEqual(queryKeys.automations('b').lists())
  })

  it('follows a reactive space id', async () => {
    const spaceId = ref(SPACE)
    const local = withSetup(() => useAutomations(spaceId).useAutomationsQuery())

    await flush()
    spaceId.value = 'space-2'
    await nextTick()
    await flush()

    expect(forSpace).toHaveBeenCalledWith('space-2')
    expect(local.queryClient.getQueryData(queryKeys.automations('space-2').list({}))).toBeDefined()
    local.unmount()
  })

  it('invalidates only the current space on trigger', async () => {
    trigger.mockResolvedValue({ data: { id: 'a1', name: 'x' } })
    const { run } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await run.mutateAsync({ id: 'a1' })

    expect(invalidate).toHaveBeenCalledWith({
      queryKey: queryKeys.automationExecutions('space-2').lists(),
    })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: executionKeys.lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
