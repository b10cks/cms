import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { AcceptInvitePayload, CreateInvitePayload, InviteQueryParams } from '~/types/invites'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useInvites() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const usePublicInviteQuery = (token: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.invites.public(token)),
      queryFn: async () => {
        const response = await api.invites.getPublicInvite(toValue(token))
        return response.data
      },
      enabled: computed(() => !!toValue(token)),
    })
  }

  const useMyInvitesQuery = (params: MaybeRef<InviteQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.invites.myList(params)),
      queryFn: async () => {
        const response = await api.invites.listMyInvites(toValue(params))
        return response.data
      },
    })
  }

  const useMyInviteQuery = (inviteId: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.invites.myDetail(inviteId)),
      queryFn: async () => {
        const response = await api.invites.getMyInvite(toValue(inviteId))
        return response.data
      },
    })
  }

  const useSpaceInvitesQuery = (
    spaceId: MaybeRef<string>,
    params: MaybeRef<InviteQueryParams> = {}
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.invites.spaceList(spaceId, params)),
      queryFn: async () => {
        const response = await api.invites.listSpaceInvites(toValue(spaceId), toValue(params))
        return response
      },
    })
  }

  const useTeamInvitesQuery = (
    teamId: MaybeRef<string>,
    params: MaybeRef<InviteQueryParams> = {}
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.invites.teamList(teamId, params)),
      queryFn: async () => {
        const response = await api.invites.listTeamInvites(toValue(teamId), toValue(params))
        return response
      },
    })
  }

  const useCreateSpaceInviteMutation = () => {
    return useMutation({
      mutationFn: async ({
        spaceId,
        payload,
      }: {
        spaceId: string
        payload: CreateInvitePayload
      }) => {
        const response = await api.invites.createSpaceInvite(spaceId, payload)
        return { spaceId, invite: response.data }
      },
      onSuccess: ({ spaceId, invite }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.invites.spaceList(spaceId) })
        toast.success(t('labels.invites.toast.sent', { email: invite.email }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.sendFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteSpaceInviteMutation = () => {
    return useMutation({
      mutationFn: async ({ spaceId, inviteId }: { spaceId: string; inviteId: string }) => {
        await api.invites.deleteSpaceInvite(spaceId, inviteId)
        return { spaceId, inviteId }
      },
      onSuccess: ({ spaceId }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.invites.spaceList(spaceId) })
        toast.success(t('labels.invites.toast.revoked') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.revokeFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useResendSpaceInviteMutation = () => {
    return useMutation({
      mutationFn: async ({ spaceId, inviteId }: { spaceId: string; inviteId: string }) => {
        const response = await api.invites.resendSpaceInvite(spaceId, inviteId)
        return { spaceId, invite: response.data }
      },
      onSuccess: ({ invite }) => {
        toast.success(t('labels.invites.toast.resent', { email: invite.email }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.resendFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Team Invites Mutations
  const useCreateTeamInviteMutation = () => {
    return useMutation({
      mutationFn: async ({ teamId, payload }: { teamId: string; payload: CreateInvitePayload }) => {
        const response = await api.invites.createTeamInvite(teamId, payload)
        return { teamId, invite: response.data }
      },
      onSuccess: ({ teamId, invite }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.invites.teamList(teamId) })
        toast.success(t('labels.invites.toast.sent', { email: invite.email }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.sendFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteTeamInviteMutation = () => {
    return useMutation({
      mutationFn: async ({ teamId, inviteId }: { teamId: string; inviteId: string }) => {
        await api.invites.deleteTeamInvite(teamId, inviteId)
        return { teamId, inviteId }
      },
      onSuccess: ({ teamId }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.invites.teamList(teamId) })
        toast.success(t('labels.invites.toast.revoked') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.revokeFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useResendTeamInviteMutation = () => {
    return useMutation({
      mutationFn: async ({ teamId, inviteId }: { teamId: string; inviteId: string }) => {
        const response = await api.invites.resendTeamInvite(teamId, inviteId)
        return { teamId, invite: response.data }
      },
      onSuccess: ({ invite }) => {
        toast.success(t('labels.invites.toast.resent', { email: invite.email }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.resendFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Accept Invite Mutation
  const useAcceptInviteMutation = () => {
    return useMutation({
      mutationFn: async ({
        inviteId,
        payload,
      }: {
        inviteId: string
        payload: AcceptInvitePayload
      }) => {
        const response = await api.invites.acceptInvite(inviteId, payload)
        return response.data
      },
      onSuccess: (invite) => {
        const targetName = invite.space?.name || invite.team?.name || 'resource'
        toast.success(t('labels.invites.toast.joined', { name: targetName }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.invites.toast.acceptFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Public Queries
    usePublicInviteQuery,

    // User Invites Queries
    useMyInvitesQuery,
    useMyInviteQuery,

    // Space Invites Queries
    useSpaceInvitesQuery,

    // Team Invites Queries
    useTeamInvitesQuery,

    // Space Invites Mutations
    useCreateSpaceInviteMutation,
    useDeleteSpaceInviteMutation,
    useResendSpaceInviteMutation,

    // Team Invites Mutations
    useCreateTeamInviteMutation,
    useDeleteTeamInviteMutation,
    useResendTeamInviteMutation,

    // Accept Invite Mutation
    useAcceptInviteMutation,
  }
}
