<script setup lang="ts">
import CommentComposer from '~/components/comments/CommentComposer.vue'
import CommentItem from '~/components/comments/CommentItem.vue'
import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { Emoji, EmojiPicker } from '~/components/ui/emoji'
import { hasRootWriteAccess } from '~/lib/access-control'
import {
  commentDraftKeys,
  commentDraftModel,
  commentDraftsKey,
  createCommentDrafts,
} from '~/lib/commentDrafts'
import type { CommentLocation } from '~/lib/commentLocation'
import { decodeCommentBody, parseCommentBody } from '~/lib/commentMentions'
import type { CommentActions, CommentResource } from '~/types/comments'

const props = defineProps<{
  comment: CommentResource
  actions: CommentActions
  isReply?: boolean
  /** Where the thread belongs, for a list that isn't about one field. */
  location?: CommentLocation
  locationLabel?: string
  /** The list mixes open and resolved threads, so a resolved one has to say so. */
  markResolved?: boolean
}>()

const emit = defineEmits<{ jump: [] }>()

// A reply or edit under way comes back with its text when this thread mounts again.
const drafts = inject(commentDraftsKey, createCommentDrafts, true)
const editBody = commentDraftModel(drafts, () => commentDraftKeys.edit(props.comment.id))
const replyBody = commentDraftModel(drafts, () => commentDraftKeys.reply(props.comment.id))
const isEditing = ref(editBody.value !== '')
const isReplying = ref(replyBody.value !== '')
const isExpanded = ref(isReplying.value)

// Esc in a reply or edit must not close the popover around it.
provide('dismissCommentSurface', undefined)

const { t } = useI18n()
const { user: currentUser } = useAuth()
const { formatRelativeTime } = useFormat()

const spaceId = inject<string>('spaceId', '')
const { useAccessControl } = useAuthorization()
const { authorization, hasAbility } = useAccessControl(computed(() => ({ space_id: spaceId })))

const isOwn = computed(() => props.comment.author.id === currentUser.value?.id)

// Mirrors CommentPolicy::resolve: elevated root, or the author with comments.resolve_own.
const canResolve = computed(
  () => hasRootWriteAccess(authorization.value) || (isOwn.value && hasAbility('comments.resolve_own'))
)

const replies = computed(() => props.comment.replies ?? [])
const reactions = computed(() => props.comment.reactions ?? {})
const hasReactions = computed(() => Object.keys(reactions.value).length > 0)

const bodyParts = computed(() => parseCommentBody(decodeCommentBody(props.comment.body)))

const mentionName = (id: string) =>
  props.comment.mentions?.find((person) => person.id.toLowerCase() === id.toLowerCase())?.name ??
  t('labels.comments.unknownPerson')

const hasUserReacted = (emoji: string): boolean =>
  (reactions.value[emoji] ?? []).some((user) => user.id === currentUser.value?.id)

const toggleReaction = (emoji: string) => {
  if (hasUserReacted(emoji)) props.actions.removeReaction(props.comment.id, emoji)
  else props.actions.addReaction(props.comment.id, emoji)
}

const startEditing = () => {
  editBody.value = decodeCommentBody(props.comment.body)
  isEditing.value = true
}

const startReplying = () => {
  isReplying.value = true
  isExpanded.value = true
}

const cancelReply = () => {
  isReplying.value = false
  replyBody.value = ''
}

const cancelEdit = () => {
  isEditing.value = false
  editBody.value = ''
}

const toggleResolved = () => {
  if (props.comment.is_resolved) props.actions.unresolve(props.comment.id)
  else props.actions.resolve(props.comment.id)
}
</script>

<template>
  <article :class="!isReply && '-mx-2'">
    <div class="group/comment rounded-md px-2 py-1.5 hover:bg-state-hover focus-within:bg-state-hover">
      <template v-if="location">
        <p
          v-if="location.kind !== 'place'"
          class="truncate text-sm text-muted"
        >
          {{ location.kind === 'missing' ? $t('labels.comments.fieldMissing') : locationLabel }}
        </p>
        <button
          v-else
          type="button"
          :title="$t('labels.comments.goToLocation', { location: locationLabel })"
          class="flex min-h-6 max-w-full cursor-pointer items-center gap-1 rounded-sm text-sm text-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          @click="emit('jump')"
        >
          <span class="truncate">{{ locationLabel }}</span>
          <Icon
            name="lucide:arrow-right"
            class="size-3.5 shrink-0"
          />
        </button>
      </template>

      <div class="flex min-h-7 items-center gap-2 text-sm">
        <Avatar
          :name="comment.author.name"
          :avatar="comment.author.avatar"
          size="sm"
          class="!size-5 shrink-0"
        />
        <span class="min-w-0 truncate font-medium text-primary">{{ comment.author.name }}</span>
        <time
          :datetime="comment.created_at"
          class="shrink-0 text-muted"
        >
          {{ formatRelativeTime(comment.created_at) }}
        </time>
        <span
          v-if="markResolved && comment.is_resolved"
          class="flex shrink-0 items-center gap-1 text-muted"
        >
          <Icon
            name="lucide:check"
            class="size-3.5"
          />
          {{ $t('labels.comments.resolved') }}
        </span>
        <div
          v-if="!isEditing"
          class="ml-auto flex shrink-0 items-center opacity-0 transition-opacity group-focus-within/comment:opacity-100 group-hover/comment:opacity-100 has-[[data-state=open]]:opacity-100"
        >
          <EmojiPicker @select="(emoji: string) => actions.addReaction(comment.id, emoji)" />
          <Button
            v-if="!isReply"
            variant="ghost"
            size="toolbar"
            :aria-label="$t('labels.comments.actions.reply')"
            :title="$t('labels.comments.actions.reply')"
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
            :title="
              comment.is_resolved
                ? $t('labels.comments.actions.reopen')
                : $t('labels.comments.actions.resolve')
            "
            @click="toggleResolved"
          >
            <Icon :name="comment.is_resolved ? 'lucide:rotate-ccw' : 'lucide:check'" />
          </Button>
          <Button
            v-if="isOwn"
            variant="ghost"
            size="toolbar"
            :aria-label="$t('actions.edit')"
            :title="$t('actions.edit')"
            @click="startEditing"
          >
            <Icon name="lucide:edit-2" />
          </Button>
          <Button
            v-if="isOwn"
            variant="ghost-destructive"
            size="toolbar"
            :aria-label="$t('actions.delete')"
            :title="$t('actions.delete')"
            @click="actions.remove(comment)"
          >
            <Icon name="lucide:trash-2" />
          </Button>
        </div>
      </div>

      <CommentComposer
        v-if="isEditing"
        v-model="editBody"
        class="mt-1"
        autofocus
        cancelable
        :placeholder="$t('labels.comments.placeholder')"
        :submit-label="$t('actions.save')"
        failure-key="composables.comments.updateError"
        :known-people="comment.mentions"
        :submit="(body) => actions.edit(comment, body)"
        @sent="isEditing = false"
        @cancel="cancelEdit"
      />
      <p
        v-else
        class="text-base break-words whitespace-pre-wrap text-primary"
      >
        <template
          v-for="(part, index) in bodyParts"
          :key="index"
        >
          <span
            v-if="part.type === 'mention'"
            class="rounded bg-chip px-1 text-chip-foreground"
            >@{{ mentionName(part.id) }}</span
          >
          <template v-else>{{ part.text }}</template>
        </template>
      </p>

      <div
        v-if="hasReactions"
        class="mt-1 flex flex-wrap items-center gap-1"
      >
        <Button
          v-for="(users, emoji) in reactions"
          :key="emoji"
          :variant="hasUserReacted(emoji) ? 'accent' : 'default'"
          size="xs"
          @click="toggleReaction(emoji)"
        >
          <Emoji :code="emoji" />
          <span class="text-sm">{{ users.length }}</span>
        </Button>
      </div>
    </div>

    <div
      v-if="replies.length > 0 && !isReply"
      class="pl-2"
    >
      <Button
        variant="link"
        size="xs"
        @click="isExpanded = !isExpanded"
      >
        {{ isExpanded ? $t('labels.comments.hideReplies') : $t('labels.comments.showReplies') }}
        {{ replies.length }} {{ $t('labels.comments.reply', replies.length) }}
      </Button>

      <div
        v-if="isExpanded"
        class="ml-2.5 space-y-1 border-l border-border pl-2"
      >
        <CommentItem
          v-for="reply in replies"
          :key="reply.id"
          :comment="reply"
          :actions="actions"
          is-reply
        />
      </div>
    </div>

    <div
      v-if="isReplying && !isReply"
      class="mt-1 mr-2 ml-4.5 border-l border-border pl-2"
    >
      <CommentComposer
        v-model="replyBody"
        autofocus
        cancelable
        :placeholder="$t('labels.comments.replyPlaceholder')"
        :submit-label="$t('labels.comments.actions.reply')"
        :submit="(body) => actions.reply(comment, body)"
        @sent="isReplying = false"
        @cancel="cancelReply"
      />
    </div>
  </article>
</template>
