import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { MaybeRefOrGetter } from 'vue'
import { computed, toValue } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type {
  CreateSpaceBlueprintPayload,
  SpaceBlueprintQueryParams,
  SpaceBlueprintResource,
} from '~/api/resources/space-blueprints'

const spaceBlueprintQueryKeys = {
  all: () => ['space-blueprints'] as const,
  availableLists: () => [...spaceBlueprintQueryKeys.all(), 'available', 'list'] as const,
  availableList: (filters: SpaceBlueprintQueryParams = {}) =>
    [...spaceBlueprintQueryKeys.availableLists(), filters] as const,
  teamLists: () => [...spaceBlueprintQueryKeys.all(), 'team', 'list'] as const,
  teamList: (teamId: string, filters: SpaceBlueprintQueryParams = {}) =>
    [...spaceBlueprintQueryKeys.teamLists(), teamId, filters] as const,
  teamDetails: () => [...spaceBlueprintQueryKeys.all(), 'team', 'detail'] as const,
  teamDetail: (teamId: string, blueprintId: string) =>
    [...spaceBlueprintQueryKeys.teamDetails(), teamId, blueprintId] as const,
}

export function useSpaceBlueprints() {
  const { t } = useI18n()
  const { isAuthenticated } = useAuth()
  const queryClient = useQueryClient()

  const useAvailableSpaceBlueprintsQuery = (
    params: MaybeRefOrGetter<SpaceBlueprintQueryParams> = {}
  ) => {
    return useQuery({
      queryKey: computed(() => spaceBlueprintQueryKeys.availableList(toValue(params) ?? {})),
      queryFn: async (): Promise<SpaceBlueprintResource[]> => {
        const response = await api.spaceBlueprints.index({
          sort: '+name',
          ...toValue(params),
        })

        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
      placeholderData: keepPreviousData,
    })
  }

  const useTeamSpaceBlueprintsQuery = (
    teamId: MaybeRefOrGetter<string | null | undefined>,
    params: MaybeRefOrGetter<SpaceBlueprintQueryParams> = {}
  ) => {
    return useQuery({
      queryKey: computed(() =>
        spaceBlueprintQueryKeys.teamList(toValue(teamId) || '', toValue(params) ?? {})
      ),
      queryFn: async (): Promise<SpaceBlueprintResource[]> => {
        const resolvedTeamId = toValue(teamId)

        if (!resolvedTeamId) {
          throw new Error('Team ID is required')
        }

        const response = await api.spaceBlueprints.getForTeam(resolvedTeamId, {
          sort: '+name',
          ...toValue(params),
        })

        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated) && !!toValue(teamId)),
      placeholderData: keepPreviousData,
    })
  }

  const useTeamSpaceBlueprintQuery = (
    teamId: MaybeRefOrGetter<string | null | undefined>,
    blueprintId: MaybeRefOrGetter<string | null | undefined>
  ) => {
    return useQuery({
      queryKey: computed(() =>
        spaceBlueprintQueryKeys.teamDetail(toValue(teamId) || '', toValue(blueprintId) || '')
      ),
      queryFn: async (): Promise<SpaceBlueprintResource> => {
        const resolvedTeamId = toValue(teamId)
        const resolvedBlueprintId = toValue(blueprintId)

        if (!resolvedTeamId) {
          throw new Error('Team ID is required')
        }

        if (!resolvedBlueprintId) {
          throw new Error('Space blueprint ID is required')
        }

        const response = await api.spaceBlueprints.getForTeamById(
          resolvedTeamId,
          resolvedBlueprintId
        )

        return response.data
      },
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(teamId) && !!toValue(blueprintId)
      ),
    })
  }

  const useCreateSpaceBlueprintMutation = () => {
    return useMutation({
      mutationFn: async ({
        payload,
      }: {
        payload: CreateSpaceBlueprintPayload
      }): Promise<SpaceBlueprintResource> => {
        const teamId = payload.team_id ?? null
        const response = teamId
          ? await api.spaceBlueprints.createForTeam(teamId, payload)
          : await api.spaceBlueprints.create(payload)

        return response.data
      },
      onSuccess: (blueprint) => {
        // `all()` is a prefix of every blueprint key, team lists included.
        queryClient.invalidateQueries({ queryKey: spaceBlueprintQueryKeys.all() })

        toast.success(
          t('composables.spaceBlueprints.createSuccess', {
            name: blueprint.name,
          }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaceBlueprints.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useAvailableSpaceBlueprintsQuery,
    useTeamSpaceBlueprintsQuery,
    useTeamSpaceBlueprintQuery,
    useCreateSpaceBlueprintMutation,
  }
}
