<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import QueryErrorState from '~/components/ui/QueryErrorState.vue'
import type { ContentTreeItem } from '~/composables/useContentTree'
import {
  commentDraftKeys,
  commentDraftModel,
  commentDraftsKey,
  createCommentDrafts,
} from '~/lib/commentDrafts'
import {
  locateComment,
  type CommentBlockLookup,
  type CommentLocation,
} from '~/lib/commentLocation'
import type { CommentResource } from '~/types/comments'

import CommentComposer from './CommentComposer.vue'
import CommentsList from './CommentsList.vue'

const props = defineProps<{
  contentId: string
  contentVersionId?: string
  entryName: string
  root: ContentTreeItem
  blocks: CommentBlockLookup
}>()

const emit = defineEmits<{ jump: [location: CommentLocation] }>()

/** Held by the page, so it survives a jump to a field and back. */
const tab = defineModel<'open' | 'resolved'>('tab', { default: 'open' })
const draft = commentDraftModel(inject(commentDraftsKey, createCommentDrafts, true), () =>
  commentDraftKeys.entry(props.contentId)
)

const spaceId = inject<string>('spaceId', '')

const { useCommentsQuery, useCreateCommentMutation } = useComments(
  spaceId,
  computed(() => props.contentId)
)

const {
  data: comments,
  isLoading,
  isError,
  isFetching,
  refetch,
} = useCommentsQuery()

const createComment = useCreateCommentMutation({ quiet: true })

const openThreads = computed(() => comments.value?.filter((c) => !c.is_resolved) ?? [])
const resolvedThreads = computed(() => comments.value?.filter((c) => c.is_resolved) ?? [])

const readFailed = computed(() => isError.value && !comments.value)

const locationOf = (comment: CommentResource) => {
  const location = locateComment(comment, props.root, props.blocks)

  return { location, label: location.kind === 'place' ? location.label : props.entryName }
}

const sections = computed(() => [
  {
    value: 'open' as const,
    label: 'labels.comments.open',
    threads: openThreads.value,
    empty: resolvedThreads.value.length
      ? 'labels.comments.emptyOpen'
      : 'labels.comments.emptyState',
  },
  {
    value: 'resolved' as const,
    label: 'labels.comments.resolved',
    threads: resolvedThreads.value,
    empty: 'labels.comments.emptyResolved',
  },
])
</script>

<template>
  <div class="flex flex-col gap-2">
    <h3 class="text-panel font-semibold text-primary">{{ $t('labels.comments.title') }}</h3>

    <QueryErrorState
      v-if="readFailed"
      flush
      class="py-2"
      :retrying="isFetching"
      @retry="refetch()"
    />
    <Tabs
      v-else
      v-model="tab"
    >
      <TabsList>
        <TabsTrigger
          v-for="section in sections"
          :key="section.value"
          :value="section.value"
        >
          {{ $t(section.label) }}
          <span
            v-if="comments"
            class="font-medium text-muted"
          >
            {{ section.threads.length }}
          </span>
        </TabsTrigger>
      </TabsList>
      <TabsContent
        v-for="section in sections"
        :key="section.value"
        :value="section.value"
        class="!mt-0"
      >
        <CommentsList
          :comments="section.threads"
          :is-loading="isLoading"
          :empty-text="$t(section.empty)"
          :location-of="locationOf"
          @jump="(location) => emit('jump', location)"
        />
      </TabsContent>
    </Tabs>

    <div class="sticky bottom-0 z-10 -mb-4 bg-background pt-2 pb-4">
      <CommentComposer
        v-model="draft"
        :placeholder="$t('labels.comments.placeholder')"
        :submit-label="$t('labels.comments.addButton')"
        :submit="
          (body) => createComment.mutateAsync({ content_version_id: contentVersionId, body })
        "
      />
    </div>
  </div>
</template>
