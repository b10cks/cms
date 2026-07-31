import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const forSpace = vi.fn(() => ({ auditLogs: { index } }))

vi.mock('~/api', () => ({ api: { forSpace } }))

const { useAuditLogs } = await import('~/composables/useAuditLogs')

const SPACE = 'space-1'
const keys = queryKeys.auditLogs(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Query = ReturnType<ReturnType<typeof useAuditLogs>['useAuditLogsQuery']>

let harness: Harness<Query> | undefined

const setup = (
  params: Parameters<ReturnType<typeof useAuditLogs>['useAuditLogsQuery']>[0] = {},
  enabled: MaybeRef<boolean> = true,
  spaceId: MaybeRef<string> = SPACE
) => {
  harness = withSetup<Query>(() => useAuditLogs(spaceId).useAuditLogsQuery(params, enabled))
  return harness.result
}

beforeEach(() => {
  index.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [], meta: { total: 0 } })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useAuditLogsQuery', () => {
  it('defaults to newest first', async () => {
    setup()
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('lets the caller override the sort', async () => {
    setup({ sort: '+created_at' })
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+created_at' })
  })

  it('forwards filters alongside the default sort', async () => {
    setup({ owner: 'u1', page: 2 })
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at', owner: 'u1', page: 2 })
  })

  it('keeps the paginated envelope, not just the rows', async () => {
    index.mockResolvedValue({ data: [{ id: 'l1' }], meta: { total: 1 } })

    const query = setup()
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'l1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    setup({ page: 3 })
    await flush()

    expect(harness?.queryClient.getQueryData(keys.list({ page: 3 }))).toBeDefined()
  })

  it('scopes the client to the space', async () => {
    setup({}, true, 'space-9')
    await flush()

    expect(forSpace).toHaveBeenCalledWith('space-9')
  })

  it('stays idle without a space id', async () => {
    const query = setup({}, true, '')
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when disabled', async () => {
    const query = setup({}, false)
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('starts fetching once the enabled ref flips', async () => {
    const enabled = ref(false)

    setup({}, enabled)
    await flush()
    expect(index).not.toHaveBeenCalled()

    enabled.value = true
    await nextTick()
    await flush()

    expect(index).toHaveBeenCalledTimes(1)
  })

  it('refetches a seeded key rather than trusting it — audit logs are never stale-cached', async () => {
    const local = withSetup(() => useAuditLogs(SPACE).useAuditLogsQuery(), {
      seed: [[keys.list({}), { data: [{ id: 'stale' }] }]],
    })
    await flush()

    expect(index).toHaveBeenCalledTimes(1)
    local.unmount()
  })

  it('rekeys when the params ref changes', async () => {
    const params = ref({ page: 1 })

    setup(params)
    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    expect(harness?.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
  })

  it('keeps the previous page cached, so keepPreviousData has something to show', async () => {
    const params = ref({ page: 1 })

    setup(params)
    await flush()
    params.value = { page: 2 }
    await nextTick()
    await flush()

    // With gcTime: 0 the unobserved page-1 entry would be evicted immediately and the
    // table would flash empty on every page change.
    expect(harness?.queryClient.getQueryData(keys.list({ page: 1 }))).toBeDefined()
  })

  it('lists() prefixes list(filters), so an invalidation would match every page', () => {
    const list = keys.list({ page: 2 })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('keeps audit logs of two spaces apart', () => {
    expect(queryKeys.auditLogs('a').all()).not.toEqual(queryKeys.auditLogs('b').all())
  })
})
