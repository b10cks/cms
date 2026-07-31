import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Release } from '~/types/releases'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const releases = {
  index: vi.fn(),
  getDetail: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  commit: vi.fn(),
  cancel: vi.fn(),
  publish: vi.fn(),
  delete: vi.fn(),
  assignVersions: vi.fn(),
  removeVersions: vi.fn(),
}

const success = vi.fn()
const failure = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ releases }) } }))
vi.mock('vue-sonner', () => ({ toast: { success, error: failure } }))

const { useReleases } = await import('~/composables/useReleases')

const SPACE = 'space-1'

const release = (overrides: Partial<Release> = {}) =>
  ({
    id: 'r1',
    name: 'Spring launch',
    publish_at: '2026-08-01T00:00:00Z',
    committed_at: null,
    published_at: null,
    ...overrides,
  }) as unknown as Release

let harness: Harness<ReturnType<typeof mountReleases>> | undefined

const mountReleases = () => {
  const composable = useReleases(SPACE)

  return {
    ...composable,
    create: composable.useCreateReleaseMutation(),
    update: composable.useUpdateReleaseMutation(),
    commit: composable.useCommitReleaseMutation(),
    cancel: composable.useCancelReleaseMutation(),
    publish: composable.usePublishReleaseMutation(),
    remove: composable.useDeleteReleaseMutation(),
    assign: composable.useAssignVersionsMutation(),
    unassign: composable.useRemoveVersionsMutation(),
  }
}

const setup = () => {
  harness = withSetup(mountReleases)
  return harness
}

const mutations = () => setup().result

const spyInvalidate = () => vi.spyOn((harness as Harness<unknown>).queryClient, 'invalidateQueries')

const invalidatedKeys = (spy: ReturnType<typeof spyInvalidate>) =>
  spy.mock.calls.map(([filters]) => (typeof filters === 'function' ? filters() : filters)?.queryKey)

beforeEach(() => {
  for (const fn of Object.values(releases)) fn.mockReset()
  success.mockReset()
  failure.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useReleasesQuery', () => {
  it('caches the whole envelope under the list key', async () => {
    const response = { data: [release()], meta: { total: 1 } }
    releases.index.mockResolvedValue(response)

    const { queryClient } = withSetup(() => useReleases(SPACE).useReleasesQuery())

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.releases(SPACE).list({}))).toEqual(response)
    )
    expect(releases.index).toHaveBeenCalledWith({})
    queryClient.clear()
  })

  it('forwards the caller params verbatim', async () => {
    releases.index.mockResolvedValue({ data: [] })
    const params = { filter: { state: 'draft' } }

    const { queryClient } = withSetup(() => useReleases(SPACE).useReleasesQuery(params))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.releases(SPACE).list(params))).toBeDefined()
    )
    expect(releases.index).toHaveBeenCalledWith(params)
    queryClient.clear()
  })

  it('stays disabled while the caller says so', () => {
    const { queryClient } = withSetup(() => useReleases(SPACE).useReleasesQuery({}, false))

    expect(releases.index).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled without a space id', () => {
    const { queryClient } = withSetup(() => useReleases('').useReleasesQuery())

    expect(releases.index).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useReleaseQuery', () => {
  it('unwraps the envelope under the detail key', async () => {
    releases.getDetail.mockResolvedValue({ data: release() })

    const { queryClient } = withSetup(() => useReleases(SPACE).useReleaseQuery('r1'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.releases(SPACE).detail('r1'))).toMatchObject({
        id: 'r1',
      })
    )
    queryClient.clear()
  })

  it('stays disabled for an empty id', () => {
    const { queryClient } = withSetup(() => useReleases(SPACE).useReleaseQuery(''))

    expect(releases.getDetail).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled while the caller says so', () => {
    const { queryClient } = withSetup(() => useReleases(SPACE).useReleaseQuery('r1', false))

    expect(releases.getDetail).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useCreateReleaseMutation', () => {
  it('invalidates only the release lists, since the entry is new', async () => {
    releases.create.mockResolvedValue({ data: release() })
    const { create } = mutations()
    const invalidate = spyInvalidate()

    await create.mutateAsync({ name: 'Spring launch' } as never)

    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.releases(SPACE).lists()])
    expect(success).toHaveBeenCalledWith('Release "Spring launch" created successfully')
  })

  it('reports failure', async () => {
    releases.create.mockRejectedValue(new Error('name taken'))

    await expect(mutations().create.mutateAsync({} as never)).rejects.toThrow('name taken')
    expect(failure).toHaveBeenCalledWith('Failed to create release: name taken')
  })

  it('falls back to "Unknown error" for an empty message', async () => {
    releases.create.mockRejectedValue(new Error(''))

    await expect(mutations().create.mutateAsync({} as never)).rejects.toThrow()
    expect(failure).toHaveBeenCalledWith('Failed to create release: Unknown error')
  })
})

describe('state-changing mutations', () => {
  const cases = [
    ['update', 'update', 'Release "Spring launch" updated successfully', 'Failed to update release: nope'],
    ['commit', 'commit', 'Release "Spring launch" committed successfully', 'Failed to commit release: nope'],
    ['cancel', 'cancel', 'Release "Spring launch" cancelled successfully', 'Failed to cancel release: nope'],
    [
      'publish',
      'publish',
      'Release "Spring launch" published successfully',
      'Failed to publish release: nope',
    ],
  ] as const

  const call = (name: (typeof cases)[number][0], mutation: { mutateAsync: (input: never) => Promise<unknown> }) =>
    name === 'update'
      ? mutation.mutateAsync({ id: 'r1', payload: { name: 'x' } } as never)
      : mutation.mutateAsync('r1' as never)

  it.each(cases)('%s invalidates the lists and the release detail', async (name, endpoint) => {
    releases[endpoint].mockResolvedValue({ data: release() })
    const mutation = mutations()[name]
    const invalidate = spyInvalidate()

    await call(name, mutation)

    // publish reaches further — it also refreshes the content caches.
    expect(invalidatedKeys(invalidate).slice(0, 2)).toEqual([
      queryKeys.releases(SPACE).lists(),
      queryKeys.releases(SPACE).detail('r1'),
    ])
  })

  it.each(cases)('%s reports success', async (name, endpoint, message) => {
    releases[endpoint].mockResolvedValue({ data: release() })

    await call(name, mutations()[name])

    expect(success).toHaveBeenCalledWith(message)
  })

  it.each(cases)('%s reports failure', async (name, endpoint, _message, error) => {
    releases[endpoint].mockRejectedValue(new Error('nope'))

    await expect(call(name, mutations()[name])).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith(error)
  })

  it.each(cases)('%s keys the invalidation off the response id', async (name, endpoint) => {
    // A stale id in the caller's hand would leave the wrong detail cached; the
    // composable always follows the server's answer.
    releases[endpoint].mockResolvedValue({ data: release({ id: 'server-id' }) })
    const mutation = mutations()[name]
    const invalidate = spyInvalidate()

    await call(name, mutation)

    expect(invalidatedKeys(invalidate)).toContainEqual(queryKeys.releases(SPACE).detail('server-id'))
  })

  it('refreshes the content caches after a release publishes', async () => {
    // Publishing a release publishes every content version it holds, so the
    // lists, the open details and the menu all describe stale states.
    releases.publish.mockResolvedValue({ data: release({ published_at: 'now' }) })
    const { publish } = mutations()
    const invalidate = spyInvalidate()

    await publish.mutateAsync('r1')

    const keys = invalidatedKeys(invalidate)
    expect(keys).toContainEqual(queryKeys.contents(SPACE).lists())
    expect(keys).toContainEqual(queryKeys.contents(SPACE).details())
    expect(keys).toContainEqual(queryKeys.contentMenu(SPACE).all())
  })
})

describe('useDeleteReleaseMutation', () => {
  it('drops the detail cache and invalidates the lists', async () => {
    releases.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync('r1')

    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.releases(SPACE).lists()])
    expect(removeQueries).toHaveBeenCalledWith({ queryKey: queryKeys.releases(SPACE).detail('r1') })
    expect(success).toHaveBeenCalledWith('Release deleted successfully')
  })

  it('really evicts the seeded detail', async () => {
    releases.delete.mockResolvedValue(undefined)
    const detail = queryKeys.releases(SPACE).detail('r1')

    harness = withSetup(mountReleases, { seed: [[detail, release()]] })

    await harness.result.remove.mutateAsync('r1')

    expect(harness.queryClient.getQueryData(detail)).toBeUndefined()
  })

  it('reports failure', async () => {
    releases.delete.mockRejectedValue(new Error('committed'))

    await expect(mutations().remove.mutateAsync('r1')).rejects.toThrow('committed')
    expect(failure).toHaveBeenCalledWith('Failed to delete release: committed')
  })
})

describe('useAssignVersionsMutation', () => {
  it('invalidates the lists and the detail and counts the assigned versions', async () => {
    releases.assignVersions.mockResolvedValue({
      data: release({ versions: [{ id: 'v1' }, { id: 'v2' }] } as never),
    })
    const { assign } = mutations()
    const invalidate = spyInvalidate()

    await assign.mutateAsync({ releaseId: 'r1', payload: { version_ids: ['v1', 'v2'] } as never })

    expect(releases.assignVersions).toHaveBeenCalledWith('r1', { version_ids: ['v1', 'v2'] })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.releases(SPACE).lists(),
      queryKeys.releases(SPACE).detail('r1'),
    ])
    expect(success).toHaveBeenCalledWith('2 version(s) added to release "Spring launch"')
  })

  it('counts an empty assignment as zero, not one', async () => {
    // The count comes from the request, so an empty assignment can say so —
    // the response carries the release, never the versions just assigned.
    releases.assignVersions.mockResolvedValue({ data: release({ versions: [] } as never) })

    await mutations().assign.mutateAsync({ releaseId: 'r1', payload: { version_ids: [] } })

    expect(success).toHaveBeenCalledWith('0 version(s) added to release "Spring launch"')
  })

  it('counts the request even when the response omits versions entirely', async () => {
    releases.assignVersions.mockResolvedValue({ data: release() })

    await mutations().assign.mutateAsync({ releaseId: 'r1', payload: { version_ids: ['v1'] } })

    expect(success).toHaveBeenCalledWith('1 version(s) added to release "Spring launch"')
  })

  it('reports failure', async () => {
    releases.assignVersions.mockRejectedValue(new Error('already committed'))

    await expect(
      mutations().assign.mutateAsync({ releaseId: 'r1', payload: { version_ids: [] } })
    ).rejects.toThrow('already committed')
    expect(failure).toHaveBeenCalledWith('Failed to assign versions: already committed')
  })
})

describe('useRemoveVersionsMutation', () => {
  it('invalidates the lists and the detail without counting', async () => {
    releases.removeVersions.mockResolvedValue({ data: release() })
    const { unassign } = mutations()
    const invalidate = spyInvalidate()

    await unassign.mutateAsync({ releaseId: 'r1', payload: { version_ids: ['v1'] } as never })

    expect(releases.removeVersions).toHaveBeenCalledWith('r1', { version_ids: ['v1'] })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.releases(SPACE).lists(),
      queryKeys.releases(SPACE).detail('r1'),
    ])
    expect(success).toHaveBeenCalledWith('Version(s) removed from release "Spring launch"')
  })

  it('reports failure', async () => {
    releases.removeVersions.mockRejectedValue(new Error('nope'))

    await expect(
      mutations().unassign.mutateAsync({ releaseId: 'r1', payload: {} as never })
    ).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to remove versions: nope')
  })
})

describe('getReleaseState', () => {
  const state = (overrides: Partial<Release>) => mutations().getReleaseState(release(overrides))

  it('is published once published_at is set, whatever else says', () => {
    expect(state({ published_at: '2026-01-01T00:00:00Z', committed_at: null })).toBe('published')
  })

  it('is a draft while it has not been committed', () => {
    expect(state({ committed_at: null })).toBe('draft')
  })

  it('is scheduled while the publish date is in the future', () => {
    expect(
      state({ committed_at: '2026-01-01T00:00:00Z', publish_at: '2999-01-01T00:00:00Z' })
    ).toBe('scheduled')
  })

  it('is pending once the publish date has passed', () => {
    expect(
      state({ committed_at: '2026-01-01T00:00:00Z', publish_at: '2000-01-01T00:00:00Z' })
    ).toBe('pending')
  })

  it('is pending at exactly the publish moment', () => {
    const now = new Date('2026-07-01T12:00:00Z')
    vi.useFakeTimers()
    vi.setSystemTime(now)

    try {
      expect(state({ committed_at: '2026-01-01T00:00:00Z', publish_at: now.toISOString() })).toBe(
        'pending'
      )
    } finally {
      vi.useRealTimers()
    }
  })

  it('calls a committed release with a null publish date "pending"', () => {
    // Committed without a date means ready to publish now.
    expect(state({ committed_at: '2026-01-01T00:00:00Z', publish_at: null as never })).toBe(
      'pending'
    )
  })

  it('treats an undefined publish date exactly like a null one', () => {
    // Both empty values are one state; the raw Date comparison used to split
    // them (epoch vs Invalid Date) into "pending" and "scheduled".
    expect(state({ committed_at: '2026-01-01T00:00:00Z', publish_at: undefined as never })).toBe(
      'pending'
    )
    expect(state({ committed_at: '2026-01-01T00:00:00Z', publish_at: '' })).toBe('pending')
  })
})
