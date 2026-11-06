<script setup lang="ts">
import type { Ref } from 'vue'
import { toast } from 'vue-sonner'

import ContentStateBadge from '~/components/content/ContentStateBadge.vue'
import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Skeleton } from '~/components/ui/skeleton'
import type { CommentResource } from '~/types/comments'
import type { ContentResource } from '~/types/contents'

/** The entry's facts as a property sheet, one per line, from what the API already serves. */
const props = defineProps<{
  content: ContentResource
}>()

const { t } = useI18n()
const route = useRoute()
const { formatDateTime, formatRelativeTime } = useFormat()

const spaceId = inject<string>('spaceId', '')
const comments = inject<Ref<CommentResource[] | undefined>>('comments', ref(undefined))
const commentsFailed = inject<Ref<boolean>>('commentsFailed', ref(false))

const { useContentVersionsQuery, useContentVersionCountQuery } = useContentVersions(
  spaceId,
  computed(() => props.content.id)
)
// The newest versions, the same read the history sheet makes. They carry the people and the
// release, which the entry response leaves out. A published version older than this page
// simply doesn't show who published it.
const versionsQuery = useContentVersionsQuery()
const countQuery = useContentVersionCountQuery()

const findVersion = (id: string | null) =>
  id ? versionsQuery.data.value?.find((version) => version.id === id) : undefined
const currentVersion = computed(() => findVersion(props.content.current_version_id))
const publishedVersion = computed(() => findVersion(props.content.published_version_id))

const status = computed(
  () =>
    props.content.language_versions?.find((version) => version.is_current)?.status ??
    (!props.content.published_version_id
      ? 'draft'
      : props.content.current_version_id !== props.content.published_version_id
        ? 'changed'
        : 'published')
)

// A draft the live version doesn't have yet: never published, or changed since.
const hasDraft = computed(() => status.value === 'draft' || status.value === 'changed')

const scheduled = computed(() => {
  const version = currentVersion.value
  if (!version || (!version.release && !version.scheduled_at)) return null
  return [version.release?.name, version.scheduled_at && formatDateTime(version.scheduled_at)]
    .filter(Boolean)
    .join(', ')
})

const languages = computed(() =>
  (props.content.language_versions ?? [])
    .filter((version) => version.exists)
    .map((version) => version.label)
    .join(', ')
)

const openComments = computed(
  () => comments.value?.filter((comment) => !comment.is_resolved).length ?? null
)

// One fact per 32px line: label column, value column.
const row = 'grid h-8 grid-cols-[8rem_minmax(0,1fr)] items-center gap-3'

const copy = (value: string) =>
  navigator.clipboard.writeText(value).then(() => toast.success(t('labels.contents.info.copied')))
</script>

<template>
  <div class="grid gap-5 text-sm">
    <section class="grid">
      <h3 class="mb-1 font-semibold text-primary">{{ t('labels.contents.info.publishing') }}</h3>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.status') }}</span>
        <span class="flex"><ContentStateBadge :status="status" /></span>
      </div>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.liveSince') }}</span>
        <span
          v-if="content.published_at"
          class="truncate text-primary"
          >{{ formatDateTime(content.published_at) }}</span
        >
        <span
          v-else
          class="text-muted"
          >{{ t('labels.contents.info.notLive') }}</span
        >
      </div>
      <div
        v-if="content.first_published_at"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.firstPublished') }}</span>
        <span class="truncate text-primary">{{ formatDateTime(content.first_published_at) }}</span>
      </div>
      <div
        v-if="hasDraft && !versionsQuery.isError.value"
        :class="row"
      >
        <span class="text-muted">{{
          content.published_version_id
            ? t('labels.contents.info.pendingDraft')
            : t('labels.contents.info.draft')
        }}</span>
        <Skeleton
          v-if="versionsQuery.isLoading.value"
          class="h-4 w-40"
        />
        <span
          v-else-if="currentVersion"
          class="flex min-w-0 items-center gap-2"
        >
          <template v-if="currentVersion.author">
            <Avatar
              :name="currentVersion.author.name"
              :avatar="currentVersion.author.avatar"
              size="sm"
              class="size-5 shrink-0 rounded-md"
            />
            <span class="truncate text-primary">{{ currentVersion.author.name }}</span>
          </template>
          <span class="ml-auto shrink-0 text-muted">{{
            formatRelativeTime(currentVersion.created_at)
          }}</span>
        </span>
      </div>
      <div
        v-if="publishedVersion?.publisher"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.lastPublish') }}</span>
        <span class="flex min-w-0 items-center gap-2">
          <Avatar
            :name="publishedVersion.publisher.name"
            :avatar="publishedVersion.publisher.avatar"
            size="sm"
            class="size-5 shrink-0 rounded-md"
          />
          <span class="truncate text-primary">{{ publishedVersion.publisher.name }}</span>
          <span
            v-if="publishedVersion.published_at"
            class="ml-auto shrink-0 text-muted"
            >{{ formatRelativeTime(publishedVersion.published_at) }}</span
          >
        </span>
      </div>
      <div
        v-if="scheduled"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.scheduled') }}</span>
        <span class="truncate text-info-ink">{{ scheduled }}</span>
      </div>
      <div
        v-if="!countQuery.isError.value"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.versions') }}</span>
        <Skeleton
          v-if="countQuery.isLoading.value"
          class="h-4 w-8"
        />
        <span
          v-else
          class="text-primary tabular-nums"
          >{{ countQuery.data.value }}</span
        >
      </div>
    </section>

    <section class="grid">
      <h3 class="mb-1 font-semibold text-primary">{{ t('labels.contents.info.relations') }}</h3>
      <div
        v-if="content.parent"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.parent') }}</span>
        <span class="truncate text-primary">{{ content.parent.name }}</span>
      </div>
      <div
        v-if="languages"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.languages') }}</span>
        <span class="truncate text-primary">{{ languages }}</span>
      </div>
      <div
        v-if="openComments !== null && !commentsFailed"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.info.comments') }}</span>
        <RouterLink
          :to="{ query: { ...route.query, mode: 'comments' } }"
          :class="[
            'flex w-fit items-center gap-1 rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring',
            openComments > 0 ? 'text-warning-ink' : 'text-primary',
          ]"
        >
          {{
            openComments > 0
              ? t('labels.contents.info.commentsOpen', openComments)
              : t('labels.contents.info.commentsNone')
          }}
          <Icon
            name="lucide:chevron-right"
            class="text-muted"
          />
        </RouterLink>
      </div>
    </section>

    <section class="grid">
      <h3 class="mb-1 font-semibold text-primary">{{ t('labels.contents.info.technical') }}</h3>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.type') }}</span>
        <span class="truncate text-primary">{{ content.block?.name }}</span>
      </div>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.address') }}</span>
        <span class="flex min-w-0 items-center gap-1">
          <span class="truncate text-primary">{{ content.full_slug }}</span>
          <button
            type="button"
            class="row-action"
            :aria-label="t('labels.contents.info.copyAddress')"
            :title="t('labels.contents.info.copyAddress')"
            @click="copy(content.full_slug)"
          >
            <Icon name="lucide:copy" />
          </button>
        </span>
      </div>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.id') }}</span>
        <span class="flex min-w-0 items-center gap-1">
          <span class="truncate text-primary tabular-nums">{{ content.id }}</span>
          <button
            type="button"
            class="row-action"
            :aria-label="t('labels.contents.info.copyId')"
            :title="t('labels.contents.info.copyId')"
            @click="copy(content.id)"
          >
            <Icon name="lucide:copy" />
          </button>
        </span>
      </div>
      <div
        v-if="content.external_id"
        :class="row"
      >
        <span class="text-muted">{{ t('labels.contents.fields.externalId') }}</span>
        <span class="truncate text-primary">{{ content.external_id }}</span>
      </div>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.created') }}</span>
        <span class="truncate text-primary">{{ formatDateTime(content.created_at) }}</span>
      </div>
      <div :class="row">
        <span class="text-muted">{{ t('labels.contents.info.updated') }}</span>
        <span class="truncate text-primary">{{ formatDateTime(content.updated_at) }}</span>
      </div>
    </section>
  </div>
</template>

