<script setup lang="ts">
import { useIntervalFn } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import type { AiRunStep, AiThreadStep } from '~/lib/aiThreads'

/**
 * What a turn did, as one quiet line: "Working for 12s" while the run goes,
 * "Worked for 14s, 4 steps" after. Opens into the tool calls in order.
 */
const props = defineProps<{
  steps: readonly (AiRunStep | AiThreadStep)[]
  running: boolean
  /** ISO string from the run record, or epoch ms for the live turn */
  startedAt: string | number | null
  finishedAt: string | null
}>()

const { t, te } = useI18n()

const open = ref(false)
const now = shallowRef(Date.now())
const ticker = useIntervalFn(() => (now.value = Date.now()), 1000, { immediate: false })
watch(
  () => props.running,
  (running) => {
    now.value = Date.now()
    if (running) ticker.resume()
    else ticker.pause()
  },
  { immediate: true }
)

const isPending = (step: AiRunStep | AiThreadStep) => 'done' in step && !step.done

const duration = computed(() => {
  if (props.startedAt === null) return null
  const end = props.finishedAt ? Date.parse(props.finishedAt) : props.running ? now.value : null
  if (end === null) return null
  const total = Math.max(0, Math.round((end - new Date(props.startedAt).getTime()) / 1000))
  const minutes = Math.floor(total / 60)
  return minutes > 0
    ? t('components.aiThreads.duration.minutes', { m: minutes, s: total % 60 })
    : t('components.aiThreads.duration.seconds', { s: total })
})

const summary = computed(() => {
  const count = props.steps.length > 0 ? t('components.aiThreads.workSteps', props.steps.length) : null
  if (!duration.value) return count
  const label = t(props.running ? 'components.aiThreads.working' : 'components.aiThreads.worked', {
    duration: duration.value,
  })
  return count && !props.running ? `${label}, ${count}` : label
})

const toolLabel = (name: string) => {
  const key = `components.aiThreads.tools.${name}`
  return te(key) ? t(key) : t('components.aiThreads.usingTool', { tool: name })
}

const current = computed(() => (props.running ? props.steps.findLast(isPending) : undefined))
</script>

<template>
  <div
    v-if="summary"
    class="grid gap-1.5 text-sm"
  >
    <button
      type="button"
      class="inline-flex w-fit items-center gap-1 rounded-md text-muted transition-colors hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none"
      :aria-expanded="open"
      :disabled="steps.length === 0"
      @click="open = !open"
    >
      <span :class="running && 'ai-animate-text'">{{ summary }}</span>
      <Icon
        v-if="steps.length > 0"
        name="lucide:chevron-right"
        class="size-3.5 transition-transform"
        :class="open && 'rotate-90'"
      />
    </button>

    <p
      v-if="current && !open"
      class="flex items-center gap-2 text-muted"
      role="status"
    >
      <Icon
        name="lucide:loader"
        class="animate-spin"
      />
      <span class="truncate">{{ toolLabel(current.name) }}</span>
    </p>

    <ol
      v-if="open"
      class="ml-1.5 grid gap-1 border-l border-border pl-3"
      :aria-label="$t('components.aiThreads.steps')"
    >
      <li
        v-for="step in steps"
        :key="step.id"
        class="flex items-center gap-2"
        :class="step.ok ? 'text-muted' : 'text-destructive'"
      >
        <Icon
          :name="isPending(step) ? 'lucide:loader' : step.ok ? 'lucide:check' : 'lucide:x'"
          :class="isPending(step) && 'animate-spin'"
        />
        <span class="truncate">{{ toolLabel(step.name) }}</span>
      </li>
    </ol>
  </div>
</template>
