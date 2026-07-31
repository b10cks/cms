import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const replay = vi.fn()

const forSpace = vi.fn(() => ({
  automationExecutions: { index, replay },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAutomationExecutions } = await import('~/composables/useAutomationExecutions')

const SPACE = 'space-1'
const keys = queryKeys.automationExecutions(SPACE)
const automationKeys = queryKeys.automations(SPACE)
const actionKeys = queryKeys.automationActions(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useAutomationExecutions>
type Mutations = {
  replay: ReturnType<Composable['useReplayAutomationExecutionMutation']>
}

let harness: Harness<Mutations> | undefined

const setup = (spaceId: MaybeRef<string> = SPACE, seed?: Array<[readonly unknown[], unknown]>) => {
  harness = withSetup<Mutations>(
    () => ({
      replay: useAutomationExecutions(spaceId).useReplayAutomationExecutionMutation(),
    }),
    { seed }
  )
  return harness.result
}

beforeEach(() => {
  for (const fn of [index, replay, success, error]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useAutomationExecutionsQuery', () => {
  it('sends the caller params verbatim — there is no default sort', async () => {
    withSetup(() => useAutomationExecutions(SPACE).useAutomationExecutionsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({})
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('forwards the filters it was given', async () => {
    withSetup(() =>
      useAutomationExecutions(SPACE).useAutomationExecutionsQuery({
        automation_id: 'a1',
        status: 'failed',
      })
    )
    await flush()

    expect(index).toHaveBeenCalledWith({ automation_id: 'a1', status: 'failed' })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'x1' }], meta: { total: 1 } })

    const query = withSetup(() =>
      useAutomationExecutions(SPACE).useAutomationExecutionsQuery()
    ).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'x1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const local = withSetup(() =>
      useAutomationExecutions(SPACE).useAutomationExecutionsQuery({ status: 'failed' })
    )
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ status: 'failed' }))).toBeDefined()
    local.unmount()
  })

  it('stays idle while the space id is empty', async () => {
    const query = withSetup(() => useAutomationExecutions('').useAutomationExecutionsQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })
    const local = withSetup(() =>
      useAutomationExecutions(SPACE).useAutomationExecutionsQuery(params)
    )

    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(local.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
    local.unmount()
  })

  // There is no execution detail query: the module exposes the list only.
  it('exposes exactly the list query and the replay mutation', () => {
    const local = withSetup(() => useAutomationExecutions(SPACE))

    expect(Object.keys(local.result)).toEqual([
      'useAutomationExecutionsQuery',
      'useReplayAutomationExecutionMutation',
    ])
    local.unmount()
  })
})

describe('useReplayAutomationExecutionMutation', () => {
  it('fans the invalidation out over executions, automations and actions', async () => {
    replay.mockResolvedValue({ data: { id: 'x2', status: 'queued' } })
    const { replay: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    const result = await mutation.mutateAsync('x1')

    expect(replay).toHaveBeenCalledWith('x1')
    expect(result).toEqual({ id: 'x2', status: 'queued' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: automationKeys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: actionKeys.lists() })
    expect(invalidate).toHaveBeenCalledTimes(3)
  })

  // The replay creates a *new* execution, so nothing is keyed off the response.
  it('invalidates only lists, never a detail key', async () => {
    replay.mockResolvedValue({ data: { id: 'x2' } })
    const { replay: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync('x1')

    for (const call of invalidate.mock.calls) {
      expect((call[0] as { queryKey: readonly unknown[] }).queryKey).not.toContain('detail')
    }
  })

  it('reports the replay as queued, not completed', async () => {
    replay.mockResolvedValue({ data: { id: 'x2' } })
    const { replay: mutation } = setup()

    await mutation.mutateAsync('x1')

    expect(success).toHaveBeenCalledWith('Execution was queued for replay successfully')
  })

  it('reports the failure reason', async () => {
    replay.mockRejectedValue(new Error('payload no longer valid'))
    const { replay: mutation } = setup()

    await expect(mutation.mutateAsync('x1')).rejects.toThrow('payload no longer valid')
    expect(error).toHaveBeenCalledWith('Failed to replay execution: payload no longer valid')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    replay.mockRejectedValue(new Error(''))
    const { replay: mutation } = setup()

    await mutation.mutateAsync('x1').catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to replay execution: Unknown error')
  })

  it('does not invalidate when the replay fails', async () => {
    replay.mockRejectedValue(new Error('nope'))
    const { replay: mutation } = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync('x1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
  })
})

describe('space scoping', () => {
  it('keys executions per space, so two spaces never share a list', () => {
    expect(queryKeys.automationExecutions('a').lists()).not.toEqual(
      queryKeys.automationExecutions('b').lists()
    )
  })

  it('invalidates only the current space on replay', async () => {
    replay.mockResolvedValue({ data: { id: 'x2' } })
    const { replay: mutation } = setup('space-2')
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutation.mutateAsync('x1')

    expect(invalidate).toHaveBeenCalledWith({
      queryKey: queryKeys.automationExecutions('space-2').lists(),
    })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.lists() })
  })

  it('lists() is a prefix of list(filters), so invalidation actually matches', () => {
    const list = keys.list({ page: 3 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
