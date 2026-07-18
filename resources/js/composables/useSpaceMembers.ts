import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { SpaceMemberQueryParams, UpdateSpaceMemberPayload } from '~/types/spaces'

import { queryKeys } from './useQueryClient'

export function useSpaceMembers() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()

  const useSpaceMembersQuery = (
    spaceId: MaybeRef<string>,
    params: MaybeRef<SpaceMemberQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.spaceMembers(spaceId).list(params)),
      queryFn: async () => {
        return await api.forSpace(toValue(spaceId)).members.list({
          sort: '+firstname',
          ...toValue(params),
        })
      },
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(spaceId) && !!toValue(enabled)
      ),
      placeholderData: keepPreviousData,
    })
  }

  const useUpdateSpaceMemberMutation = () => {
    return useMutation({
      mutationFn: async ({
        spaceId,
        userId,
        payload,
      }: {
        spaceId: string
        userId: string
        payload: UpdateSpaceMemberPayload
      }) => {
        await api.forSpace(spaceId).members.update(userId, payload)
        return { spaceId, userId, role: payload.role }
      },
      onSuccess: ({ spaceId, role }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaceMembers(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.spacePeople(spaceId).lists() })
        toast.success(
          t('composables.spaceMembers.updateUserSuccess', { role: role ?? '' }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaceMembers.updateUserError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRemoveSpaceMemberMutation = () => {
    return useMutation({
      mutationFn: async ({ spaceId, userId }: { spaceId: string; userId: string }) => {
        await api.forSpace(spaceId).members.remove(userId)
        return { spaceId, userId }
      },
      onSuccess: ({ spaceId }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaceMembers(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.spacePeople(spaceId).lists() })
        toast.success(t('composables.spaceMembers.removeUserSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaceMembers.removeUserError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useSpaceMembersQuery,
    useUpdateSpaceMemberMutation,
    useRemoveSpaceMemberMutation,
  }
}
