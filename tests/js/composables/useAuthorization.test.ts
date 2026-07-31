import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import type { AuthorizationPayload, AuthorizationQueryParams } from '~/types/authorization'
import type { TeamResource } from '~/types/teams'

const authorizationGet = vi.fn()
const teamsIndex = vi.fn()

vi.mock('~/api', () => ({
  api: {
    authorization: { get: authorizationGet },
    teams: { index: teamsIndex },
  },
}))

// `~/composables/useAuth` (auto-imported by useAuthorization) pulls in the real
// router, which would instantiate every page chunk. Nothing under test navigates.
vi.mock('~/router', () => ({
  router: {
    currentRoute: { value: { query: {}, meta: {}, fullPath: '/' } },
    push: vi.fn(),
  },
}))

vi.mock('~/plugins/posthog', () => ({
  getPosthog: () => ({ identify: vi.fn(), reset: vi.fn(), capture: vi.fn() }),
}))

const { useAuth } = await import('~/composables/useAuth')
const {
  createAccessEvaluationContext,
  ensureAuthorizationContext,
  ensureSelectedTeamAccess,
  useAuthorization,
} = await import('~/composables/useAuthorization')
const { queryKeys } = await import('~/composables/useQueryClient')
const { spaceNavigationItems } = await import('~/lib/access-control')
const { queryClient } = await import('~/plugins/vue-query')

const { withSetup } = await import('../support/harness')
type Harness<T> = import('../support/harness').Harness<T>

// This jsdom build exposes no localStorage, and both `useGlobalTeam` and
// `getStoredSelectedTeamId` read the selected team from it. One shared instance,
// cleared per test, so nothing vueuse captured goes stale.
const memoryStorage = (() => {
  const store = new Map<string, string>()

  return {
    get length() {
      return store.size
    },
    key: (index: number) => [...store.keys()][index] ?? null,
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => void store.set(key, String(value)),
    removeItem: (key: string) => void store.delete(key),
    clear: () => store.clear(),
  } as Storage
})()

Object.defineProperty(window, 'localStorage', { value: memoryStorage, configurable: true })

const payload = (
  overrides: Partial<AuthorizationPayload> & {
    spaceAbilities?: string[]
    teamAbilities?: string[]
  } = {}
): AuthorizationPayload => {
  const { spaceAbilities = [], teamAbilities = [], ...rest } = overrides

  return {
    user_id: 'user-1',
    is_root: false,
    teams: [],
    spaces: [],
    team: { id: 'team-1', role_keys: [], abilities: teamAbilities },
    space: { id: 'space-1', team_role_keys: [], abilities: spaceAbilities },
    roles: { team: [], space: [] },
    ...rest,
  }
}

const team = (id: string, canCreateSpace = false) =>
  ({ id, name: id, can_create_space: canCreateSpace }) as unknown as TeamResource

/** The exact params object `useGlobalTeam` hands `useTeamsQuery`. */
const TEAMS_PARAMS = { include_space_context: true, per_page: 1000 }

const teamsSeed = (teams: TeamResource[]): [readonly unknown[], unknown] => [
  queryKeys.teams.list(TEAMS_PARAMS),
  { data: teams },
]

let harness: Harness<unknown> | undefined

const mountAccessControl = (
  {
    authorization,
    params = {},
    teams = [team('team-1')],
    overrides,
  }: {
    authorization?: AuthorizationPayload
    params?: AuthorizationQueryParams
    teams?: TeamResource[]
    overrides?: Partial<import('~/lib/access-control').AccessEvaluationContext>
  } = {}
) => {
  const seed: Array<[readonly unknown[], unknown]> = [teamsSeed(teams)]

  if (authorization) {
    seed.push([queryKeys.authorization.context(params), authorization])
  }

  const created = withSetup(
    () => useAuthorization().useAccessControl(params, overrides ?? {}),
    { seed }
  )

  harness = created

  return created.result
}

beforeEach(() => {
  authorizationGet.mockReset()
  teamsIndex.mockReset()
  window.localStorage.clear()
  queryClient.clear()
  // The authorization query is `enabled` only for a signed-in user.
  useAuth().setUser({ id: 'user-1', email: 'a@b.test' } as never)
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  useAuth().setUser(null)
})

describe('createAccessEvaluationContext', () => {
  it('carries the query params through as the team/space scope', () => {
    const auth = payload()

    expect(
      createAccessEvaluationContext(auth, { team_id: 'team-9', space_id: 'space-9' })
    ).toEqual({
      authorization: auth,
      teamId: 'team-9',
      spaceId: 'space-9',
    })
  })

  it('nulls the scope when no params are given', () => {
    expect(createAccessEvaluationContext(null)).toEqual({
      authorization: null,
      teamId: null,
      spaceId: null,
    })
  })

  it('lets overrides win over the params', () => {
    const context = createAccessEvaluationContext(null, { team_id: 'team-9' }, {
      teamId: 'team-override',
      selectedTeamCanCreateSpace: true,
    })

    expect(context.teamId).toBe('team-override')
    expect(context.selectedTeamCanCreateSpace).toBe(true)
  })
})

describe('ensureAuthorizationContext', () => {
  it('unwraps the response envelope and caches it under the params key', async () => {
    const auth = payload({ spaceAbilities: ['content.view'] })
    authorizationGet.mockResolvedValue({ data: auth })

    expect(await ensureAuthorizationContext({ space_id: 'space-1' })).toEqual(auth)
    expect(authorizationGet).toHaveBeenCalledWith({ space_id: 'space-1' })
    expect(queryClient.getQueryData(queryKeys.authorization.context({ space_id: 'space-1' }))).toEqual(
      auth
    )
  })

  it('serves a second call from the cache', async () => {
    authorizationGet.mockResolvedValue({ data: payload() })

    await ensureAuthorizationContext()
    await ensureAuthorizationContext()

    expect(authorizationGet).toHaveBeenCalledTimes(1)
  })

  it('keeps a different space scope on its own cache entry', async () => {
    authorizationGet.mockImplementation(async (params: AuthorizationQueryParams) => ({
      data: payload({ spaceAbilities: [`${params.space_id}.view`] }),
    }))

    const first = await ensureAuthorizationContext({ space_id: 'space-1' })
    const second = await ensureAuthorizationContext({ space_id: 'space-2' })

    expect(first.space?.abilities).toEqual(['space-1.view'])
    expect(second.space?.abilities).toEqual(['space-2.view'])
    expect(authorizationGet).toHaveBeenCalledTimes(2)
  })
})

describe('ensureSelectedTeamAccess', () => {
  it('resolves the team stored by useGlobalTeam', async () => {
    window.localStorage.setItem('global-team', JSON.stringify({ selectedTeamId: 'team-2' }))
    teamsIndex.mockResolvedValue({ data: [team('team-1'), team('team-2', true)] })

    expect(await ensureSelectedTeamAccess()).toEqual({ teamId: 'team-2', canCreateSpace: true })
  })

  it('falls back to the first team when the stored id is not a team any more', async () => {
    window.localStorage.setItem('global-team', JSON.stringify({ selectedTeamId: 'gone' }))
    teamsIndex.mockResolvedValue({ data: [team('team-1'), team('team-2', true)] })

    expect(await ensureSelectedTeamAccess()).toEqual({ teamId: 'team-1', canCreateSpace: false })
  })

  it.each([
    ['no stored value', null],
    ['unparseable JSON', '{'],
    ['a non-string team id', JSON.stringify({ selectedTeamId: 42 })],
  ])('falls back to the first team for %s', async (_label, stored) => {
    if (stored !== null) {
      window.localStorage.setItem('global-team', stored)
    }
    teamsIndex.mockResolvedValue({ data: [team('team-7', true)] })

    expect(await ensureSelectedTeamAccess()).toEqual({ teamId: 'team-7', canCreateSpace: true })
  })

  it('reports no team and no space creation when the user has no teams', async () => {
    teamsIndex.mockResolvedValue({ data: [] })

    expect(await ensureSelectedTeamAccess()).toEqual({ teamId: null, canCreateSpace: false })
  })

  it('tolerates a response without a data array', async () => {
    teamsIndex.mockResolvedValue({})

    expect(await ensureSelectedTeamAccess()).toEqual({ teamId: null, canCreateSpace: false })
  })

  it('requests the space context so can_create_space is populated', async () => {
    teamsIndex.mockResolvedValue({ data: [] })

    await ensureSelectedTeamAccess()

    expect(teamsIndex).toHaveBeenCalledWith({ sort: '+name', ...TEAMS_PARAMS })
  })

  it('reads the team list from the entry useGlobalTeam populates', async () => {
    // One key for both: the guard used to fall back to `teams[0]` of a
    // default-paginated response, which can name a different team than the one
    // the switcher shows.
    queryClient.setQueryData(queryKeys.teams.list(TEAMS_PARAMS), {
      data: [team('team-5', true)],
    })

    expect(await ensureSelectedTeamAccess()).toEqual({ teamId: 'team-5', canCreateSpace: true })
    expect(teamsIndex).not.toHaveBeenCalled()
  })
})

describe('useAbility', () => {
  const mountAbility = (
    ability: string | ReturnType<typeof ref<string>>,
    authorization?: AuthorizationPayload
  ) => {
    const created = withSetup(() => useAuthorization().useAbility(ability as never), {
      seed: authorization ? [[queryKeys.authorization.context({}), authorization]] : [],
    })

    harness = created

    return created.result
  }

  it('grants a space ability', () => {
    expect(mountAbility('content.view', payload({ spaceAbilities: ['content.view'] })).value).toBe(
      true
    )
  })

  it('grants a team ability — space and team abilities are one merged set', () => {
    expect(mountAbility('team.update', payload({ teamAbilities: ['team.update'] })).value).toBe(true)
  })

  it('denies an ability nobody granted', () => {
    expect(mountAbility('content.manage', payload({ spaceAbilities: ['content.view'] })).value).toBe(
      false
    )
  })

  it('denies a near-miss ability name rather than prefix-matching it', () => {
    const granted = payload({ spaceAbilities: ['content.view'] })

    expect(mountAbility('content', granted).value).toBe(false)
    expect(mountAbility('content.view.all', granted).value).toBe(false)
  })

  it('grants everything to a root user', () => {
    expect(mountAbility('anything.at.all', payload({ is_root: true })).value).toBe(true)
  })

  it('denies while the authorization context has not resolved yet', () => {
    authorizationGet.mockReturnValue(new Promise(() => {}))

    expect(mountAbility('content.view').value).toBe(false)
  })

  it('denies while the loaded context belongs to another space', () => {
    // `useAbility` answers through the same evaluation as `useAccessControl`, so
    // the cross-space guard applies to it too.
    const created = withSetup(
      () => useAuthorization().useAbility('content.view', { space_id: 'space-2' }),
      {
        seed: [
          [
            queryKeys.authorization.context({ space_id: 'space-2' }),
            payload({ spaceAbilities: ['content.view'] }),
          ],
        ],
      }
    )
    harness = created

    expect(created.result.value).toBe(false)
  })

  it('re-evaluates when the ability ref changes', async () => {
    const ability = ref('content.view')
    const allowed = mountAbility(ability, payload({ spaceAbilities: ['content.view'] }))

    expect(allowed.value).toBe(true)

    ability.value = 'content.manage'
    await nextTick()

    expect(allowed.value).toBe(false)
  })
})

describe('useAccessControl abilities', () => {
  it('exposes the merged ability set and the raw payload', () => {
    const auth = payload({ spaceAbilities: ['content.view'], teamAbilities: ['team.update'] })
    const access = mountAccessControl({ authorization: auth })

    expect([...access.abilitySet.value].sort()).toEqual(['content.view', 'team.update'])
    expect(access.authorization.value).toEqual(auth)
  })

  it('reports a null payload while the context is unresolved', () => {
    authorizationGet.mockReturnValue(new Promise(() => {}))

    const access = mountAccessControl()

    expect(access.authorization.value).toBeNull()
    expect(access.abilitySet.value.size).toBe(0)
  })

  it('gates hasAbility on the exact ability', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['content.view'] }),
    })

    expect(access.hasAbility('content.view')).toBe(true)
    expect(access.hasAbility('content.manage')).toBe(false)
  })

  it.each([
    ['hasAbility', (a: ReturnType<typeof mountAccessControl>) => a.hasAbility('content.view')],
    ['hasAnyAbility', (a: ReturnType<typeof mountAccessControl>) => a.hasAnyAbility(['content.view'])],
    [
      'hasAllAbilities',
      (a: ReturnType<typeof mountAccessControl>) => a.hasAllAbilities(['content.view']),
    ],
  ])('denies %s with no authorization context at all', (_label, check) => {
    authorizationGet.mockReturnValue(new Promise(() => {}))

    expect(check(mountAccessControl())).toBe(false)
  })

  it('lets root through every check', () => {
    const access = mountAccessControl({ authorization: payload({ is_root: true }) })

    expect(access.hasAbility('nope.at.all')).toBe(true)
    expect(access.hasAnyAbility(['nope.at.all'])).toBe(true)
    expect(access.hasAllAbilities(['nope.at.all', 'also.nope'])).toBe(true)
  })

  it('needs one of hasAnyAbility', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['automations.view'] }),
    })

    expect(access.hasAnyAbility(['automations.view', 'automation_actions.view'])).toBe(true)
    expect(access.hasAnyAbility(['blocks.view', 'assets.view'])).toBe(false)
  })

  it('needs all of hasAllAbilities', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['content.view'], teamAbilities: ['blocks.view'] }),
    })

    expect(access.hasAllAbilities(['content.view', 'blocks.view'])).toBe(true)
    expect(access.hasAllAbilities(['content.view', 'blocks.manage'])).toBe(false)
  })

  it('denies an empty ability list — no named ability means nothing to satisfy', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['content.view'] }),
    })

    expect(access.hasAnyAbility([])).toBe(false)
    expect(access.hasAllAbilities([])).toBe(false)
  })
})

describe('useAccessControl canAccessRoute', () => {
  it('allows a nullish route — nothing to gate', () => {
    const access = mountAccessControl({ authorization: payload() })

    expect(access.canAccessRoute(null)).toBe(true)
    expect(access.canAccessRoute(undefined)).toBe(true)
  })

  it('gates a route name on its declared requirement', () => {
    const access = mountAccessControl({ authorization: payload({ spaceAbilities: ['assets.view'] }) })

    expect(access.canAccessRoute('space-assets-index')).toBe(true)
    expect(access.canAccessRoute('space-blocks-index')).toBe(false)
  })

  it('gates a route location object by its name', () => {
    const access = mountAccessControl({ authorization: payload({ spaceAbilities: ['assets.view'] }) })

    expect(access.canAccessRoute({ name: 'space-assets-index' })).toBe(true)
    expect(access.canAccessRoute({ name: 'space-blocks-index' })).toBe(false)
  })

  it('allows a route location with no name — an unnamed route has no requirement', () => {
    const access = mountAccessControl({ authorization: payload() })

    expect(access.canAccessRoute({ name: null })).toBe(true)
  })

  it('allows a route with no declared requirement', () => {
    const access = mountAccessControl({ authorization: payload() })

    expect(access.canAccessRoute('login')).toBe(true)
    expect(access.canAccessRoute('does-not-exist')).toBe(true)
  })

  it('gates a navigation item through its visibility routes', () => {
    const settings = spaceNavigationItems.find((item) => item.routeName === 'space-settings-index')!
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['backups.view'] }),
    })

    // Not space.update, but a settings sub-page the user can reach.
    expect(access.canAccessRoute(settings)).toBe(true)
    expect(
      mountAccessControl({
        authorization: payload({ spaceAbilities: ['content.view'] }),
      }).canAccessRoute(settings)
    ).toBe(false)
  })

  it('evaluates a bare requirement object', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['content.view'] }),
    })

    expect(access.canAccessRoute({ abilities: 'content.view' })).toBe(true)
    expect(access.canAccessRoute({ abilities: { allOf: ['content.view', 'blocks.view'] } })).toBe(
      false
    )
  })

  it('gates the provider dashboard on root', () => {
    expect(mountAccessControl({ authorization: payload() }).canAccessRoute('provider-dashboard')).toBe(
      false
    )
    expect(
      mountAccessControl({ authorization: payload({ is_root: true }) }).canAccessRoute(
        'provider-dashboard'
      )
    ).toBe(true)
  })

  it('exposes the raw requirement lookup', () => {
    const access = mountAccessControl({ authorization: payload() })

    expect(access.getRouteAccessRequirement('space-assets-index')).toEqual({
      abilities: 'assets.view',
    })
    expect(access.getRouteAccessRequirement('does-not-exist')).toBeNull()
  })
})

describe('useAccessControl and the selected team', () => {
  it("lets the selected team's can_create_space open spaces-new without any ability", () => {
    const access = mountAccessControl({
      authorization: payload(),
      teams: [team('team-1', true)],
    })

    expect(access.context.value.selectedTeamCanCreateSpace).toBe(true)
    expect(access.canAccessRoute('spaces-new')).toBe(true)
  })

  it('denies spaces-new when the selected team cannot create spaces', () => {
    const access = mountAccessControl({
      authorization: payload(),
      teams: [team('team-1', false)],
    })

    expect(access.canAccessRoute('spaces-new')).toBe(false)
  })

  it('still allows spaces-new on the team.spaces.create ability alone', () => {
    const access = mountAccessControl({
      authorization: payload({ teamAbilities: ['team.spaces.create'] }),
      teams: [team('team-1', false)],
    })

    expect(access.canAccessRoute('spaces-new')).toBe(true)
  })

  it('honours the stored team selection rather than the first team', () => {
    window.localStorage.setItem('global-team', JSON.stringify({ selectedTeamId: 'team-2' }))

    const access = mountAccessControl({
      authorization: payload(),
      teams: [team('team-1', false), team('team-2', true)],
    })

    expect(access.context.value.selectedTeamId).toBe('team-2')
    expect(access.canAccessRoute('spaces-new')).toBe(true)
  })

  it('reports no selected team when the user has none', () => {
    const access = mountAccessControl({ authorization: payload(), teams: [] })

    expect(access.context.value.selectedTeamId).toBeNull()
    expect(access.context.value.selectedTeamCanCreateSpace).toBe(false)
  })

  it('lets an explicit override replace the derived team flags', () => {
    const access = mountAccessControl({
      authorization: payload(),
      teams: [team('team-1', true)],
      overrides: { selectedTeamCanCreateSpace: false },
    })

    expect(access.canAccessRoute('spaces-new')).toBe(false)
  })
})

describe('useAccessControl navigation helpers', () => {
  it('keeps only the navigation items the abilities allow', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['assets.view', 'icons.view'] }),
    })

    expect(access.filterVisibleItems(spaceNavigationItems).map((item) => item.routeName)).toEqual([
      'space-assets-index',
      'space-icons-index',
    ])
  })

  it('keeps every navigation item for root', () => {
    const access = mountAccessControl({ authorization: payload({ is_root: true }) })

    expect(access.filterVisibleItems(spaceNavigationItems)).toHaveLength(spaceNavigationItems.length)
  })

  it('drops every navigation item without a context', () => {
    authorizationGet.mockReturnValue(new Promise(() => {}))

    expect(mountAccessControl().filterVisibleItems(spaceNavigationItems)).toEqual([])
  })

  it('picks the first reachable space route in navigation order', () => {
    const access = mountAccessControl({
      authorization: payload({ spaceAbilities: ['audit_logs.view', 'assets.view'] }),
    })

    expect(access.firstAllowedRouteForSpace('space-9')).toEqual({
      name: 'space-assets-index',
      params: { space: 'space-9' },
    })
  })

  it('picks the first reachable team tab', () => {
    const access = mountAccessControl({
      authorization: payload({ teamAbilities: ['team.saml.manage'] }),
    })

    expect(access.firstAllowedRouteForTeam('team-9')).toEqual({
      name: 'team-saml',
      params: { team: 'team-9' },
    })
  })

  it('returns null when nothing is reachable', () => {
    const access = mountAccessControl({ authorization: payload() })

    expect(access.firstAllowedRouteForSpace('space-9')).toBeNull()
    expect(access.firstAllowedRouteForTeam('team-9')).toBeNull()
  })
})

describe('useAccessControl reactivity', () => {
  it('re-evaluates when the authorization payload arrives', async () => {
    let resolve: ((value: unknown) => void) | undefined
    authorizationGet.mockReturnValue(
      new Promise((res) => {
        resolve = res as (value: unknown) => void
      })
    )

    const access = mountAccessControl()

    expect(access.hasAbility('content.view')).toBe(false)

    resolve?.({ data: payload({ spaceAbilities: ['content.view'] }) })
    await vi.waitUntil(() => access.authorization.value !== null)

    expect(access.hasAbility('content.view')).toBe(true)
  })

  it('never answers from the previous space’s abilities while the next space loads', async () => {
    // `placeholderData: keepPreviousData` still serves space-1's payload here, but
    // its space id no longer matches the requested one, so it must not gate the
    // UI of the space being opened.
    authorizationGet.mockReturnValue(new Promise(() => {}))

    const params = ref<AuthorizationQueryParams>({ space_id: 'space-1' })
    const created = withSetup(() => useAuthorization().useAccessControl(params), {
      seed: [
        teamsSeed([team('team-1')]),
        [
          queryKeys.authorization.context({ space_id: 'space-1' }),
          payload({ spaceAbilities: ['content.manage'] }),
        ],
      ],
    })
    harness = created

    expect(created.result.hasAbility('content.manage')).toBe(true)

    params.value = { space_id: 'space-2' }
    await nextTick()
    await nextTick()

    expect(created.result.context.value.spaceId).toBe('space-2')
    expect(created.result.hasAbility('content.manage')).toBe(false)
    expect(created.result.authorization.value).toBeNull()
    expect(created.result.filterVisibleItems(spaceNavigationItems)).toEqual([])
  })

  it('keeps the previous payload while a team/global switch loads — the nav must not blank out', async () => {
    authorizationGet.mockReturnValue(new Promise(() => {}))

    const params = ref<AuthorizationQueryParams>({ team_id: 'team-1' })
    const created = withSetup(() => useAuthorization().useAccessControl(params), {
      seed: [
        teamsSeed([team('team-1')]),
        [
          queryKeys.authorization.context({ team_id: 'team-1' }),
          payload({ teamAbilities: ['team.update'] }),
        ],
      ],
    })
    harness = created

    params.value = { team_id: 'team-2' }
    await nextTick()
    await nextTick()

    expect(created.result.hasAbility('team.update')).toBe(true)
  })

  it('answers from the loaded payload once it matches the requested space', async () => {
    authorizationGet.mockResolvedValue({
      data: payload({ space: { id: 'space-2', team_role_keys: [], abilities: ['content.view'] } }),
    })

    const params = ref<AuthorizationQueryParams>({ space_id: 'space-2' })
    const created = withSetup(() => useAuthorization().useAccessControl(params), {
      seed: [teamsSeed([team('team-1')])],
    })
    harness = created

    await vi.waitUntil(() => created.result.authorization.value !== null)

    expect(created.result.hasAbility('content.view')).toBe(true)
  })

  it('does not fetch the authorization context for a signed-out user', () => {
    useAuth().setUser(null)
    mountAccessControl()

    expect(authorizationGet).not.toHaveBeenCalled()
  })
})
