import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

/**
 * Queries and mutations of the team workspace: overview, move preview,
 * activity, access matrix and access end dates. Everything is keyed under the
 * team's detail key, so invalidating the team refreshes all of it.
 */
export function useTeamWorkspace() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()

  const isEnabled = (teamId: MaybeRef<string>, enabled: MaybeRef<boolean>) =>
    computed(() => !!toValue(isAuthenticated) && !!toValue(teamId) && toValue(enabled))

  const useTeamOverviewQuery = (teamId: MaybeRef<string>, enabled: MaybeRef<boolean> = true) =>
    useQuery({
      queryKey: computed(() => queryKeys.teams.overview(teamId)),
      queryFn: async () => (await api.teams.getOverview(toValue(teamId))).data,
      enabled: isEnabled(teamId, enabled),
    })

  const useTeamMovePreviewQuery = (
    teamId: MaybeRef<string | null>,
    parentId: MaybeRef<string | null>
  ) =>
    useQuery({
      queryKey: computed(() => ['teams', 'move-preview', toValue(teamId), toValue(parentId)]),
      queryFn: async () =>
        (await api.teams.getMovePreview(toValue(teamId) as string, toValue(parentId))).data,
      enabled: computed(() => !!toValue(isAuthenticated) && !!toValue(teamId)),
      staleTime: 0,
      gcTime: 0,
    })

  const useTeamAuditLogsQuery = (
    teamId: MaybeRef<string>,
    params: MaybeRef<{ page?: number; per_page?: number }> = {},
    enabled: MaybeRef<boolean> = true
  ) =>
    useQuery({
      queryKey: computed(() => queryKeys.teams.auditLogs(teamId, toValue(params))),
      queryFn: async () => api.teams.getAuditLogs(toValue(teamId), toValue(params)),
      enabled: isEnabled(teamId, enabled),
      placeholderData: keepPreviousData,
    })

  const useAccessMatrixQuery = (
    teamId: MaybeRef<string>,
    filterTeamId: MaybeRef<string | null>,
    enabled: MaybeRef<boolean> = true
  ) =>
    useQuery({
      queryKey: computed(() => queryKeys.teams.accessMatrix(teamId, toValue(filterTeamId))),
      queryFn: async () =>
        (await api.teams.getAccessMatrix(toValue(teamId), toValue(filterTeamId))).data,
      enabled: isEnabled(teamId, enabled),
      placeholderData: keepPreviousData,
    })

  const invalidateTeams = (...teamIds: string[]) => {
    queryClient.invalidateQueries({ queryKey: queryKeys.teams.lists() })
    queryClient.invalidateQueries({ queryKey: queryKeys.teams.hierarchy() })
    queryClient.invalidateQueries({ queryKey: queryKeys.authorization.all() })
    for (const teamId of teamIds) {
      queryClient.invalidateQueries({ queryKey: queryKeys.teams.detail(teamId) })
      queryClient.invalidateQueries({ queryKey: queryKeys.teamPeople(teamId).lists() })
    }
  }

  const showError = (error: Error) =>
    toast.error(
      t('labels.teamWorkspace.toast.error', { error: error.message || 'Unknown error' }) as string
    )

  /** Moves a team through the regular team update, then refreshes the workspace it was moved in. */
  const useMoveTeamMutation = () =>
    useMutation({
      mutationFn: async (input: { teamId: string; parentId: string; scopeTeamId: string }) => {
        await api.teams.update(input.teamId, { parent_id: input.parentId })
        return input
      },
      onSuccess: ({ teamId, scopeTeamId }) => {
        invalidateTeams(teamId, scopeTeamId)
        toast.success(t('labels.teamWorkspace.move.success') as string)
      },
      onError: showError,
    })

  const useSetAccessExpiryMutation = () =>
    useMutation({
      mutationFn: async (input: {
        kind: 'team' | 'space'
        resourceId: string
        userId: string
        accessUntil: string | null
        teamId?: string
      }) => {
        if (input.kind === 'team') {
          await api.teams.setUserExpiry(input.resourceId, input.userId, input.accessUntil)
        } else {
          await api.forSpace(input.resourceId).members.setExpiry(input.userId, input.accessUntil)
        }
        return input
      },
      onSuccess: ({ kind, resourceId, teamId }) => {
        if (kind === 'team') {
          invalidateTeams(resourceId)
        } else {
          queryClient.invalidateQueries({ queryKey: queryKeys.spacePeople(resourceId).lists() })
          if (teamId) invalidateTeams(teamId)
        }
        toast.success(t('labels.teamWorkspace.expiry.saved') as string)
      },
      onError: showError,
    })

  /** Adds, changes or clears a direct space role from the access matrix. */
  const useSetSpaceRoleMutation = () =>
    useMutation({
      mutationFn: async (input: {
        teamId: string
        spaceId: string
        userId: string
        role: string | null
        isMember: boolean
      }) => {
        const members = api.forSpace(input.spaceId).members
        if (!input.isMember && input.role) {
          await members.add(input.userId, input.role)
        } else if (input.role) {
          await members.update(input.userId, { role: input.role })
        } else {
          await members.remove(input.userId)
        }
        return input
      },
      onSuccess: ({ teamId, spaceId }) => {
        invalidateTeams(teamId)
        queryClient.invalidateQueries({ queryKey: queryKeys.spacePeople(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.spaceMembers(spaceId).lists() })
        toast.success(t('labels.teamWorkspace.matrix.saved') as string)
      },
      onError: showError,
    })

  return {
    useTeamOverviewQuery,
    useTeamMovePreviewQuery,
    useTeamAuditLogsQuery,
    useAccessMatrixQuery,
    useMoveTeamMutation,
    useSetAccessExpiryMutation,
    useSetSpaceRoleMutation,
  }
}
