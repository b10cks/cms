import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import type { SpaceBlueprintQueryParams, SpaceBlueprintResource } from '~/api/resources/space-blueprints'
import type { User } from '~/types/users'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const getForTeam = vi.fn()
const getForTeamById = vi.fn()
const create = vi.fn()
const createForTeam = vi.fn()

vi.mock('~/api', () => ({
  api: {
    spaceBlueprints: { index, getForTeam, getForTeamById, create, createForTeam },
  },
}))

const success = vi.fn()
const error = vi.fn()

vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useAuth } = await import('~/composables/useAuth')
const { useSpaceBlueprints } = await import('~/composables/useSpaceBlueprints')

type Blueprints = ReturnType<typeof useSpaceBlueprints>

const blueprint = (over: Partial<SpaceBlueprintResource> = {}): SpaceBlueprintResource =>
  ({
    id: 'bp-1',
    name: 'Marketing',
    team_id: null,
    created_at: '2026-01-01',
    updated_at: '2026-01-01',
    ...over,
  }) as SpaceBlueprintResource

let harness: { unmount: () => void } | undefined

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * vue-query hooks need an injection context, so the query and mutation factories
 * have to run inside the component setup rather than off the returned object.
 */
const setup = <T>(build: (api: Blueprints) => T): Harness<T> => {
  const instance = withSetup(() => build(useSpaceBlueprints()))
  harness = instance

  return instance
}

beforeEach(() => {
  index.mockReset()
  getForTeam.mockReset()
  getForTeamById.mockReset()
  create.mockReset()
  createForTeam.mockReset()
  success.mockReset()
  error.mockReset()
  // Every query is gated on isAuthenticated, which reads the module-level user.
  useAuth().setUser({ id: 'user-1' } as unknown as User)
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  useAuth().setUser(null)
})

describe('useAvailableSpaceBlueprintsQuery', () => {
  it('unwraps the data envelope', async () => {
    index.mockResolvedValue({ data: [blueprint()] })
    const { result } = setup((api) => api.useAvailableSpaceBlueprintsQuery())

    await flush()

    expect(result.data.value).toEqual([blueprint()])
  })

  it('sorts by name unless the caller says otherwise', async () => {
    index.mockResolvedValue({ data: [] })
    setup((api) => api.useAvailableSpaceBlueprintsQuery())

    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
  })

  it('lets the caller override the sort and add filters', async () => {
    index.mockResolvedValue({ data: [] })
    setup((api) => api.useAvailableSpaceBlueprintsQuery({ sort: '-created_at', name: 'Mark' }))

    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at', name: 'Mark' })
  })

  it('caches under a key that includes the filters', async () => {
    index.mockResolvedValue({ data: [blueprint()] })
    const { queryClient } = setup((api) => api.useAvailableSpaceBlueprintsQuery({ name: 'Mark' }))

    await flush()

    expect(
      queryClient.getQueryData(['space-blueprints', 'available', 'list', { name: 'Mark' }])
    ).toEqual([blueprint()])
  })

  it('refetches under a new key when the filters change', async () => {
    index.mockResolvedValue({ data: [] })
    const params = ref<SpaceBlueprintQueryParams>({ name: 'a' })

    setup((api) => api.useAvailableSpaceBlueprintsQuery(params))
    await flush()

    params.value = { name: 'b' }
    await nextTick()
    await flush()

    expect(index).toHaveBeenCalledTimes(2)
    expect(index).toHaveBeenLastCalledWith({ sort: '+name', name: 'b' })
  })

  it('stays idle while nobody is signed in', async () => {
    useAuth().setUser(null)
    const { result } = setup((api) => api.useAvailableSpaceBlueprintsQuery())

    await flush()

    expect(result.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('treats a nullish params value as no filters', async () => {
    index.mockResolvedValue({ data: [] })
    setup((api) =>
      api.useAvailableSpaceBlueprintsQuery(() => null as unknown as SpaceBlueprintQueryParams)
    )

    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
  })
})

describe('useTeamSpaceBlueprintsQuery', () => {
  it('asks the team endpoint and unwraps the envelope', async () => {
    getForTeam.mockResolvedValue({ data: [blueprint({ team_id: 'team-1' })] })
    const { result } = setup((api) => api.useTeamSpaceBlueprintsQuery('team-1'))

    await flush()

    expect(getForTeam).toHaveBeenCalledWith('team-1', { sort: '+name' })
    expect(result.data.value).toEqual([blueprint({ team_id: 'team-1' })])
  })

  it('keys team lists apart from the available list', async () => {
    getForTeam.mockResolvedValue({ data: [] })
    const { queryClient } = setup((api) => api.useTeamSpaceBlueprintsQuery('team-1'))

    await flush()

    expect(queryClient.getQueryData(['space-blueprints', 'team', 'list', 'team-1', {}])).toEqual([])
  })

  it('stays idle without a team id', async () => {
    const { result } = setup((api) => api.useTeamSpaceBlueprintsQuery(null))

    await flush()

    expect(result.fetchStatus.value).toBe('idle')
    expect(getForTeam).not.toHaveBeenCalled()
  })

  it('starts fetching once the team id arrives', async () => {
    getForTeam.mockResolvedValue({ data: [] })
    const teamId = ref<string | null>(null)

    setup((api) => api.useTeamSpaceBlueprintsQuery(teamId))
    await flush()

    teamId.value = 'team-2'
    await nextTick()
    await flush()

    expect(getForTeam).toHaveBeenCalledWith('team-2', { sort: '+name' })
  })
})

describe('useTeamSpaceBlueprintQuery', () => {
  it('fetches one blueprint for a team', async () => {
    getForTeamById.mockResolvedValue({ data: blueprint({ team_id: 'team-1' }) })
    const { result } = setup((api) => api.useTeamSpaceBlueprintQuery('team-1', 'bp-1'))

    await flush()

    expect(getForTeamById).toHaveBeenCalledWith('team-1', 'bp-1')
    expect(result.data.value).toEqual(blueprint({ team_id: 'team-1' }))
  })

  it('keys the detail apart from the list', async () => {
    getForTeamById.mockResolvedValue({ data: blueprint() })
    const { queryClient } = setup((api) => api.useTeamSpaceBlueprintQuery('team-1', 'bp-1'))

    await flush()

    expect(
      queryClient.getQueryData(['space-blueprints', 'team', 'detail', 'team-1', 'bp-1'])
    ).toEqual(blueprint())
  })

  it('stays idle while either id is missing', async () => {
    const { result } = setup((api) => ({
      withoutTeam: api.useTeamSpaceBlueprintQuery(null, 'bp-1'),
      withoutBlueprint: api.useTeamSpaceBlueprintQuery('team-1', null),
    }))

    await flush()

    expect(result.withoutTeam.fetchStatus.value).toBe('idle')
    expect(result.withoutBlueprint.fetchStatus.value).toBe('idle')
    expect(getForTeamById).not.toHaveBeenCalled()
  })

  // The `enabled` gate already covers every missing id, so the queryFn's own
  // 'Team ID is required' / 'Space blueprint ID is required' throws are
  // unreachable — they only narrow the types.
  it('never surfaces the queryFn guards as an error state', async () => {
    const { result } = setup((api) => api.useTeamSpaceBlueprintQuery(null, null))

    await flush()

    expect(result.error.value).toBeNull()
  })
})

describe('useCreateSpaceBlueprintMutation', () => {
  it('posts to the personal endpoint without a team', async () => {
    create.mockResolvedValue({ data: blueprint() })
    const { result } = setup((api) => api.useCreateSpaceBlueprintMutation())

    const created = await result.mutateAsync({ payload: { name: 'Marketing' } })

    expect(create).toHaveBeenCalledWith({ name: 'Marketing' })
    expect(createForTeam).not.toHaveBeenCalled()
    expect(created).toEqual(blueprint())
  })

  it('posts to the team endpoint when the payload names one', async () => {
    createForTeam.mockResolvedValue({ data: blueprint({ team_id: 'team-1' }) })
    const { result } = setup((api) => api.useCreateSpaceBlueprintMutation())

    await result.mutateAsync({ payload: { name: 'Marketing', team_id: 'team-1' } })

    // The team travels in the path, so it is stripped from the body.
    expect(createForTeam).toHaveBeenCalledWith('team-1', { name: 'Marketing' })
    expect(create).not.toHaveBeenCalled()
  })

  it('treats an explicit null team as personal', async () => {
    create.mockResolvedValue({ data: blueprint() })
    const { result } = setup((api) => api.useCreateSpaceBlueprintMutation())

    await result.mutateAsync({ payload: { name: 'Marketing', team_id: null } })

    expect(create).toHaveBeenCalledWith({ name: 'Marketing' })
  })

  it('invalidates every blueprint list on success', async () => {
    create.mockResolvedValue({ data: blueprint() })
    const { result, queryClient } = setup((api) => api.useCreateSpaceBlueprintMutation())
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.mutateAsync({ payload: { name: 'Marketing' } })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['space-blueprints'] })
  })

  // One invalidation covers a team blueprint too: ['space-blueprints'] is a
  // prefix of the team list key, so a second explicit call added nothing.
  it('covers the team list through the same prefix', async () => {
    createForTeam.mockResolvedValue({ data: blueprint({ team_id: 'team-1' }) })
    const { result, queryClient } = setup((api) => api.useCreateSpaceBlueprintMutation())
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.mutateAsync({ payload: { name: 'Marketing', team_id: 'team-1' } })

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['space-blueprints'] })
  })

  it('toasts the created blueprint by name', async () => {
    create.mockResolvedValue({ data: blueprint({ name: 'Marketing' }) })
    const { result } = setup((api) => api.useCreateSpaceBlueprintMutation())

    await result.mutateAsync({ payload: { name: 'Marketing' } })

    expect(success).toHaveBeenCalledWith('Blueprint "Marketing" created successfully')
  })

  it('toasts the failure message and rejects', async () => {
    create.mockRejectedValue(new Error('Name taken'))
    const { result } = setup((api) => api.useCreateSpaceBlueprintMutation())

    await expect(result.mutateAsync({ payload: { name: 'Marketing' } })).rejects.toThrow(
      'Name taken'
    )
    expect(error).toHaveBeenCalledWith('Failed to create blueprint: Name taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const { result } = setup((api) => api.useCreateSpaceBlueprintMutation())

    await expect(result.mutateAsync({ payload: { name: 'Marketing' } })).rejects.toThrow()
    expect(error).toHaveBeenCalledWith('Failed to create blueprint: Unknown error')
  })
})
