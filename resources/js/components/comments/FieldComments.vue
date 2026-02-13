<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { TextField } from '~/components/ui/form'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { CommentResource, CreateCommentRequest } from '~/types/comments'
import CommentsList from './CommentsList.vue'

const props = defineProps<{
  itemId?: string
  field?: string
}>()

const spaceId = inject<string>('spaceId', '')
const contentId = inject<Ref<string>>('contentId', ref(''))
const contentVersionId = inject<Ref<string | undefined>>('contentVersionId', ref(undefined))

const isOpen = ref(false)
const newCommentBody = ref('')
const comments = inject<Ref<CommentResource[]>>('comments', ref([]))

const {
  useCreateCommentMutation,
  useUpdateCommentMutation,
  useDeleteCommentMutation,
  useResolveCommentMutation,
  useUnresolveCommentMutation,
  useAddReactionMutation,
  useRemoveReactionMutation,
} = useComments(
  computed(() => spaceId),
  computed(() => contentId.value)
)

const createCommentMutation = useCreateCommentMutation()
const updateCommentMutation = useUpdateCommentMutation()
const deleteCommentMutation = useDeleteCommentMutation()
const resolveCommentMutation = useResolveCommentMutation()
const unresolveCommentMutation = useUnresolveCommentMutation()
const addReactionMutation = useAddReactionMutation()
const removeReactionMutation = useRemoveReactionMutation()

const fieldComments = computed(() => {
  return (
    comments.value?.filter(
      (c) => c.item_id === (props.itemId || null) && c.field === props.field
    ) || []
  )
})

const unresolvedCount = computed(() => {
  return fieldComments.value.filter((c) => !c.is_resolved).length
})

const totalComments = computed(() => {
  return fieldComments.value.length
})

const handleCreateComment = () => {
  if (!newCommentBody.value.trim()) return

  const payload: CreateCommentRequest = {
    content_version_id: contentVersionId.value,
    body: newCommentBody.value,
    item_id: props.itemId,
    field: props.field,
  }

  createCommentMutation.mutate(payload, {
    onSuccess: () => {
      newCommentBody.value = ''
    },
  })
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

const handleEdit = (comment: CommentResource, body: string) => {
  updateCommentMutation.mutate({ id: comment.id, payload: { body } })
}

const handleReply = (parentId: string, body: string) => {
  const payload: CreateCommentRequest = {
    content_version_id: contentVersionId.value,
    parent_id: parentId,
    body,
    item_id: props.itemId,
    field: props.field,
  }
  createCommentMutation.mutate(payload)
}

const handleAddReaction = (commentId: string, emoji: string) => {
  addReactionMutation.mutate({ commentId, emoji })
}

const handleRemoveReaction = (commentId: string, emoji: string) => {
  removeReactionMutation.mutate({ commentId, emoji })
}
</script>

<template>
  <Popover v-model:open="isOpen">
    <PopoverTrigger class="absolute -top-1 right-0 z-0 flex w-full justify-end">
      <button
        :class="[unresolvedCount > 0 ? 'text-warning' : 'text-muted']"
        class="inline-flex cursor-pointer items-center gap-1 rounded-sm p-1 text-xs font-semibold hover:bg-secondary/80"
      >
        <Icon
          name="lucide:message-square"
          size="14"
        />
        <span v-if="totalComments > 0">
          {{ totalComments }}
        </span>
      </button>
    </PopoverTrigger>
    <PopoverContent
      class="w-md max-w-full"
      side="left"
      :sideOffset="16"
      align="start"
    >
      <div class="flex h-160 flex-col">
        <div class="flex items-center justify-between">
          <h4 class="font-medium">Field Comments</h4>
          <Button
            variant="ghost"
            size="toolbar"
            @click="isOpen = false"
          >
            <Icon name="lucide:x" />
          </Button>
        </div>

        <ScrollArea class="h-96">
          <div class="flex h-full w-full flex-1 flex-col">
            <div
              v-if="fieldComments.length === 0"
              class="flex items-start gap-2 py-3 text-muted"
            >
              <Icon
                name="lucide:message-circle"
                class="shrink-0"
              />
              <p class="text-sm">
                Give feedback, ask questions, or share your thoughts. You can also click anywhere in
                the preview to leave a positioned comment.
              </p>
            </div>
            <CommentsList
              v-else
              :comments="fieldComments"
              @reply="handleReply"
              @edit="handleEdit"
              @delete="handleDelete"
              @resolve="handleResolve"
              @unresolve="handleUnresolve"
              @add-reaction="handleAddReaction"
              @remove-reaction="handleRemoveReaction"
              class="mx-2"
            />
          </div>
        </ScrollArea>

        <div class="h-full space-y-1">
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
                class="mr-1 animate-spin"
              />
              <span> Add Comment </span>
            </Button>
          </div>
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
