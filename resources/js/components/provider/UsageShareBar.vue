<script setup lang="ts">
import { cn } from '~/lib/utils'

/** Share of a quota as a figure and a bar. Above 85 % the bar switches to the second series colour. */
const props = defineProps<{ share: number | null; detail?: string }>()

const percent = computed(() => (props.share === null ? null : Math.round(props.share * 100)))
</script>

<template>
  <div
    class="flex items-center gap-2"
    :title="detail"
  >
    <template v-if="percent !== null">
      <span class="w-10 text-right text-sm tabular-nums">{{ percent }}%</span>
      <span class="inline-block h-1.5 w-24 overflow-hidden rounded-full bg-secondary">
        <span
          :class="cn('block h-full rounded-full', percent > 85 ? 'bg-series-2' : 'bg-series-1')"
          :style="{ width: `${Math.min(percent, 100)}%` }"
        />
      </span>
    </template>
    <span
      v-else
      class="text-sm text-muted"
    >{{ detail ?? $t('providerConsole.usage.unlimited') }}</span>
  </div>
</template>
