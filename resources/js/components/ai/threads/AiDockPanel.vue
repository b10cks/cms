<script setup lang="ts">
import AiThreadList from '~/components/ai/threads/AiThreadList.vue'
import AiThreadView from '~/components/ai/threads/AiThreadView.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { aiDock, closeDock, openDock, selectThread, startThread } from '~/lib/aiDock'

/** What the dock shows: the open thread, a new one, or the thread list. */
defineProps<{
  spaceId: string
}>()

const emit = defineEmits<{
  expand: []
}>()

const showsThread = computed(() => aiDock.state.threadId !== null || aiDock.state.composing)
</script>

<template>
  <AiThreadView
    v-if="showsThread"
    :key="aiDock.state.threadId ?? 'new'"
    :space-id="spaceId"
    :thread-id="aiDock.state.threadId"
    layout="dock"
    @back="selectThread(null)"
    @expand="emit('expand')"
    @close="closeDock"
  />
  <div
    v-else
    class="flex h-full min-h-0 flex-col"
  >
    <header class="flex h-14 shrink-0 items-center gap-2 border-b border-border pr-3 pl-4">
      <h2 class="min-w-0 flex-1 truncate font-semibold text-primary">{{ $t('components.aiThreads.title') }}</h2>
      <Button
        size="toolbar"
        variant="ghost"
        :title="$t('components.aiThreads.expand')"
        :aria-label="$t('components.aiThreads.expand')"
        @click="emit('expand')"
      >
        <Icon name="lucide:maximize-2" />
      </Button>
      <Button
        size="toolbar"
        variant="ghost"
        :title="$t('components.aiThreads.close')"
        :aria-label="$t('components.aiThreads.close')"
        @click="closeDock"
      >
        <Icon name="lucide:x" />
      </Button>
    </header>
    <AiThreadList
      class="min-h-0 flex-1"
      :space-id="spaceId"
      :active-id="null"
      @select="openDock"
      @new="startThread()"
    />
  </div>
</template>
