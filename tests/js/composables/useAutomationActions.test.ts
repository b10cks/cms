import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()

const forSpace = vi.fn(() => ({
  automationActions: { index, get, create, update, delete: destroy },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAutomationActions } = await import('~/composables/useAutomationActions')

const SPACE = 'space-1'
const keys = queryKeys.automationActions(SPACE)
const automationKeys = queryKeys.automations(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useAutomationActions>
type Mutations = {
  create: ReturnType<Composable['useCreateAutomationActionMutation']>
  update: ReturnType<Composable['useUpdateAutomationActionMutation']>
  remove: ReturnType<Composable['useDeleteAutomationActionMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => {
      const actions = useAutomationActions(spaceId)
      return {
        create: actions.useCreateAutomationActionMutation(),
        update: actions.useUpdateAutomationActionMutation(),
        remove: actions.useDeleteAutomationActionMutation(),
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

describe('useAutomationActionsQuery', () => {
  it('sends the caller params verbatim — there is no default sort', async () => {
    withSetup(() => useAutomationActions(SPACE).useAutomationActionsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({})
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('forwards the filters it was given', async () => {
    withSetup(() => useAutomationActions(SPACE).useAutomationActionsQuery({ type: 'webhook' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ type: 'webhook' })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'act1' }], meta: { total: 1 } })

    const query = withSetup(() => useAutomationActions(SPACE).useAutomationActionsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'act1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() =>
      useAutomationActions(SPACE).useAutomationActionsQuery({ page: 2 })
    )
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useAutomationActions('').useAutomationActionsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() => useAutomationActions(SPACE).useAutomationActionsQuery(params))

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })
})

describe('useAutomationActionQuery', () => {
  it('unwraps the data envelope for a single action', async () => {
    get.mockResolvedValue({ data: { id: 'act1', name: 'Ping', type: 'webhook' } })

    const query = withSetup(() => useAutomationActions(SPACE).useAutomationActionQuery('act1'))
      .result
    await flush()

    expect(query.data.value).toEqual({ id: 'act1', name: 'Ping', type: 'webhook' })
    expect(get).toHaveBeenCalledWith('act1')
  })

  it('stays idle without an id', async () => {
    const query = withSetup(() => useAutomationActions(SPACE).useAutomationActionQuery('')).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('useCreateAutomationActionMutation', () => {
  const payload = {
    name: 'Ping',
    type: 'webhook',
    config: { url: 'https://hook.test', method: 'POST' },
  } as unknown as CreateAutomationActionPayload

  it('invalidates the action lists and names the action in the toast', async () => {
    create.mockResolvedValue({ data: { id: 'act1', name: 'Ping' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(create).toHaveBeenCalledWith(payload)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).toHaveBeenCalledWith('Action "Ping" created successfully')
  })

  // Create does not touch the automation lists, but update does — the two
  // sibling mutations disagree about whether an action change is visible there.
  it('leaves the automation lists alone, unlike update', async () => {
    create.mockResolvedValue({ data: { id: 'act1', name: 'Ping' } })
    const { create: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync(payload)

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: automationKeys.lists() })
  })

  it('sends the nested config through untouched', async () => {
    create.mockResolvedValue({ data: { id: 'act1', name: 'Ping' } })
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload)

    expect(create.mock.calls[0][0].config).toEqual({ url: 'https://hook.test', method: 'POST' })
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('url required'))
    const { create: mutation } = setup()

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('url required')
    expect(error).toHaveBeenCalledWith('Failed to create action: url required')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { create: mutation } = setup()

    await mutation.mutateAsync(payload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create action: Unknown error')
  })
})

describe('useUpdateAutomationActionMutation', () => {
  it('invalidates the action lists, the detail and the automation lists', async () => {
    update.mockResolvedValue({ data: { id: 'act1', name: 'Renamed' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({
      id: 'act1',
      payload: { name: 'Renamed' } as UpdateAutomationActionPayload,
    })

    expect(update).toHaveBeenCalledWith('act1', { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('act1') })
    // The automation list renders its action summary, so it goes stale too.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: automationKeys.lists() })
    expect(success).toHaveBeenCalledWith('Action "Renamed" updated successfully')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'x' } })
    const { update: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync({ id: 'act1', payload: {} as UpdateAutomationActionPayload })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('act1') })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('conflict'))
    const { update: mutation } = setup()

    await mutation
      .mutateAsync({ id: 'act1', payload: {} as UpdateAutomationActionPayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update action: conflict')
  })
})

describe('useDeleteAutomationActionMutation', () => {
  it('invalidates the action lists and evicts the detail entry', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup(SPACE, [[keys.detail('act1'), { id: 'act1' }]])
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('act1')

    expect(destroy).toHaveBeenCalledWith('act1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(harness?.queryClient.getQueryData(keys.detail('act1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Action deleted successfully')
  })

  // Deleting an action changes what the automation list shows, yet only update
  // invalidates the automation lists — delete leaves them stale.
  it('leaves the automation lists stale', async () => {
    destroy.mockResolvedValue(undefined)
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('act1')

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: automationKeys.lists() })
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('still referenced'))
    const { remove } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await remove.mutateAsync('act1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete action: still referenced')
  })
})

describe('space scoping', () => {
  it('keys actions per space, so two spaces never share a list', () => {
    expect(queryKeys.automationActions('a').lists()).not.toEqual(
      queryKeys.automationActions('b').lists()
    )
  })

  it('keeps actions and automations in separate namespaces', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'automation-actions'])
    expect(automationKeys.all()).toEqual(['spaces', SPACE, 'automations'])
  })

  it('follows a reactive space id', async () => {
    const spaceId = ref(SPACE)
    const local = withSetup(() => useAutomationActions(spaceId).useAutomationActionsQuery())

    await flush()
    spaceId.value = 'space-2'
    await nextTick()
    await flush()

    expect(forSpace).toHaveBeenCalledWith('space-2')
    expect(
      local.queryClient.getQueryData(queryKeys.automationActions('space-2').list({}))
    ).toBeDefined()
    local.unmount()
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
