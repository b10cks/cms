<script setup lang="ts">
import { refDebounced } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Skeleton } from '~/components/ui/skeleton'
import { Switch } from '~/components/ui/switch'
import type { AiThread } from '~/lib/aiThreads'
import { groupThreads, STATUS_DOT_CLASSES } from '~/lib/aiThreads'

/**
 * The threads the user can see, needs-you first then by last activity, as the
 * server sorts them. Shared threads of others sit in their own group.
 */
const props = defineProps<{
  spaceId: string
  activeId: string | null
}>()

const emit = defineEmits<{
  select: [threadId: string]
  new: []
}>()

const { t } = useI18n()
const { formatRelativeTime } = useFormat()

const search = ref('')
const archived = ref(false)
const debouncedSearch = refDebounced(search, 250)

const { useThreadsQuery } = useAiThreads(() => props.spaceId)
const { data, isLoading, isPlaceholderData } = useThreadsQuery(() => ({
  archived: archived.value,
  search: debouncedSearch.value.trim(),
}))

const groups = computed(() => {
  const { mine, shared } = groupThreads(data.value?.data ?? [])
  return [
    { key: 'mine', label: null, threads: mine },
    { key: 'shared', label: t('components.aiThreads.shared'), threads: shared },
  ].filter((group) => group.threads.length > 0)
})

const meta = (thread: AiThread) =>
  [
    thread.is_owner || !thread.owner ? null : thread.owner.name,
    thread.entry_count > 0 ? t('components.aiThreads.toReview', { count: thread.entry_count }) : null,
    thread.last_activity_at ? formatRelativeTime(thread.last_activity_at) : null,
  ]
    .filter(Boolean)
    .join(', ')
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <div class="grid shrink-0 gap-2 border-b border-border p-3">
      <div class="flex items-center gap-2">
        <Input
          v-model="search"
          type="search"
          class="h-8 min-w-0 flex-1"
          :placeholder="$t('components.aiThreads.search')"
          :aria-label="$t('components.aiThreads.search')"
        />
        <Button
          size="sm"
          variant="ai"
          @click="emit('new')"
        >
          <Icon name="lucide:plus" />
          {{ $t('components.aiThreads.new') }}
        </Button>
      </div>
      <label class="flex items-center gap-2 text-sm text-muted">
        <Switch
          :model-value="archived"
          @update:model-value="(value: boolean) => (archived = value)"
        />
        {{ $t('components.aiThreads.showArchived') }}
      </label>
    </div>

    <div
      class="min-h-0 flex-1 overflow-y-auto p-2 transition-opacity"
      :class="isPlaceholderData && 'opacity-60'"
    >
      <div
        v-if="isLoading"
        class="grid gap-3 p-2"
        aria-hidden="true"
      >
        <Skeleton
          v-for="index in 5"
          :key="index"
          class="h-10 w-full"
        />
      </div>
      <p
        v-else-if="groups.length === 0"
        class="px-3 py-8 text-center text-sm text-muted"
      >
        {{ search ? $t('components.aiThreads.noMatches') : $t('components.aiThreads.empty') }}
      </p>
      <section
        v-for="group in groups"
        :key="group.key"
        class="mb-3 last:mb-0"
      >
        <h3
          v-if="group.label"
          class="px-3 pt-2 pb-1 text-sm font-semibold text-muted"
        >
          {{ group.label }}
        </h3>
        <ul class="grid gap-0.5">
          <li
            v-for="thread in group.threads"
            :key="thread.id"
          >
            <button
              type="button"
              class="flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left hover:bg-tree-hover focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
              :class="thread.id === activeId && 'bg-tree-selected text-primary'"
              :aria-current="thread.id === activeId ? 'true' : undefined"
              :data-thread="thread.id"
              @click="emit('select', thread.id)"
            >
              <span
                :class="['mt-2 size-2 shrink-0 rounded-full', STATUS_DOT_CLASSES[thread.status]]"
                :title="$t(`components.aiThreads.status.${thread.status}`)"
              />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium">{{ thread.title }}</span>
                <span
                  v-if="meta(thread)"
                  class="block truncate text-sm text-muted"
                >
                  {{ meta(thread) }}
                </span>
              </span>
              <Icon
                v-if="thread.shared && thread.is_owner"
                name="lucide:users"
                class="mt-1 shrink-0 text-muted"
                :aria-label="$t('components.aiThreads.sharedWithSpace')"
              />
            </button>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
