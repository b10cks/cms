<script setup lang="ts">
import AiReviewItemList from '~/components/ai/threads/AiReviewItemList.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import { Spinner } from '~/components/ui/spinner'
import type { AiChangesetItem, AiReviewGroup } from '~/lib/aiChangesets'
import { readablePath, STRUCTURE_GROUP } from '~/lib/aiChangesets'

/**
 * One review group of an operation: a checkbox to include it in the group
 * accept, and when opened a few examples, the changes left to an individual
 * decision and, on request, every change a page at a time. While a group
 * decision runs on the queue the group takes no other decision.
 */
const props = defineProps<{
  spaceId: string
  operationId: string
  group: AiReviewGroup
  busy: boolean
  locales: (item: AiChangesetItem) => { source: string; target: string } | null
}>()

const selected = defineModel<boolean>('selected', { required: true })

const emit = defineEmits<{
  accept: [item: AiChangesetItem, overwrite: boolean]
  reject: [item: AiChangesetItem]
}>()

const { t } = useI18n()
const { useReviewItemsQuery } = useAiThreads(() => props.spaceId)

const expanded = ref(false)
const showAll = ref(false)

const individualQuery = useReviewItemsQuery(
  () => props.operationId,
  () => props.group.key,
  true,
  () => expanded.value && props.group.individual > 0
)
const allQuery = useReviewItemsQuery(
  () => props.operationId,
  () => props.group.key,
  false,
  () => expanded.value && showAll.value
)

const individualItems = computed(() => individualQuery.data.value?.pages.flatMap((page) => page.data) ?? [])
const allItems = computed(() => allQuery.data.value?.pages.flatMap((page) => page.data) ?? [])

/** No decision while another is running, here or on the queue. */
const locked = computed(() => props.busy || props.group.queued !== null)

const total = computed(() => Object.values(props.group.counts).reduce((sum, count) => sum + count, 0))
const open = computed(() => props.group.counts.pending + props.group.counts.conflict)

const title = computed(() => {
  if (props.group.key === STRUCTURE_GROUP) return t('components.aiThreads.review.structure')
  if (props.group.label) return props.group.label
  if (props.group.operation === 'create') return t('components.aiThreads.review.created', { target: t(`components.aiChangeset.targets.${props.group.target_type}`) })
  return readablePath(props.group.path ?? '') || t(`components.aiChangeset.targets.${props.group.target_type}`)
})
</script>

<template>
  <li :data-group="group.key">
    <div class="flex min-h-11 items-center gap-3 px-4 py-2">
      <Checkbox
        v-if="group.bulk > 0"
        :model-value="selected"
        :disabled="locked"
        :aria-label="$t('components.aiThreads.review.selectGroup', { name: title })"
        @update:model-value="(value) => (selected = value === true)"
      />
      <span
        v-else
        class="size-4 shrink-0"
      />
      <button
        type="button"
        class="flex min-w-0 flex-1 items-center gap-2 rounded-md text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        :aria-expanded="expanded"
        @click="expanded = !expanded"
      >
        <span class="min-w-0 flex-1">
          <span class="block truncate font-medium select-none">{{ title }}</span>
          <span
            v-if="group.queued"
            class="flex items-center gap-1.5 text-sm text-ai select-none"
            role="status"
            data-queued
          >
            <Spinner class="size-3.5" />
            {{ $t(`components.aiThreads.review.queued.${group.queued}`, { open }) }}
          </span>
          <span
            v-else
            class="block text-sm text-muted tabular-nums select-none"
          >
            {{ $t('components.aiThreads.review.groupMeta', { open, total }) }}
          </span>
        </span>
        <span
          v-if="group.individual > 0"
          class="shrink-0 text-sm text-warning tabular-nums select-none"
        >
          {{ $t('components.aiThreads.review.individual', { count: group.individual }) }}
        </span>
        <Icon
          name="lucide:chevron-right"
          :class="['shrink-0 text-muted motion-safe:transition-transform motion-safe:duration-200', expanded && 'rotate-90']"
        />
      </button>
    </div>

    <div
      v-if="expanded"
      class="grid gap-4 border-t border-border px-4 py-3"
    >
      <section
        v-if="group.examples.length > 0"
        class="grid gap-2"
      >
        <h4 class="text-sm text-muted select-none">{{ $t('components.aiThreads.review.examples') }}</h4>
        <AiReviewItemList
          :space-id="spaceId"
          :items="group.examples"
          :busy="locked"
          :locales="locales"
          :scope="group.key"
          @accept="(item, overwrite) => emit('accept', item, overwrite)"
          @reject="(item) => emit('reject', item)"
        />
      </section>

      <section
        v-if="group.individual > 0"
        class="grid gap-2"
        data-individual
      >
        <h4 class="text-sm text-muted select-none">{{ $t('components.aiThreads.review.individualTitle') }}</h4>
        <AiReviewItemList
          :space-id="spaceId"
          :items="individualItems"
          :busy="locked"
          :locales="locales"
          :scope="group.key"
          @accept="(item, overwrite) => emit('accept', item, overwrite)"
          @reject="(item) => emit('reject', item)"
        />
        <p
          v-if="individualQuery.isPending.value"
          class="text-sm text-muted"
        >
          {{ $t('components.aiThreads.review.loading') }}
        </p>
        <Button
          v-if="individualQuery.hasNextPage.value"
          size="sm"
          variant="ghost"
          class="w-fit"
          :loading="individualQuery.isFetchingNextPage.value"
          @click="individualQuery.fetchNextPage()"
        >
          {{ $t('components.aiThreads.review.showMore') }}
        </Button>
      </section>

      <section class="grid gap-2">
        <Button
          v-if="!showAll"
          size="sm"
          variant="ghost"
          class="-ml-3 w-fit"
          @click="showAll = true"
        >
          {{ $t('components.aiThreads.review.showAll', { count: total }) }}
        </Button>
        <template v-else>
          <h4 class="text-sm text-muted select-none">{{ $t('components.aiThreads.review.allTitle', { count: total }) }}</h4>
          <AiReviewItemList
              :space-id="spaceId"
            :items="allItems"
            :busy="locked"
            :locales="locales"
            :scope="group.key"
            @accept="(item, overwrite) => emit('accept', item, overwrite)"
            @reject="(item) => emit('reject', item)"
          />
          <p
            v-if="allQuery.isPending.value"
            class="text-sm text-muted"
          >
            {{ $t('components.aiThreads.review.loading') }}
          </p>
          <Button
            v-if="allQuery.hasNextPage.value"
            size="sm"
            variant="ghost"
            class="w-fit"
            :loading="allQuery.isFetchingNextPage.value"
            @click="allQuery.fetchNextPage()"
          >
            {{ $t('components.aiThreads.review.showMore') }}
          </Button>
        </template>
      </section>
    </div>
  </li>
</template>
