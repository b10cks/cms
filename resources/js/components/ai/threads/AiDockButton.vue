<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { aiDock, toggleDock } from '~/lib/aiDock'

/** Header toggle for the AI dock with the count of threads that need the user. */
const props = defineProps<{
  spaceId: string
}>()

const { t } = useI18n()
const { useThreadsQuery } = useAiThreads(() => props.spaceId)
const { data } = useThreadsQuery()

const attention = computed(() => data.value?.meta.attention ?? 0)
const label = computed(() =>
  attention.value > 0
    ? t('components.aiThreads.toggleWithCount', { count: attention.value })
    : t('components.aiThreads.toggle')
)
</script>

<template>
  <button
    type="button"
    class="icon-anim relative flex size-9 cursor-pointer items-center justify-center rounded-lg text-sidebar-foreground transition-colors duration-200 hover:bg-sidebar-accent focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
    :class="aiDock.state.open && 'bg-sidebar-accent text-sidebar-accent-foreground'"
    :aria-label="label"
    :aria-pressed="aiDock.state.open"
    :title="`${label} (⌘J)`"
    @click="toggleDock"
  >
    <Icon
      name="ai:bot"
      class="text-ai"
    />
    <span
      v-if="attention > 0"
      class="pointer-events-none absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] leading-4 font-semibold text-white"
      aria-hidden="true"
    >
      {{ attention > 99 ? '99+' : attention }}
    </span>
  </button>
</template>
