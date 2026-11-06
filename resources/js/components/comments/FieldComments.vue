<script setup lang="ts">
import { unrefElement } from '@vueuse/core'

import CommentComposer from '~/components/comments/CommentComposer.vue'
import Icon from '~/components/Icon.vue'
import { MessageSquareIcon } from '~/components/icons'
import { Button } from '~/components/ui/button'
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import QueryErrorState from '~/components/ui/QueryErrorState.vue'
import {
  commentDraftKeys,
  commentDraftModel,
  commentDraftsKey,
  createCommentDrafts,
} from '~/lib/commentDrafts'
import type { FieldReveal } from '~/lib/commentLocation'
import { scrollIntoViewAfterExpand } from '~/lib/fieldFocus'
import type { CommentResource } from '~/types/comments'

import CommentsList from './CommentsList.vue'

const props = defineProps<{
  itemId?: string
  field?: string
  /** The field's name, for the button and the popover heading. */
  fieldLabel: string
}>()

const spaceId = inject<string>('spaceId', '')
const contentId = inject<Ref<string | null | undefined>>('contentId', ref(null))
const contentVersionId = inject<Ref<string | undefined>>('contentVersionId', ref(undefined))
const comments = inject<Ref<CommentResource[] | undefined>>('comments', ref(undefined))
const isLoadingComments = inject<Ref<boolean>>('commentsLoading', ref(false))
const commentsFailed = inject<Ref<boolean>>('commentsFailed', ref(false))
const retryComments = inject<() => void>('retryComments', () => {})
const fieldReveal = inject<Ref<FieldReveal | null>>('fieldReveal', ref(null))

const { t } = useI18n()

const isOpen = ref(false)
const draft = commentDraftModel(inject(commentDraftsKey, createCommentDrafts, true), () =>
  commentDraftKeys.field(contentId.value ?? '', props.itemId, props.field)
)
const trigger = useTemplateRef('trigger')
const composer = useTemplateRef('composer')

provide('dismissCommentSurface', () => {
  isOpen.value = false
})

const { useCreateCommentMutation } = useComments(spaceId, contentId)
const createComment = useCreateCommentMutation({ quiet: true })

const fieldComments = computed(
  () =>
    comments.value?.filter(
      (comment) => comment.item_id === (props.itemId || null) && comment.field === props.field
    ) ?? []
)

const openCount = computed(() => fieldComments.value.filter((c) => !c.is_resolved).length)
const totalCount = computed(() => fieldComments.value.length)

const triggerLabel = computed(() =>
  totalCount.value > 0
    ? t('labels.comments.fieldTriggerCount', {
        field: props.fieldLabel,
        total: totalCount.value,
        open: openCount.value,
      })
    : t('labels.comments.fieldTrigger', { field: props.fieldLabel })
)

/** A comment's location asked for this field: bring it into view and open its popover. */
const answerReveal = () => {
  const reveal = fieldReveal.value
  if (!reveal || reveal.row || reveal.itemId !== props.itemId || reveal.field !== props.field) return

  fieldReveal.value = null
  scrollIntoViewAfterExpand(
    (unrefElement(trigger) as HTMLElement | undefined)?.closest('[data-field-path]')
  )
  isOpen.value = true
}

onMounted(answerReveal)
watch(fieldReveal, answerReveal, { flush: 'post' })

// Beside the field when the popover fits to its left, the comment stays next to the text. Below it otherwise.
const POPOVER_WIDTH = 448
const anchor = useTemplateRef<HTMLElement>('anchor')
const side = ref<'left' | 'bottom'>('left')
watch(isOpen, (open) => {
  if (!open) return
  const left = anchor.value?.getBoundingClientRect().left ?? 0
  side.value = left >= POPOVER_WIDTH + 16 ? 'left' : 'bottom'
})

const focusComposer = async (event: Event) => {
  event.preventDefault()
  await nextTick()
  composer.value?.focus()
}
</script>

<template>
  <Popover v-model:open="isOpen">
    <PopoverAnchor as-child>
      <div
        ref="anchor"
        aria-hidden="true"
        class="pointer-events-none absolute inset-0"
      />
    </PopoverAnchor>
    <PopoverTrigger as-child>
      <Button
        ref="trigger"
        variant="ghost"
        size="xs"
        :class="[
          'absolute -top-1 right-0 z-10 !px-1.5 transition-opacity',
          openCount > 0 ? 'text-warning-ink' : 'text-muted',
          totalCount === 0 && 'field-comment-trigger-idle',
        ]"
        data-field-side-action
        :aria-label="triggerLabel"
        :title="triggerLabel"
      >
        <MessageSquareIcon :size="14" />
        <span v-if="totalCount > 0">{{ totalCount }}</span>
      </Button>
    </PopoverTrigger>
    <PopoverContent
      class="flex !max-h-[min(36rem,80svh)] w-md max-w-[calc(100vw-2rem)] flex-col gap-2"
      :side="side"
      :side-offset="side === 'left' ? 16 : 8"
      :collision-padding="8"
      align="start"
      @open-auto-focus="focusComposer"
    >
      <div class="flex items-center justify-between gap-2">
        <h4 class="min-w-0 truncate font-medium">{{ fieldLabel }}</h4>
        <Button
          variant="ghost"
          size="toolbar"
          :aria-label="$t('actions.close')"
          @click="isOpen = false"
        >
          <Icon name="lucide:x" />
        </Button>
      </div>

      <QueryErrorState
        v-if="commentsFailed"
        flush
        @retry="retryComments()"
      />
      <div
        v-else
        class="-mx-1 min-h-0 flex-1 overflow-y-auto px-3"
      >
        <CommentsList
          :comments="fieldComments"
          :is-loading="isLoadingComments"
          :empty-text="$t('labels.comments.emptyField')"
          mark-resolved
        />
      </div>

      <CommentComposer
        ref="composer"
        v-model="draft"
        class="shrink-0"
        :placeholder="$t('labels.comments.placeholder')"
        :submit-label="$t('labels.comments.addButton')"
        :submit="
          (body) =>
            createComment.mutateAsync({
              content_version_id: contentVersionId,
              body,
              item_id: itemId,
              field,
            })
        "
      />
    </PopoverContent>
  </Popover>
</template>

<style>
/* The idle button belongs to the innermost field under the pointer or focus, so a
   nested list doesn't light up every field in it. */
.field-comment-trigger-idle:not([data-state='open']) {
  opacity: 0;
}

[data-field-path]:hover:not(:has([data-field-path]:hover)) > .field-comment-trigger-idle,
[data-field-path]:focus-within:not(:has([data-field-path]:focus-within))
  > .field-comment-trigger-idle {
  opacity: 1;
}
</style>
