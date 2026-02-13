import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useComments(spaceId: MaybeRef<string>, contentId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const commentsAPI = computed(() => api.forSpace(toValue(spaceId)).comments(toValue(contentId)))

  const useCommentsQuery = (params: MaybeRef<CommentsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.comments(spaceId, contentId).list(params)),
      queryFn: async () => {
        const response = await commentsAPI.value.index(toValue(params))
        return response.data
      },
    })
  }

  const useCommentQuery = (commentId: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.comments(spaceId, contentId).detail(commentId)),
      queryFn: async () => {
        const response = await commentsAPI.value.get(toValue(commentId))
        return response.data
      },
    })
  }

  const useCreateCommentMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateCommentRequest) => {
        const response = await commentsAPI.value.create(payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
        toast.success(t('composables.comments.addSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.addError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateCommentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateCommentRequest }) => {
        const response = await commentsAPI.value.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).detail(data.id),
        })
        toast.success(t('composables.comments.updateSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteCommentMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await commentsAPI.value.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
        queryClient.removeQueries({
          queryKey: queryKeys.comments(spaceId, contentId).detail(id),
        })
        toast.success(t('composables.comments.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useResolveCommentMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await commentsAPI.value.resolve(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).detail(data.id),
        })
        toast.success(t('composables.comments.resolveSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.resolveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUnresolveCommentMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await commentsAPI.value.unresolve(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).detail(data.id),
        })
        toast.success(t('composables.comments.unresolveSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.unresolveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useAddReactionMutation = () => {
    return useMutation({
      mutationFn: async ({ commentId, emoji }: { commentId: string; emoji: string }) => {
        const response = await commentsAPI.value.addReaction(commentId, emoji)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).detail(data.id),
        })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.addReactionError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRemoveReactionMutation = () => {
    return useMutation({
      mutationFn: async ({ commentId, emoji }: { commentId: string; emoji: string }) => {
        await commentsAPI.value.removeReaction(commentId, emoji)
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, contentId).lists(),
        })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.comments.removeReactionError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useCommentsQuery,
    useCommentQuery,

    // Mutations
    useCreateCommentMutation,
    useUpdateCommentMutation,
    useDeleteCommentMutation,
    useResolveCommentMutation,
    useUnresolveCommentMutation,
    useAddReactionMutation,
    useRemoveReactionMutation,
  }
}
