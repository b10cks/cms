<script setup lang="ts">
import AiChangesetItem from '~/components/ai/AiChangesetItem.vue'
import AiOperationPager from '~/components/ai/operations/AiOperationPager.vue'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import { useItemDecisions } from '~/components/ai/threads/useItemDecisions'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Spinner } from '~/components/ui/spinner'
import type { AiChangedEntry, AiChangesetItem as Item } from '~/lib/aiChangesets'
import { entryHistoryLink, entryLink } from '~/lib/aiChangesets'

/**
 * What an operation changed in drafts, an entry at a time, entries with
 * conflicts first. Those are the only things that need a person. Each entry links to its draft and its history, and opens into its
 * fields, loaded on request.
 */
const props = defineProps<{
  spaceId: string
  operationId: string
  changesetId: string
  locales: (item: Item) => { source: string; target: string } | null
}>()

const { t } = useOperationI18n()
const { formatNumber } = useFormat()
const { useOperationChangesQuery, useEntryItemsQuery, rejectMutation } = useAiThreads(() => props.spaceId)
const decisions = useItemDecisions()

const PER_PAGE = 25
const page = ref(1)
const { data, isFetching } = useOperationChangesQuery(() => props.operationId, page, PER_PAGE)
const lastPage = computed(() => Math.max(1, Math.ceil((data.value?.meta.total ?? 0) / PER_PAGE)))

const openEntry = ref<string | null>(null)
const entryItems = useEntryItemsQuery(
  () => props.operationId,
  () => openEntry.value ?? '',
  () => openEntry.value !== null
)
const items = computed(() => entryItems.data.value?.pages.flatMap((itemPage) => itemPage.data) ?? [])

const key = (entry: AiChangedEntry) => entry.target_id ?? entry.label ?? ''
const toggle = (entry: AiChangedEntry) => {
  openEntry.value = openEntry.value === entry.target_id ? null : entry.target_id
}

const draftLink = (entry: AiChangedEntry) => entryLink(props.spaceId, { targetType: 'content', targetId: entry.target_id })
const historyLink = (entry: AiChangedEntry) => entryHistoryLink(props.spaceId, { targetType: 'content', targetId: entry.target_id })

const busy = computed(() => decisions.acceptMutation.isPending.value || rejectMutation.isPending.value)
const reject = (item: Item) => rejectMutation.mutate({ changesetId: props.changesetId, decision: { item_ids: [item.id] } })
</script>

<template>
  <div
    v-if="data && data.data.length > 0"
    class="min-w-0"
    data-operation-changes
  >
    <ul
      class="divide-y divide-border"
      :class="isFetching && 'opacity-70'"
    >
      <li
        v-for="entry in data.data"
        :key="key(entry)"
        :data-changed-entry="entry.target_id"
      >
        <div class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2">
          <button
            type="button"
            class="-ml-1 flex size-7 shrink-0 items-center justify-center rounded-md text-muted hover:bg-input focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-expanded="openEntry === entry.target_id"
            :aria-label="t('changes.fields', { name: entry.label ?? '' })"
            @click="toggle(entry)"
          >
            <Icon
              name="lucide:chevron-right"
              :class="['transition-transform duration-200', openEntry === entry.target_id && 'rotate-90']"
            />
          </button>
          <RouterLink
            v-if="draftLink(entry)"
            :to="draftLink(entry)!"
            class="min-w-40 flex-1 truncate font-medium hover:underline"
          >
            {{ entry.label }}
          </RouterLink>
          <span
            v-else
            class="min-w-40 flex-1 truncate font-medium"
          >
            {{ entry.label }}
          </span>
          <span class="flex shrink-0 items-center gap-3 text-sm tabular-nums">
            <span
              v-if="entry.conflicts > 0"
              class="text-warning-ink"
              data-entry-conflicts
            >
              {{ t('changes.conflicts', { count: formatNumber(entry.conflicts) }, entry.conflicts) }}
            </span>
            <span class="text-muted">{{ t('changes.written', { count: formatNumber(entry.written) }, entry.written) }}</span>
            <RouterLink
              v-if="historyLink(entry)"
              :to="historyLink(entry)!"
              class="text-muted hover:text-primary hover:underline"
              data-entry-history
            >
              {{ t('changes.history') }}
            </RouterLink>
          </span>
        </div>
        <div
          v-if="openEntry === entry.target_id"
          class="border-t border-border px-4 py-3"
        >
          <Spinner v-if="entryItems.isLoading.value" />
          <ul
            v-else
            class="grid gap-4"
          >
            <li
              v-for="item in items"
              :key="item.id"
              :data-item="item.id"
            >
              <AiChangesetItem
                :item="item"
                :busy="busy"
                :locales="locales(item)"
                @accept="(overwrite) => decisions.accept(item, overwrite)"
                @reject="reject(item)"
              />
            </li>
          </ul>
          <Button
            v-if="entryItems.hasNextPage.value"
            size="sm"
            variant="ghost"
            class="mt-2 -ml-3"
            :loading="entryItems.isFetchingNextPage.value"
            @click="entryItems.fetchNextPage()"
          >
            {{ $t('components.aiThreads.review.showMore') }}
          </Button>
        </div>
      </li>
    </ul>
    <AiOperationPager
      v-model:page="page"
      class="px-4 py-2"
      :last-page="lastPage"
      :busy="isFetching"
    />
  </div>
</template>
