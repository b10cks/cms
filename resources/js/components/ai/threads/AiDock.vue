<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core'
import { toast } from 'vue-sonner'

import AiDockPanel from '~/components/ai/threads/AiDockPanel.vue'
import { Sheet, SheetContent, SheetTitle } from '~/components/ui/sheet'
import { aiDock, closeDock, openDock, resetDock, toggleDock } from '~/lib/aiDock'
import type { AiThreadStatus } from '~/lib/aiThreads'
import { attentionTransitions } from '~/lib/aiThreads'

/**
 * The AI dock of a space: a resizable split that pushes the page on wide
 * screens, a sheet below 1280px. Toggled with Cmd+J. Hidden on the threads
 * page, which shows the same threads full size. Raises a toast when a thread
 * that isn't open needs the user or failed.
 */
const props = defineProps<{
  spaceId: string
}>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const wide = useMediaQuery('(min-width: 1280px)')

const onThreadsPage = computed(() => route.name === 'space-ai-threads')
const visible = computed(() => aiDock.state.open && !onThreadsPage.value)

useShortcut({
  keys: 'mod+j',
  description: () => t('shortcuts.global.toggleAiDock'),
  allowInInput: true,
  handler: () => {
    if (onThreadsPage.value) openDock()
    else toggleDock()
  },
})

watch(
  () => props.spaceId,
  () => resetDock()
)

const expand = () => {
  void router.push({
    name: 'space-ai-threads',
    params: { space: props.spaceId, thread: aiDock.state.threadId ?? undefined },
  })
}

const openThread = (threadId: string) => {
  if (onThreadsPage.value) void router.push({ name: 'space-ai-threads', params: { space: props.spaceId, thread: threadId } })
  else openDock(threadId)
}

const { useThreadsQuery } = useAiThreads(() => props.spaceId)
const { data: threads, isPlaceholderData } = useThreadsQuery()

/** The thread the user is looking at, which never toasts. */
const openThreadId = computed(() => {
  if (onThreadsPage.value) return typeof route.params.thread === 'string' ? route.params.thread : null
  return aiDock.state.open ? aiDock.state.threadId : null
})

let baseline: Map<string, AiThreadStatus> | null = null
watch(
  () => props.spaceId,
  () => (baseline = null)
)
watch(
  () => threads.value?.data,
  (list) => {
    if (!list || isPlaceholderData.value) return
    if (baseline) {
      for (const thread of attentionTransitions(baseline, list, openThreadId.value)) {
        toast(t(`components.aiThreads.toast.${thread.status}`, { title: thread.title }), {
          action: { label: t('components.aiThreads.open'), onClick: () => openThread(thread.id) },
        })
      }
    }
    baseline = new Map(list.map((thread) => [thread.id, thread.status]))
  }
)

let dragStart: { x: number; width: number } | null = null
const onHandleDown = (event: PointerEvent) => {
  if (!(event.currentTarget instanceof HTMLElement)) return
  event.currentTarget.setPointerCapture(event.pointerId)
  dragStart = { x: event.clientX, width: aiDock.width.value }
}
const onHandleMove = (event: PointerEvent) => {
  if (dragStart) aiDock.width.value = dragStart.width + dragStart.x - event.clientX
}
const onHandleKey = (event: KeyboardEvent) => {
  const step = event.key === 'ArrowLeft' ? 24 : event.key === 'ArrowRight' ? -24 : 0
  if (step === 0) return
  event.preventDefault()
  aiDock.width.value += step
}
</script>

<template>
  <aside
    v-if="visible && wide"
    class="sticky top-14 flex h-[calc(100svh-3.5rem)] shrink-0 border-l border-border bg-background"
    :style="{ width: `${aiDock.width.value}px` }"
    :aria-label="$t('components.aiThreads.title')"
  >
    <div
      role="separator"
      tabindex="0"
      aria-orientation="vertical"
      :aria-label="$t('components.aiThreads.resize')"
      :aria-valuenow="aiDock.width.value"
      class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize after:absolute after:inset-y-0 after:left-1/2 after:w-px after:-translate-x-1/2 after:transition-colors hover:after:bg-ring focus-visible:outline-none focus-visible:after:bg-ring"
      @pointerdown="onHandleDown"
      @pointermove="onHandleMove"
      @pointerup="dragStart = null"
      @pointercancel="dragStart = null"
      @keydown="onHandleKey"
    />
    <div class="min-w-0 flex-1">
      <AiDockPanel
        :space-id="spaceId"
        @expand="expand"
      />
    </div>
  </aside>

  <Sheet
    v-else-if="!wide"
    :open="visible"
    @update:open="(open) => (open ? openDock() : closeDock())"
  >
    <SheetContent
      side="right"
      class="flex w-full! flex-col gap-0 p-0! sm:max-w-md! [&>button:last-child]:hidden"
    >
      <SheetTitle class="sr-only">{{ $t('components.aiThreads.title') }}</SheetTitle>
      <AiDockPanel
        :space-id="spaceId"
        @expand="expand"
      />
    </SheetContent>
  </Sheet>
</template>
