<script setup lang="ts">
import { refDebounced, useIntervalFn, useLocalStorage } from '@vueuse/core'

import AiThreadRow from '~/components/ai/threads/AiThreadRow.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Skeleton } from '~/components/ui/skeleton'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '~/components/ui/tooltip'
import type { AiThread } from '~/lib/aiThreads'
import { groupThreads } from '~/lib/aiThreads'

/**
 * The threads the user can see, modelled on T3 Code's sidebar: a ghost search
 * row with the new-thread button, the active threads (needs-you first, as the
 * server sorts them; shared threads of others in their own group), and the
 * settled ones on a collapsed shelf at the bottom, loaded only when opened.
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

const search = ref('')
const debouncedSearch = refDebounced(search, 250)
const settledOpen = useLocalStorage('b10cks:ai-threads-settled-open', false)
const now = ref(Date.now())
const { pause, resume } = useIntervalFn(() => (now.value = Date.now()), 1000, { immediate: false })

const { useThreadsQuery, updateMutation } = useAiThreads(() => props.spaceId)
const { data, isLoading, isPlaceholderData } = useThreadsQuery(() => ({
  archived: false,
  search: debouncedSearch.value.trim(),
}))
const { data: settledData, isLoading: settledLoading } = useThreadsQuery(
  () => ({ archived: true, search: debouncedSearch.value.trim() }),
  settledOpen
)

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManage = computed(() => access.hasAbility('ai.manage'))
const canSettle = (thread: AiThread) => thread.is_owner || (thread.kind !== 'chat' && canManage.value)

const groups = computed(() => {
  const { mine, shared } = groupThreads(data.value?.data ?? [])
  return [
    { key: 'mine', label: null, threads: mine },
    { key: 'shared', label: t('components.aiThreads.shared'), threads: shared },
  ].filter((group) => group.threads.length > 0)
})

/** The clock ticks only while a thread runs, for its elapsed time. */
watch(
  () => data.value?.data.some((thread) => thread.status === 'running') ?? false,
  (running) => {
    now.value = Date.now()
    if (running) resume()
    else pause()
  },
  { immediate: true }
)

const settledCount = computed(() => data.value?.meta.settled ?? 0)
const settledThreads = computed(() => settledData.value?.data ?? [])

const settle = (thread: AiThread, archived: boolean) =>
  updateMutation.mutate({ threadId: thread.id, patch: { archived } })

const clearSearch = () => {
  search.value = ''
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <div class="flex shrink-0 items-center gap-1 px-2 pt-2 pb-1">
      <label
        class="flex h-8 min-w-0 flex-1 cursor-text items-center gap-2 rounded-md px-2 text-sm text-muted hover:bg-tree-hover hover:text-foreground focus-within:bg-tree-hover focus-within:text-foreground focus-within:ring-1 focus-within:ring-ring focus-within:ring-inset"
      >
        <Icon
          name="lucide:search"
          class="size-4 shrink-0"
        />
        <input
          v-model="search"
          type="search"
          class="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted [&::-webkit-search-cancel-button]:hidden"
          :placeholder="$t('components.aiThreads.searchPlaceholder')"
          :aria-label="$t('components.aiThreads.search')"
          @keydown.esc="clearSearch"
        />
        <button
          v-if="search"
          type="button"
          class="flex size-5 shrink-0 items-center justify-center rounded-sm text-muted hover:text-foreground"
          :aria-label="$t('components.aiThreads.clearSearch')"
          @click="clearSearch"
        >
          <Icon
            name="lucide:x"
            class="size-3.5"
          />
        </button>
      </label>
      <TooltipProvider :delay-duration="300">
        <Tooltip>
          <TooltipTrigger as-child>
            <Button
              size="toolbar"
              variant="ghost"
              class="shrink-0"
              :aria-label="$t('components.aiThreads.new')"
              data-new-thread
              @click="emit('new')"
            >
              <Icon name="lucide:square-pen" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{{ $t('components.aiThreads.new') }}</TooltipContent>
        </Tooltip>
      </TooltipProvider>
    </div>

    <div
      class="min-h-0 flex-1 overflow-y-auto px-2 pb-2 transition-opacity"
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
          class="h-14 w-full"
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
        class="mb-2 last:mb-0"
      >
        <h3
          v-if="group.label"
          class="flex h-8 items-center gap-2 px-2 text-sm font-medium text-muted"
        >
          <span class="shrink-0">{{ group.label }}</span>
          <span
            class="h-px min-w-2 flex-1 bg-border"
            aria-hidden="true"
          />
        </h3>
        <ul>
          <AiThreadRow
            v-for="thread in group.threads"
            :key="thread.id"
            :thread="thread"
            :active="thread.id === activeId"
            :settled="false"
            :can-settle="canSettle(thread)"
            :now="thread.status === 'running' ? now : 0"
            @select="emit('select', thread.id)"
            @settle="settle(thread, true)"
          />
        </ul>
      </section>

      <section
        v-if="settledCount > 0 || settledOpen"
        class="mt-2"
        data-settled-shelf
      >
        <button
          type="button"
          class="flex h-8 w-full cursor-pointer items-center gap-2 rounded-md px-2 text-left text-sm font-medium text-muted focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
          :aria-expanded="settledOpen"
          data-settled-toggle
          @click="settledOpen = !settledOpen"
        >
          <span class="shrink-0">{{ $t('components.aiThreads.settled') }}</span>
          <span class="flex-1" />
          <span class="tabular-nums">{{ settledCount }}</span>
          <Icon
            name="lucide:chevron-down"
            class="size-3.5 shrink-0 transition-transform"
            :class="!settledOpen && '-rotate-90'"
          />
        </button>
        <template v-if="settledOpen">
          <div
            v-if="settledLoading"
            class="grid gap-3 p-2"
            aria-hidden="true"
          >
            <Skeleton
              v-for="index in Math.min(3, Math.max(1, settledCount))"
              :key="index"
              class="h-14 w-full"
            />
          </div>
          <ul v-else>
            <AiThreadRow
              v-for="thread in settledThreads"
              :key="thread.id"
              :thread="thread"
              :active="thread.id === activeId"
              settled
              :can-settle="canSettle(thread)"
              :now="thread.status === 'running' ? now : 0"
              @select="emit('select', thread.id)"
              @settle="settle(thread, false)"
            />
          </ul>
        </template>
      </section>
    </div>
  </div>
</template>
