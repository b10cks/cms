<script setup lang="ts">
import { AI_REASONING_EFFORTS, type AiReasoningEffort } from '~/api/resources/ai'

/** Three rising bars, filled up to the reasoning level; `off` leaves them all empty. */
const props = defineProps<{ level: AiReasoningEffort }>()

const filled = computed(() => AI_REASONING_EFFORTS.indexOf(props.level))
</script>

<template>
  <span
    class="inline-flex h-2.75 shrink-0 items-end gap-0.5"
    aria-hidden="true"
  >
    <span
      v-for="bar in 3"
      :key="bar"
      class="w-0.75 rounded-[1px] transition-colors duration-200"
      :class="bar <= filled ? 'bg-ai-tint-foreground' : 'bg-muted/35'"
      :style="{ height: `${1 + bar * 3}px` }"
    />
  </span>
</template>
