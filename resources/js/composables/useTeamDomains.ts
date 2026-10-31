import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { UpdateTeamDomainPayload } from '~/types/team-domains'

import { queryKeys } from './useQueryClient'

const domainKeys = {
  domains: (teamId: MaybeRef<string>) => [...queryKeys.teams.detail(teamId), 'domains'] as const,
  joinRequests: (teamId: MaybeRef<string>) =>
    [...queryKeys.teams.detail(teamId), 'join-requests'] as const,
  joinable: () => ['users', 'me', 'join-requests'] as const,
}

/** Verified team domains, the join requests they produce, and the requester's side of both. */
export function useTeamDomains() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()

  const failed = (error: Error) => {
    toast.error(t('components.teamDomains.toast.failed', { error: error.message }))
  }

  const useTeamDomainsQuery = (teamId: MaybeRef<string>, enabled: MaybeRef<boolean> = true) =>
    useQuery({
      queryKey: computed(() => domainKeys.domains(teamId)),
      queryFn: async () => (await api.teamDomains.list(toValue(teamId))).data,
      enabled: computed(() => !!toValue(isAuthenticated) && !!toValue(teamId) && toValue(enabled)),
    })

  const invalidateDomains = (teamId: string) =>
    queryClient.invalidateQueries({ queryKey: domainKeys.domains(teamId) })

  const useAddTeamDomainMutation = () =>
    useMutation({
      mutationFn: ({ teamId, domain }: { teamId: string; domain: string }) =>
        api.teamDomains.add(teamId, domain),
      onSuccess: (_, { teamId }) => invalidateDomains(teamId),
      onError: failed,
    })

  /** A missing TXT record is an expected answer, so the caller shows it inline instead of a toast. */
  const useVerifyTeamDomainMutation = () =>
    useMutation({
      mutationFn: ({ teamId, domainId }: { teamId: string; domainId: string }) =>
        api.teamDomains.verify(teamId, domainId),
      onSuccess: (response, { teamId }) => {
        invalidateDomains(teamId)
        toast.success(
          t('components.teamDomains.toast.verified', { domain: response.data.display_domain })
        )
      },
    })

  const useUpdateTeamDomainMutation = () =>
    useMutation({
      mutationFn: ({
        teamId,
        domainId,
        payload,
      }: {
        teamId: string
        domainId: string
        payload: UpdateTeamDomainPayload
      }) => api.teamDomains.updateDomain(teamId, domainId, payload),
      onSuccess: (_, { teamId }) => invalidateDomains(teamId),
      onError: (error: Error, { teamId }) => {
        invalidateDomains(teamId)
        failed(error)
      },
    })

  const useRemoveTeamDomainMutation = () =>
    useMutation({
      mutationFn: ({ teamId, domainId }: { teamId: string; domainId: string }) =>
        api.teamDomains.remove(teamId, domainId),
      onSuccess: (_, { teamId }) => invalidateDomains(teamId),
      onError: failed,
    })

  const useTeamJoinRequestsQuery = (teamId: MaybeRef<string>, enabled: MaybeRef<boolean> = true) =>
    useQuery({
      queryKey: computed(() => domainKeys.joinRequests(teamId)),
      queryFn: async () => (await api.teamDomains.joinRequests(toValue(teamId))).data,
      enabled: computed(() => !!toValue(isAuthenticated) && !!toValue(teamId) && toValue(enabled)),
    })

  const useDecideJoinRequestMutation = () =>
    useMutation({
      mutationFn: ({
        teamId,
        requestId,
        decision,
        role,
      }: {
        teamId: string
        requestId: string
        decision: 'approve' | 'decline'
        role?: string
      }) =>
        decision === 'approve'
          ? api.teamDomains.approve(teamId, requestId, role)
          : api.teamDomains.decline(teamId, requestId),
      onSuccess: (_, { teamId, decision }) => {
        queryClient.invalidateQueries({ queryKey: domainKeys.joinRequests(teamId) })
        if (decision === 'approve') {
          queryClient.invalidateQueries({ queryKey: queryKeys.teams.detail(teamId) })
        }
      },
      onError: failed,
    })

  const useJoinableTeamsQuery = () =>
    useQuery({
      queryKey: domainKeys.joinable(),
      queryFn: async () => (await api.teamDomains.joinable()).data,
      enabled: computed(() => !!toValue(isAuthenticated)),
    })

  const useRequestToJoinMutation = () =>
    useMutation({
      mutationFn: (teamId: string) => api.teamDomains.requestToJoin(teamId),
      onSuccess: () => queryClient.invalidateQueries({ queryKey: domainKeys.joinable() }),
      onError: failed,
    })

  const useCancelJoinRequestMutation = () =>
    useMutation({
      mutationFn: (requestId: string) => api.teamDomains.cancelRequest(requestId),
      onSuccess: () => queryClient.invalidateQueries({ queryKey: domainKeys.joinable() }),
      onError: failed,
    })

  return {
    useTeamDomainsQuery,
    useAddTeamDomainMutation,
    useVerifyTeamDomainMutation,
    useUpdateTeamDomainMutation,
    useRemoveTeamDomainMutation,
    useTeamJoinRequestsQuery,
    useDecideJoinRequestMutation,
    useJoinableTeamsQuery,
    useRequestToJoinMutation,
    useCancelJoinRequestMutation,
  }
}
