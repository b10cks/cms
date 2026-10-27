<script setup lang="ts">
import AiThreadList from '~/components/ai/threads/AiThreadList.vue'
import AiThreadView from '~/components/ai/threads/AiThreadView.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { aiDock, selectThread, startThread } from '~/lib/aiDock'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const spaceId = computed(() => route.params.space as string)
const routeThread = computed(() => (typeof route.params.thread === 'string' ? route.params.thread : null))
const composing = computed(() => aiDock.state.composing)
const threadId = computed(() => (composing.value ? null : routeThread.value))

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
  <div class="flex h-[calc(100svh-3.5rem)] w-full min-w-0">
    <AiThreadList
      class="w-80 shrink-0 border-r border-border"
      :space-id="spaceId"
      :active-id="threadId"
      @select="show"
      @new="startThread()"
    />
    <div class="min-w-0 flex-1">
      <AiThreadView
        v-if="threadId || composing"
        :key="threadId ?? 'new'"
        :space-id="spaceId"
        :thread-id="threadId"
        layout="page"
        @created="created"
      />
      <div
        v-else
        class="flex h-full flex-col items-center justify-center gap-4 p-6 text-center"
      >
        <Icon
          name="lucide:sparkles"
          size="32"
          class="text-ai"
        />
        <p class="text-muted">{{ $t('components.aiThreads.pickThread') }}</p>
        <Button
          variant="ai"
          @click="startThread()"
        >
          <Icon name="lucide:plus" />
          {{ $t('components.aiThreads.new') }}
        </Button>
      </div>
    </div>
  </div>
</template>
