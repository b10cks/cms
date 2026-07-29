import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useNotifications() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const invalidateAll = () =>
    queryClient.invalidateQueries({ queryKey: queryKeys.notifications.all() })

  const useNotificationsQuery = (params: MaybeRef<NotificationQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.notifications.list(toValue(params))),
      queryFn: async () => {
        const response = await api.notifications.list(toValue(params))
        return response
      },
      placeholderData: keepPreviousData,
    })
  }

  const useUnreadCountQuery = () => {
    return useQuery({
      queryKey: queryKeys.notifications.unreadCount(),
      queryFn: async () => {
        const response = await api.notifications.unreadCount()
        return response.count
      },
    })
  }

  const useMarkAsReadMutation = () => {
    return useMutation({
      mutationFn: (id: string) => api.notifications.markAsRead(id),
      onSuccess: invalidateAll,
      onError: () => toast.error(t('notifications.toast.actionFailed') as string),
    })
  }

  const useMarkAsUnreadMutation = () => {
    return useMutation({
      mutationFn: (id: string) => api.notifications.markAsUnread(id),
      onSuccess: invalidateAll,
      onError: () => toast.error(t('notifications.toast.actionFailed') as string),
    })
  }

  const useMarkAllAsReadMutation = () => {
    return useMutation({
      mutationFn: () => api.notifications.markAllAsRead(),
      onSuccess: invalidateAll,
      onError: () => toast.error(t('notifications.toast.actionFailed') as string),
    })
  }

  const useDeleteNotificationMutation = () => {
    return useMutation({
      mutationFn: (id: string) => api.notifications.remove(id),
      onSuccess: invalidateAll,
      onError: () => toast.error(t('notifications.toast.actionFailed') as string),
    })
  }

  const useClearAllMutation = () => {
    return useMutation({
      mutationFn: () => api.notifications.removeAll(),
      onSuccess: invalidateAll,
      onError: () => toast.error(t('notifications.toast.actionFailed') as string),
    })
  }

  return {
    useNotificationsQuery,
    useUnreadCountQuery,
    useMarkAsReadMutation,
    useMarkAsUnreadMutation,
    useMarkAllAsReadMutation,
    useDeleteNotificationMutation,
    useClearAllMutation,
  }
}
