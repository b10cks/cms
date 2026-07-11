<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffTextSegments, toDisplayText, type DiffSegment } from '~/utils/text-diff'

interface Row {
  key: string
  unchanged: boolean
  segments: DiffSegment[]
}

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

// Stable identity keys carry no reviewable information.
const IGNORED_KEYS = new Set(['id'])

const toRecord = (value: unknown): Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

const rows = computed((): Row[] => {
  const oldRecord = toRecord(props.oldValue)
  const newRecord = toRecord(props.newValue)
  const keys = [...new Set([...Object.keys(newRecord), ...Object.keys(oldRecord)])]

  return keys
    .filter((key) => !IGNORED_KEYS.has(key))
    .map((key): Row => {
      const oldText = toDisplayText(oldRecord[key])
      const newText = toDisplayText(newRecord[key])
      const unchanged = oldText === newText

      return {
        key,
        unchanged,
        segments: unchanged
          ? [{ type: 'equal', text: newText }]
          : diffTextSegments(oldText, newText),
      }
    })
    .filter((row) => !row.unchanged || row.segments[0].text !== '')
})
</script>

<template>
  <div class="space-y-1 text-sm">
    <div
      v-for="row in rows"
      :key="row.key"
      class="flex items-baseline gap-2"
    >
      <span class="text-muted-foreground w-24 shrink-0 truncate font-mono text-xs">{{
        row.key
      }}</span>
      <span
        class="min-w-0"
        :class="{ 'text-muted-foreground': row.unchanged }"
      >
        <DiffSegments :segments="row.segments" />
      </span>
    </div>
  </div>
</template>
