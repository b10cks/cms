<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { CommentResource, CreateCommentRequest } from '~/types/comments'
import { Badge } from '../ui/badge'
import { Button } from '../ui/button'
import { TextField } from '../ui/form'
import CommentsList from './CommentsList.vue'

const props = defineProps<{
  contentId: string
  contentVersionId?: string
}>()

const spaceId = inject<string>('spaceId', '')
const newCommentBody = ref('')

const {
  useCommentsQuery,
  useCreateCommentMutation,
  useUpdateCommentMutation,
  useDeleteCommentMutation,
  useResolveCommentMutation,
  useUnresolveCommentMutation,
  useAddReactionMutation,
  useRemoveReactionMutation,
} = useComments(
  spaceId,
  computed(() => props.contentId)
)

const { data: comments, isLoading } = useCommentsQuery(
  computed(() => ({
    filter: {
      content_version_id: props.contentVersionId,
    },
  }))
)

const createCommentMutation = useCreateCommentMutation()
const updateCommentMutation = useUpdateCommentMutation()
const deleteCommentMutation = useDeleteCommentMutation()
const resolveCommentMutation = useResolveCommentMutation()
const unresolveCommentMutation = useUnresolveCommentMutation()
const addReactionMutation = useAddReactionMutation()
const removeReactionMutation = useRemoveReactionMutation()

const handleCreateComment = () => {
  if (!newCommentBody.value.trim()) return

  const payload: CreateCommentRequest = {
    content_version_id: props.contentVersionId,
    body: newCommentBody.value,
  }

  createCommentMutation.mutate(payload, {
    onSuccess: () => {
      newCommentBody.value = ''
    },
  })
}

const handleReply = (parentId: string, body: string) => {
  const payload: CreateCommentRequest = {
    content_version_id: props.contentVersionId,
    parent_id: parentId,
    body,
  }
  createCommentMutation.mutate(payload)
}

const handleEdit = (comment: CommentResource, body: string) => {
  updateCommentMutation.mutate({ id: comment.id, payload: { body } })
}

const handleDelete = (comment: CommentResource) => {
  deleteCommentMutation.mutate(comment.id)
}

const handleResolve = (commentId: string) => {
  resolveCommentMutation.mutate(commentId)
}

const handleUnresolve = (commentId: string) => {
  unresolveCommentMutation.mutate(commentId)
}

const handleAddReaction = (commentId: string, emoji: string) => {
  addReactionMutation.mutate({ commentId, emoji })
}

const handleRemoveReaction = (commentId: string, emoji: string) => {
  removeReactionMutation.mutate({ commentId, emoji })
}

const unresolvedCount = computed(() => {
  if (!comments.value) return 0
  return comments.value.filter((c) => !c.is_resolved).length
})
</script>

<template>
  <div class="flex h-full flex-1 flex-col">
    <div class="flex items-center gap-1">
      <h3 class="text-xl font-semibold text-primary">Comments</h3>
      <Badge
        v-if="unresolvedCount > 0"
        variant="default"
        size="sm"
      >
        {{ unresolvedCount }}/{{ comments?.length || 0 }}
      </Badge>
    </div>

    <ScrollArea class="h-full flex-1">
      <CommentsList
        :comments="comments || []"
        :is-loading="isLoading"
        @reply="handleReply"
        @edit="handleEdit"
        @delete="handleDelete"
        @resolve="handleResolve"
        @unresolve="handleUnresolve"
        @add-reaction="handleAddReaction"
        @remove-reaction="handleRemoveReaction"
      />
    </ScrollArea>

    <div class="space-y-1">
      <TextField
        name="comment"
        v-model="newCommentBody"
        @keydown.enter.meta.prevent.stop="handleCreateComment"
        placeholder="Add a comment..."
        :auto-size="100"
      />
      <div class="flex justify-end">
        <Button
          :disabled="!newCommentBody.trim() || createCommentMutation.isPending.value"
          @click="handleCreateComment"
        >
          <Icon
            v-if="createCommentMutation.isPending.value"
            name="lucide:loader-2"
            class="animate-spin"
          />
          <span> Add Comment </span>
        </Button>
      </div>
    </div>
  </div>
</template>
