import { keepPreviousData, useQuery } from '@tanstack/vue-query'

import { api } from '~/api'
import {
  canAccessNavigationItem,
  canAccessRequirement,
  canAccessRouteByName,
  filterNavigationItems,
  firstAllowedRouteForSpace as firstAllowedSpaceRoute,
  firstAllowedRouteForTeam as firstAllowedTeamRoute,
  getAbilitySet,
  getRouteAccessRequirement,
  type AccessEvaluationContext,
  type NavigationAccessItem,
  type RouteAccessRequirement,
} from '~/lib/access-control'
import { GLOBAL_TEAM_QUERY_PARAMS } from '~/lib/global-team'
import { queryClient } from '~/plugins/vue-query'
import type { AuthorizationPayload, AuthorizationQueryParams } from '~/types/authorization'

import { queryKeys } from './useQueryClient'

interface SelectedTeamAccess {
  teamId: string | null
  canCreateSpace: boolean
}

const SELECTED_TEAM_STORAGE_KEY = 'global-team'

function getStoredSelectedTeamId() {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    const raw = window.localStorage.getItem(SELECTED_TEAM_STORAGE_KEY)

    if (!raw) {
      return null
    }

    const parsed = JSON.parse(raw) as { selectedTeamId?: string | null }
    return typeof parsed.selectedTeamId === 'string' ? parsed.selectedTeamId : null
  } catch {
    return null
  }
}

export async function ensureAuthorizationContext(params: AuthorizationQueryParams = {}) {
  return queryClient.ensureQueryData({
    queryKey: queryKeys.authorization.context(params),
    queryFn: async () => {
      const response = await api.authorization.get(params)
      return response.data
    },
  })
}

export async function ensureSelectedTeamAccess(): Promise<SelectedTeamAccess> {
  // Same key and same params as `useGlobalTeam`: the guard and the team switcher
  // have to resolve the selection from one cache entry, or a paginated-away team
  // makes them disagree about which team is selected.
  const teamsResponse = await queryClient.ensureQueryData({
    queryKey: queryKeys.teams.list(GLOBAL_TEAM_QUERY_PARAMS),
    queryFn: async () => {
      return await api.teams.index({
        sort: '+name',
        ...GLOBAL_TEAM_QUERY_PARAMS,
      })
    },
  })

  const teams = teamsResponse.data ?? []
  const storedSelectedTeamId = getStoredSelectedTeamId()
  const selectedTeam = teams.find((team) => team.id === storedSelectedTeamId) ?? teams[0] ?? null

  return {
    teamId: selectedTeam?.id ?? null,
    canCreateSpace: !!selectedTeam?.can_create_space,
  }
}

/**
 * `keepPreviousData` keeps the payload of the space the user just left around
 * while the next one loads. That is what stops the nav from blanking out on a
 * team/global switch, but for a space scope it would answer ability checks from
 * *another* space's abilities — so a space with fewer rights briefly renders as
 * permitted. Treat such a payload as unresolved instead.
 */
function resolveScopedAuthorization(
  authorization: AuthorizationPayload | undefined,
  params: AuthorizationQueryParams
): AuthorizationPayload | null {
  if (!authorization) {
    return null
  }

  const loadedSpaceId = authorization.space?.id

  if (params.space_id && loadedSpaceId && loadedSpaceId !== params.space_id) {
    return null
  }

  return authorization
}

export function createAccessEvaluationContext(
  authorization?: AuthorizationPayload | null,
  params?: AuthorizationQueryParams,
  overrides?: Partial<AccessEvaluationContext>
): AccessEvaluationContext {
  return {
    authorization,
    teamId: params?.team_id ?? null,
    spaceId: params?.space_id ?? null,
    ...overrides,
  }
}

export function useAuthorization() {
  const { isAuthenticated } = useAuth()

  const useAuthorizationQuery = (params: MaybeRef<AuthorizationQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.authorization.context(toValue(params))),
      queryFn: async () => {
        const response = await api.authorization.get(toValue(params))
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
      // Keep the previous context while a space/team switch loads the next
      // one, so ability-gated UI (sidebar nav) doesn't blank out in between.
      placeholderData: keepPreviousData,
    })
  }

  const useAccessControl = (
    params: MaybeRef<AuthorizationQueryParams> = {},
    overrides: MaybeRef<Partial<AccessEvaluationContext>> = {}
  ) => {
    const query = useAuthorizationQuery(params)
    const { selectedTeam } = useGlobalTeam()

    const authorization = computed(() =>
      resolveScopedAuthorization(query.data.value, toValue(params))
    )

    const context = computed<AccessEvaluationContext>(() =>
      createAccessEvaluationContext(authorization.value, toValue(params), {
        selectedTeamId: selectedTeam.value?.id ?? null,
        selectedTeamCanCreateSpace: selectedTeam.value?.can_create_space ?? false,
        ...toValue(overrides),
      })
    )

    const abilitySet = computed(() => getAbilitySet(authorization.value))

    const hasAbility = (ability: string) => {
      return canAccessRequirement({ abilities: ability }, context.value)
    }

    const hasAnyAbility = (abilities: string[]) => {
      return canAccessRequirement({ abilities: { anyOf: abilities } }, context.value)
    }

    const hasAllAbilities = (abilities: string[]) => {
      return canAccessRequirement({ abilities: { allOf: abilities } }, context.value)
    }

    const canAccessRoute = (
      route:
        | string
        | { name?: string | null }
        | RouteAccessRequirement
        | NavigationAccessItem
        | null
        | undefined
    ) => {
      if (!route) {
        return true
      }

      if (typeof route === 'string') {
        return canAccessRouteByName(route, context.value)
      }

      if ('routeName' in route) {
        return canAccessNavigationItem(route, context.value)
      }

      if ('name' in route) {
        return canAccessRouteByName(route.name ?? '', context.value)
      }

      return canAccessRequirement(route as RouteAccessRequirement, context.value)
    }

    const filterVisibleItems = <T extends NavigationAccessItem>(items: T[]) => {
      return filterNavigationItems(items, context.value)
    }

    const firstAllowedRouteForSpace = (spaceId: string) => {
      return firstAllowedSpaceRoute(spaceId, context.value)
    }

    const firstAllowedRouteForTeam = (teamId: string) => {
      return firstAllowedTeamRoute(teamId, context.value)
    }

    return {
      query,
      authorization,
      abilitySet,
      context,
      hasAbility,
      hasAnyAbility,
      hasAllAbilities,
      canAccessRoute,
      filterVisibleItems,
      firstAllowedRouteForSpace,
      firstAllowedRouteForTeam,
      getRouteAccessRequirement,
    }
  }

  // Built on `useAccessControl` so both ability APIs answer identically: the same
  // scope guard, the same selected-team context, the same requirement evaluation.
  const useAbility = (
    ability: MaybeRef<string>,
    params: MaybeRef<AuthorizationQueryParams> = {}
  ) => {
    const { hasAbility } = useAccessControl(params)

    return computed(() => hasAbility(toValue(ability)))
  }

  return {
    useAuthorizationQuery,
    useAbility,
    useAccessControl,
  }
}
