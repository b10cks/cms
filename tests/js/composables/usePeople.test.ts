import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const spacePeopleList = vi.fn()
const teamPeople = vi.fn()
const forSpace = vi.fn(() => ({ people: { list: spacePeopleList } }))

vi.mock('~/api', () => ({ api: { forSpace, teams: { getPeople: teamPeople } } }))

/** useAuth pulls in the router; the queries only read isAuthenticated. */
const isAuthenticated = ref(true)
vi.mock('~/composables/useAuth', () => ({ useAuth: () => ({ isAuthenticated }) }))

const { usePeople } = await import('~/composables/usePeople')

const SPACE = 'space-1'
const TEAM = 'team-1'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof usePeople>

let harness: Harness<unknown> | undefined

const setupSpace = (
  ...args: Parameters<Composable['useSpacePeopleQuery']>
): ReturnType<Composable['useSpacePeopleQuery']> => {
  const local = withSetup(() => usePeople().useSpacePeopleQuery(...args))
  harness = local
  return local.result
}

const setupTeam = (
  ...args: Parameters<Composable['useTeamPeopleQuery']>
): ReturnType<Composable['useTeamPeopleQuery']> => {
  const local = withSetup(() => usePeople().useTeamPeopleQuery(...args))
  harness = local
  return local.result
}

beforeEach(() => {
  spacePeopleList.mockReset()
  teamPeople.mockReset()
  forSpace.mockClear()
  isAuthenticated.value = true
  spacePeopleList.mockResolvedValue({ data: [] })
  teamPeople.mockResolvedValue({ data: [] })
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useSpacePeopleQuery', () => {
  it('forwards the params verbatim — people has no default sort', async () => {
    setupSpace(SPACE, { name: 'ada' })
    await flush()

    expect(spacePeopleList).toHaveBeenCalledWith({ name: 'ada' })
  })

  it('passes an empty object when the caller sends nothing', async () => {
    setupSpace(SPACE)
    await flush()

    expect(spacePeopleList).toHaveBeenCalledWith({})
  })

  it('returns the envelope untouched, members and invites together', async () => {
    spacePeopleList.mockResolvedValue({ data: [{ id: 'u1', type: 'member' }], meta: { total: 1 } })

    const query = setupSpace(SPACE)
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'u1', type: 'member' }], meta: { total: 1 } })
  })

  it('caches under the space people list key', async () => {
    setupSpace(SPACE, { page: 2 })
    await flush()

    expect(harness?.queryClient.getQueryData(queryKeys.spacePeople(SPACE).list({ page: 2 }))).toBeDefined()
  })

  it('stays idle while logged out', async () => {
    isAuthenticated.value = false

    const query = setupSpace(SPACE)
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(spacePeopleList).not.toHaveBeenCalled()
  })

  it('stays idle without a space id', async () => {
    const query = setupSpace('')
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('stays idle when disabled', async () => {
    const query = setupSpace(SPACE, {}, false)
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('fetches once authentication arrives', async () => {
    isAuthenticated.value = false

    setupSpace(SPACE)
    await flush()
    isAuthenticated.value = true
    await nextTick()
    await flush()

    expect(spacePeopleList).toHaveBeenCalledTimes(1)
  })
})

describe('useTeamPeopleQuery', () => {
  it('asks the team-scoped endpoint with the team id', async () => {
    setupTeam(TEAM, { role: 'owner' })
    await flush()

    expect(teamPeople).toHaveBeenCalledWith(TEAM, { role: 'owner' })
  })

  it('caches under the team people list key', async () => {
    setupTeam(TEAM)
    await flush()

    expect(harness?.queryClient.getQueryData(queryKeys.teamPeople(TEAM).list({}))).toBeDefined()
  })

  it('stays idle without a team id', async () => {
    const query = setupTeam('')
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(teamPeople).not.toHaveBeenCalled()
  })

  it('stays idle while logged out', async () => {
    isAuthenticated.value = false

    const query = setupTeam(TEAM)
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it('rekeys when the team id ref changes', async () => {
    const teamId = ref(TEAM)

    setupTeam(teamId)
    await flush()
    teamId.value = 'team-2'
    await nextTick()
    await flush()

    expect(teamPeople).toHaveBeenLastCalledWith('team-2', {})
  })
})

describe('query keys', () => {
  it('nests team people under the teams namespace, so a team invalidation reaches them', () => {
    expect(queryKeys.teamPeople(TEAM).all().slice(0, 1)).toEqual([...queryKeys.teams.all()])
  })

  it('nests space people under the spaces namespace', () => {
    expect(queryKeys.spacePeople(SPACE).all().slice(0, 1)).toEqual([...queryKeys.spaces.all()])
  })

  it('lists() prefixes list(filters) for both scopes', () => {
    const spaceList = queryKeys.spacePeople(SPACE).list({ page: 1 })
    const teamList = queryKeys.teamPeople(TEAM).list({ page: 1 })

    expect(spaceList.slice(0, 4)).toEqual([...queryKeys.spacePeople(SPACE).lists()])
    expect(teamList.slice(0, 4)).toEqual([...queryKeys.teamPeople(TEAM).lists()])
  })

  it('does not collide with the space members list, which is keyed separately', () => {
    expect(queryKeys.spacePeople(SPACE).lists()).not.toEqual(queryKeys.spaceMembers(SPACE).lists())
  })
})
