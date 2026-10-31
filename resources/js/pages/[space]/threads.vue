<script setup lang="ts">
import AiThreadList from '~/components/ai/threads/AiThreadList.vue'
import AiThreadView from '~/components/ai/threads/AiThreadView.vue'
import { aiDock, selectThread, startThread } from '~/lib/aiDock'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const spaceId = computed(() => route.params.space as string)
const aiEnabled = useAiEnabled(spaceId)
const routeThread = computed(() => (typeof route.params.thread === 'string' ? route.params.thread : null))
/** Without a thread in the route, or while composing, the page shows a new thread. */
const threadId = computed(() => (aiDock.state.composing ? null : routeThread.value))

const show = (id: string) => {
  selectThread(id)
  void router.push({ name: 'space-ai-threads', params: { space: spaceId.value, thread: id } })
}

const created = (id: string) => {
  void router.replace({ name: 'space-ai-threads', params: { space: spaceId.value, thread: id } })
}

useSeoMeta({
  title: computed(() => t('components.aiThreads.title')),
})
</script>

<template>
  <div
    v-if="!aiEnabled"
    class="flex h-[calc(100svh-3.5rem)] w-full flex-col items-center justify-center gap-1 text-sm text-muted"
  >
    <p>{{ $t('components.aiThreads.disabled') }}</p>
    <RouterLink
      :to="{ name: 'space-settings-ai', params: { space: spaceId } }"
      class="text-foreground underline-offset-4 hover:underline"
    >
      {{ $t('components.aiThreads.enableInSettings') }}
    </RouterLink>
  </div>
  <div
    v-else
    class="flex h-[calc(100svh-3.5rem)] w-full min-w-0"
  >
    <AiThreadList
      class="w-80 shrink-0 border-r border-border"
      :space-id="spaceId"
      :active-id="threadId"
      @select="show"
      @new="startThread()"
    />
    <div class="min-w-0 flex-1">
      <AiThreadView
        :key="threadId ?? 'new'"
        :space-id="spaceId"
        :thread-id="threadId"
        layout="page"
        @created="created"
      />
    </div>
  </div>
</template>
