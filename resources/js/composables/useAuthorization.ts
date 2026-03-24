import { useQuery } from '@tanstack/vue-query'

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
  const teamsResponse = await queryClient.ensureQueryData({
    queryKey: queryKeys.teams.list({ include_space_context: true }),
    queryFn: async () => {
      return await api.teams.index({
        sort: '+name',
        include_space_context: true,
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
    })
  }

  const useAbility = (
    ability: MaybeRef<string>,
    params: MaybeRef<AuthorizationQueryParams> = {}
  ) => {
    const query = useAuthorizationQuery(params)

    return computed(() => {
      const abilities = getAbilitySet(query.data.value)
      return query.data.value?.is_root || abilities.has(toValue(ability))
    })
  }

  const useAccessControl = (
    params: MaybeRef<AuthorizationQueryParams> = {},
    overrides: MaybeRef<Partial<AccessEvaluationContext>> = {}
  ) => {
    const query = useAuthorizationQuery(params)
    const { selectedTeam } = useGlobalTeam()

    const context = computed<AccessEvaluationContext>(() =>
      createAccessEvaluationContext(query.data.value, toValue(params), {
        selectedTeamId: selectedTeam.value?.id ?? null,
        selectedTeamCanCreateSpace: selectedTeam.value?.can_create_space ?? false,
        ...toValue(overrides),
      })
    )

    const abilitySet = computed(() => getAbilitySet(query.data.value))

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
      authorization: computed(() => query.data.value ?? null),
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

  return {
    useAuthorizationQuery,
    useAbility,
    useAccessControl,
  }
}
