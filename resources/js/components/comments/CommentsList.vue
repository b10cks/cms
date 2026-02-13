<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { CommentResource } from '~/types/comments'
import CommentItem from './CommentItem.vue'

const props = defineProps<{
  comments: CommentResource[]
  isLoading?: boolean
}>()

const emit = defineEmits<{
  (e: 'reply', commentId: string, body: string): void
  (e: 'edit', comment: CommentResource, body: string): void
  (e: 'delete', comment: CommentResource): void
  (e: 'resolve', commentId: string): void
  (e: 'unresolve', commentId: string): void
  (e: 'addReaction', commentId: string, emoji: string): void
  (e: 'removeReaction', commentId: string, emoji: string): void
}>()

// Separate top-level comments from replies
const topLevelComments = computed(() => props.comments.filter((c) => !c.parent_id))
const repliesByParent = computed(() => {
  const replies: Record<string, CommentResource[]> = {}
  props.comments.forEach((comment) => {
    if (comment.parent_id) {
      if (!replies[comment.parent_id]) {
        replies[comment.parent_id] = []
      }
      replies[comment.parent_id].push(comment)
    }
  })
  return replies
})
</script>

<template>
  <div class="space-y-6 py-4">
    <div
      v-if="isLoading"
      class="space-y-4"
    >
      <div
        v-for="i in 3"
        :key="i"
        class="flex animate-pulse gap-3"
      >
        <div class="h-8 w-8 rounded-full bg-muted" />
        <div class="flex-1 space-y-2">
          <div class="h-4 w-24 rounded bg-muted" />
          <div class="h-3 w-full rounded bg-muted" />
          <div class="h-3 w-3/4 rounded bg-muted" />
        </div>
      </div>
    </div>

    <div
      v-else-if="comments.length === 0"
      class="flex items-start gap-2 text-muted"
    >
      <Icon
        name="lucide:message-circle"
        class="shrink-0"
      />
      <p class="text-sm">
        Give feedback, ask questions, or share your thoughts. You can also click anywhere in the
        preview to leave a positioned comment.
      </p>
    </div>

    <div v-else>
      <CommentItem
        v-for="comment in topLevelComments"
        :key="comment.id"
        :comment="comment"
        :replies="repliesByParent[comment.id]"
        @reply="(id, body) => emit('reply', id, body)"
        @edit="(c, body) => emit('edit', c, body)"
        @delete="(c) => emit('delete', c)"
        @resolve="(id) => emit('resolve', id)"
        @unresolve="(id) => emit('unresolve', id)"
        @add-reaction="(id, emoji) => emit('addReaction', id, emoji)"
        @remove-reaction="(id, emoji) => emit('removeReaction', id, emoji)"
      />
    </div>
  </div>
</template>
