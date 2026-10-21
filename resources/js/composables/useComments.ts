import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { CommentsQueryParams } from '~/api/resources/comments'
import { addComment, patchComment, removeComment } from '~/lib/commentCache'
import type { CommentResource, CreateCommentRequest, UpdateCommentRequest } from '~/types/comments'

import { queryKeys } from './useQueryClient'

export function useComments(
  spaceId: MaybeRef<string>,
  contentId: MaybeRef<string | null | undefined>
) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const resolvedContentId = computed(() => toValue(contentId) || '')
  const commentsAPI = computed(() =>
    api.forSpace(toValue(spaceId)).comments(resolvedContentId.value)
  )
  const hasContentId = computed(() => !!toValue(contentId))
  // Provided by the content editor page (useContentLiveCollaboration) so other
  // collaborators refetch comments after any local mutation.
  const broadcastCommentUpdate = inject<(() => void) | undefined>(
    'broadcastCommentUpdate',
    undefined
  )
  const notifyCollaborators = () => broadcastCommentUpdate?.()

  const listsKey = () => queryKeys.comments(spaceId, resolvedContentId.value).lists()

  /** Cancels in-flight list fetches so they cannot overwrite the patch, and returns the lists to roll back to. */
  const snapshotLists = async () => {
    await queryClient.cancelQueries({ queryKey: listsKey() })
    return queryClient.getQueriesData<CommentResource[]>({ queryKey: listsKey() })
  }

  const restoreLists = (snapshot: Awaited<ReturnType<typeof snapshotLists>>) => {
    for (const [key, data] of snapshot) queryClient.setQueryData(key, data)
  }

  const patchLists = (
    update: (list: CommentResource[], params: CommentsQueryParams | undefined) => CommentResource[]
  ) => {
    for (const [key, data] of queryClient.getQueriesData<CommentResource[]>({
      queryKey: listsKey(),
    })) {
      if (data) {
        queryClient.setQueryData(key, update(data, key.at(-1) as CommentsQueryParams | undefined))
      }
    }
  }

  const resolvedPatch = (isResolved: boolean): Partial<CommentResource> => ({
    is_resolved: isResolved,
    resolved_at: isResolved ? new Date().toISOString() : undefined,
  })

  const useCommentsQuery = (params: MaybeRef<CommentsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.comments(spaceId, resolvedContentId.value).list(params)),
      queryFn: async () => {
        const response = await commentsAPI.value.index(toValue(params))
        return response.data
      },
      enabled: hasContentId,
      placeholderData: keepPreviousData,
    })
  }

  const useCommentQuery = (commentId: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() =>
        queryKeys.comments(spaceId, resolvedContentId.value).detail(commentId)
      ),
      queryFn: async () => {
        const response = await commentsAPI.value.get(toValue(commentId))
        return response.data
      },
      enabled: computed(() => hasContentId.value && !!toValue(commentId)),
    })
  }

  const useCreateCommentMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateCommentRequest) => {
        const response = await commentsAPI.value.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        patchLists((list, params) => addComment(list, data, params))
        notifyCollaborators()
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
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).detail(data.id),
        })
        notifyCollaborators()
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
      onMutate: async (id) => {
        const snapshot = await snapshotLists()
        patchLists((list) => removeComment(list, id))
        return { snapshot }
      },
      onSuccess: (id) => {
        queryClient.removeQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).detail(id),
        })
        notifyCollaborators()
        toast.success(t('composables.comments.deleteSuccess') as string)
      },
      onError: (error: Error, _id, context) => {
        if (context) restoreLists(context.snapshot)
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
      onMutate: async (id) => {
        const snapshot = await snapshotLists()
        patchLists((list) => patchComment(list, id, resolvedPatch(true)))
        return { snapshot }
      },
      onSuccess: (data) => {
        patchLists((list) =>
          patchComment(list, data.id, {
            is_resolved: data.is_resolved,
            resolved_at: data.resolved_at,
          })
        )
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).detail(data.id),
        })
        notifyCollaborators()
        toast.success(t('composables.comments.resolveSuccess') as string)
      },
      onError: (error: Error, _id, context) => {
        if (context) restoreLists(context.snapshot)
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
      onMutate: async (id) => {
        const snapshot = await snapshotLists()
        patchLists((list) => patchComment(list, id, resolvedPatch(false)))
        return { snapshot }
      },
      onSuccess: (data) => {
        patchLists((list) =>
          patchComment(list, data.id, {
            is_resolved: data.is_resolved,
            resolved_at: data.resolved_at,
          })
        )
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).detail(data.id),
        })
        notifyCollaborators()
        toast.success(t('composables.comments.unresolveSuccess') as string)
      },
      onError: (error: Error, _id, context) => {
        if (context) restoreLists(context.snapshot)
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
      onSuccess: (_, { commentId }) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).detail(commentId),
        })
        notifyCollaborators()
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
      onSuccess: (_, { commentId }) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.comments(spaceId, resolvedContentId.value).detail(commentId),
        })
        notifyCollaborators()
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
