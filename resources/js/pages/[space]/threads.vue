<script setup lang="ts">
import AiThreadList from '~/components/ai/threads/AiThreadList.vue'
import AiThreadView from '~/components/ai/threads/AiThreadView.vue'
import Icon from '~/components/Icon.vue'
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

/** A phone shows the list or one thread. */
const showsThread = computed(() => aiDock.state.composing || routeThread.value !== null)

const backToList = () => {
  selectThread(null)
  void router.push({ name: 'space-ai-threads', params: { space: spaceId.value } })
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
    class="flex h-[calc(100svh-3.5rem)] w-full min-w-0 phone:h-[calc(100dvh-3.5rem-var(--phone-tabbar))]"
  >
    <AiThreadList
      :class="['w-80 shrink-0 border-r border-border phone:w-full phone:border-r-0', showsThread && 'phone:hidden']"
      :space-id="spaceId"
      :active-id="threadId"
      @select="show"
      @new="startThread()"
    />
    <div :class="['flex min-w-0 flex-1 flex-col', !showsThread && 'phone:hidden']">
      <button
        type="button"
        class="hidden h-11 shrink-0 cursor-pointer items-center gap-2 border-b border-border bg-sidebar px-3 text-sm font-medium phone:flex"
        @click="backToList"
      >
        <Icon name="lucide:arrow-left" />
        {{ $t('components.aiThreads.title') }}
      </button>
      <AiThreadView
        class="min-h-0 flex-1"
        :key="threadId ?? 'new'"
        :space-id="spaceId"
        :thread-id="threadId"
        layout="page"
        @created="created"
      />
    </div>
  </div>
</template>
