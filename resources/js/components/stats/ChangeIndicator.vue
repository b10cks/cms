<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/**
 * The one way a change is shown: an arrow and the signed amount, green when it rises and red
 * when it falls. Zero renders nothing, or a muted "0" with `showZero`. `label` is the formatted
 * absolute amount.
 */
const props = defineProps<{
  /** Signed raw change; only its sign is read. */
  value: number
  label: string
  title?: string
  showZero?: boolean
}>()

const rising = computed(() => props.value > 0)
</script>

<template>
  <span
    v-if="value === 0 && showZero"
    class="text-sm font-semibold text-muted tabular-nums"
    :title="title"
  >0</span>
  <span
    v-else-if="value !== 0"
    class="inline-flex items-center gap-0.5 text-sm font-semibold tabular-nums"
    :class="rising ? 'text-success' : 'text-destructive'"
    :title="title"
  >
    <Icon
      :name="rising ? 'lucide:arrow-up' : 'lucide:arrow-down'"
      aria-hidden="true"
    />
    {{ rising ? '+' : '−' }}{{ label }}
  </span>
</template>
