<script setup lang="ts">
import { Skeleton } from '~/components/ui/skeleton'
import type { CommentLocation } from '~/lib/commentLocation'
import type { CommentActions, CommentResource } from '~/types/comments'

import CommentItem from './CommentItem.vue'

const props = defineProps<{
  comments: CommentResource[]
  isLoading?: boolean
  emptyText?: string
  /** Where a thread belongs. Left out when the list is about one field. */
  locationOf?: (comment: CommentResource) => { location: CommentLocation; label: string }
  markResolved?: boolean
}>()

const emit = defineEmits<{ jump: [location: CommentLocation] }>()

const spaceId = inject<string>('spaceId', '')
const contentId = inject<Ref<string | null | undefined>>('contentId', ref(null))
const contentVersionId = inject<Ref<string | undefined>>('contentVersionId', ref(undefined))

const {
  useCreateCommentMutation,
  useUpdateCommentMutation,
  useDeleteCommentMutation,
  useResolveCommentMutation,
  useUnresolveCommentMutation,
  useAddReactionMutation,
  useRemoveReactionMutation,
} = useComments(spaceId, contentId)

const createComment = useCreateCommentMutation({ quiet: true })
const updateComment = useUpdateCommentMutation({ quiet: true })
const deleteComment = useDeleteCommentMutation()
const resolveComment = useResolveCommentMutation()
const unresolveComment = useUnresolveCommentMutation()
const addReaction = useAddReactionMutation()
const removeReaction = useRemoveReactionMutation()

const actions: CommentActions = {
  // A reply stays on its thread's field, wherever it was written.
  reply: (parent, body) =>
    createComment.mutateAsync({
      content_version_id: contentVersionId.value,
      parent_id: parent.id,
      item_id: parent.item_id ?? undefined,
      field: parent.field ?? undefined,
      body,
    }),
  edit: (comment, body) => updateComment.mutateAsync({ id: comment.id, payload: { body } }),
  remove: (comment) => deleteComment.mutate(comment.id),
  resolve: (commentId) => resolveComment.mutate(commentId),
  unresolve: (commentId) => unresolveComment.mutate(commentId),
  addReaction: (commentId, emoji) => addReaction.mutate({ commentId, emoji }),
  removeReaction: (commentId, emoji) => removeReaction.mutate({ commentId, emoji }),
}

const threads = computed(() =>
  props.comments
    .filter((comment) => !comment.parent_id)
    .map((comment) => ({ comment, place: props.locationOf?.(comment) }))
)
</script>

<template>
  <div
    v-if="isLoading && comments.length === 0"
    class="space-y-4 py-2"
    aria-hidden="true"
  >
    <div
      v-for="i in 3"
      :key="i"
      class="space-y-2"
    >
      <div class="flex items-center gap-2">
        <Skeleton class="size-5 rounded-md" />
        <Skeleton class="h-4 w-24" />
        <Skeleton class="h-4 w-12" />
      </div>
      <Skeleton class="h-4 w-full" />
    </div>
  </div>

  <p
    v-else-if="threads.length === 0"
    class="py-2 text-sm text-muted"
  >
    {{ emptyText }}
  </p>

  <div
    v-else
    class="space-y-2 py-2"
  >
    <CommentItem
      v-for="{ comment, place } in threads"
      :key="comment.id"
      :comment="comment"
      :actions="actions"
      :location="place?.location"
      :location-label="place?.label"
      :mark-resolved="markResolved"
      @jump="place && emit('jump', place.location)"
    />
  </div>
</template>
