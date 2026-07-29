<script setup lang="ts">
import CommentItem from '~/components/comments/CommentItem.vue'
import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { Emoji, EmojiPicker } from '~/components/ui/emoji'
import { TextField } from '~/components/ui/form'
import type { CommentResource } from '~/types/comments'

const props = defineProps<{
  comment: CommentResource
  isReply?: boolean
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

const isExpanded = ref(false)
const isEditing = ref(false)
const isReplying = ref(false)
const isEmojiPickerOpen = ref(false)
const editBody = ref('')
const replyBody = ref('')

const { user: currentUser } = useAuth()
const { formatRelativeTime } = useFormat()

const spaceId = inject<string>('spaceId', '')
const { useAccessControl } = useAuthorization()
const { authorization, hasAbility } = useAccessControl(computed(() => ({ space_id: spaceId })))

// Mirrors CommentPolicy::resolve — root users, or the author with comments.resolve_own.
const canResolve = computed(
  () =>
    authorization.value?.is_root ||
    (props.comment.author.id === currentUser.value?.id && hasAbility('comments.resolve_own'))
)

const hasReplies = computed(() => (props.comment?.replies?.length ?? 0) > 0)
const repliesCount = computed(() => props.comment?.replies?.length || 0)
const reactions = computed(() => props.comment.reactions || {})

const hasUserReacted = (emoji: string): boolean => {
  const users = reactions.value[emoji] || []
  return users.some((user) => user.id === currentUser.value?.id)
}

const toggleReaction = (emoji: string) => {
  if (hasUserReacted(emoji)) {
    emit('removeReaction', props.comment.id, emoji)
  } else {
    emit('addReaction', props.comment.id, emoji)
  }
}

const addReaction = (emoji: string) => {
  emit('addReaction', props.comment.id, emoji)
  isEmojiPickerOpen.value = false
}

const startEditing = () => {
  isEditing.value = true
  editBody.value = props.comment.body
}

const cancelEditing = () => {
  isEditing.value = false
  editBody.value = ''
}

const saveEdit = () => {
  if (editBody.value.trim()) {
    emit('edit', props.comment, editBody.value)
    isEditing.value = false
  }
}

const startReplying = () => {
  isReplying.value = true
  isExpanded.value = true
}

const cancelReply = () => {
  isReplying.value = false
  replyBody.value = ''
}

const submitReply = () => {
  if (replyBody.value.trim()) {
    emit('reply', props.comment.id, replyBody.value)
    isReplying.value = false
    replyBody.value = ''
  }
}

const toggleResolved = () => {
  if (props.comment.is_resolved) {
    emit('unresolve', props.comment.id)
  } else {
    emit('resolve', props.comment.id)
  }
}
</script>

<template>
  <div
    class="group -mx-2 space-y-1 rounded-md p-2 hover:bg-muted/20"
    :class="[comment.is_resolved ? 'opacity-50' : '']"
  >
    <div class="flex items-center gap-1">
      <Avatar
        :name="comment.author.name"
        :avatar="comment.author.avatar"
        size="sm"
      />
      <div
        v-if="!isEditing"
        class="ml-auto flex items-center opacity-0 transition-opacity group-hover:opacity-100"
      >
        <Button
          v-if="comment.author.id === currentUser?.id"
          variant="ghost"
          size="toolbar"
          :aria-label="$t('actions.edit')"
          @click="startEditing"
        >
          <Icon name="lucide:edit-2" />
        </Button>
        <Button
          v-if="comment.author.id === currentUser?.id"
          variant="ghost"
          size="toolbar"
          class="text-destructive hover:text-destructive"
          :aria-label="$t('actions.delete')"
          @click="emit('delete', comment)"
        >
          <Icon name="lucide:trash-2" />
        </Button>
        <Button
          v-if="!isReply"
          variant="ghost"
          size="toolbar"
          :aria-label="$t('labels.comments.actions.reply')"
          @click="startReplying"
        >
          <Icon name="lucide:reply" />
        </Button>
        <Button
          v-if="!isReply && canResolve"
          variant="ghost"
          size="toolbar"
          :aria-label="
            comment.is_resolved
              ? $t('labels.comments.actions.reopen')
              : $t('labels.comments.actions.resolve')
          "
          @click="toggleResolved"
        >
          <Icon :name="comment.is_resolved ? 'lucide:check-circle' : 'lucide:check'" />
        </Button>
      </div>
    </div>

    <div class="flex-1 space-y-1">
      <div class="flex items-baseline gap-2">
        <span class="font-medium">{{ comment.author.name }}</span>
        <time class="text-xs text-muted">{{ formatRelativeTime(comment.created_at) }}</time>
      </div>

      <div
        v-if="isEditing"
        class="space-y-1"
      >
        <TextField
          name="editBody"
          v-model="editBody"
          @keydown.enter.meta.prevent.stop="saveEdit"
        />
        <div class="flex justify-end">
          <Button
            variant="ghost"
            size="toolbar"
            class="text-destructive"
            :aria-label="$t('actions.cancel')"
            @click="cancelEditing"
          >
            <Icon name="lucide:x" />
          </Button>
          <Button
            variant="ghost"
            size="toolbar"
            class="text-success"
            :aria-label="$t('actions.save')"
            @click="saveEdit"
          >
            <Icon name="lucide:check" />
          </Button>
        </div>
      </div>
      <p
        v-else
        class="text-sm text-primary"
      >
        {{ comment.body }}
      </p>

      <div class="mt-2 flex flex-wrap items-center gap-1">
        <Button
          v-for="(users, emoji) in reactions"
          :key="emoji"
          :variant="hasUserReacted(emoji) ? 'accent' : 'default'"
          size="xs"
          @click="toggleReaction(emoji)"
        >
          <Emoji :code="emoji" />
          <span class="text-xs">{{ users.length }}</span>
        </Button>

        <EmojiPicker
          @select="addReaction($event)"
          class="opacity-0 group-hover:opacity-100"
        />
      </div>

      <div v-if="hasReplies && !isReply">
        <Button
          variant="link"
          size="xs"
          @click="isExpanded = !isExpanded"
        >
          {{ isExpanded ? $t('labels.comments.hideReplies') : $t('labels.comments.showReplies') }}
          {{ repliesCount }} {{ $t('labels.comments.reply', repliesCount) }}
        </Button>

        <div
          v-if="isExpanded"
          class="mt-3 space-y-4 border-l-2 border-muted pl-2"
        >
          <CommentItem
            v-for="reply in comment.replies"
            :key="reply.id"
            :comment="reply"
            is-reply
            @edit="(c, body) => emit('edit', c, body)"
            @delete="(c) => emit('delete', c)"
            @add-reaction="(c, emoji) => emit('addReaction', c, emoji)"
            @remove-reaction="(c, emoji) => emit('removeReaction', c, emoji)"
          />
        </div>
      </div>

      <div
        v-if="isReplying && !isReply"
        class="mt-3 border-l-2 border-border pl-4"
      >
        <div class="flex flex-col gap-1">
          <Avatar
            :name="
              currentUser
                ? `${currentUser.firstname} ${currentUser.lastname}`
                : $t('labels.comments.you')
            "
            :avatar="currentUser?.avatar"
            size="sm"
          />
          <div class="flex-1 space-y-2">
            <TextField
              name="replyBody"
              v-model="replyBody"
              @keydown.enter.meta.prevent.stop="submitReply"
              :placeholder="$t('labels.comments.replyPlaceholder')"
            />
            <div class="flex justify-end gap-1">
              <Button
                variant="ghost"
                size="toolbar"
                :aria-label="$t('actions.cancel')"
                @click="cancelReply"
              >
                <Icon name="lucide:x" />
              </Button>
              <Button
                variant="ghost"
                size="toolbar"
                :aria-label="$t('actions.send')"
                @click="submitReply"
              >
                <Icon name="lucide:send" />
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
