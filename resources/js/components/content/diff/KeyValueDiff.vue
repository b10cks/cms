<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffTextSegments } from '~/utils/text-diff'

type RowStatus = 'added' | 'removed' | 'changed' | 'unchanged'

interface Row {
  key: string
  oldText: string
  newText: string
  status: RowStatus
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

const format = (value: unknown): string => {
  if (value == null) return ''
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

const rows = computed((): Row[] => {
  const oldRecord = toRecord(props.oldValue)
  const newRecord = toRecord(props.newValue)
  const keys = [...new Set([...Object.keys(newRecord), ...Object.keys(oldRecord)])]

  return keys
    .filter((key) => !IGNORED_KEYS.has(key))
    .map((key): Row => {
      const inOld = key in oldRecord && oldRecord[key] != null
      const inNew = key in newRecord && newRecord[key] != null
      const oldText = format(oldRecord[key])
      const newText = format(newRecord[key])

      let status: RowStatus = 'unchanged'
      if (inOld && !inNew) status = 'removed'
      else if (!inOld && inNew) status = 'added'
      else if (oldText !== newText) status = 'changed'

      return { key, oldText, newText, status }
    })
    .filter((row) => row.status !== 'unchanged' || row.newText !== '')
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
      <span class="min-w-0">
        <template v-if="row.status === 'changed'">
          <DiffSegments :segments="diffTextSegments(row.oldText, row.newText)" />
        </template>
        <ins
          v-else-if="row.status === 'added'"
          class="rounded-sm bg-success/25 text-success no-underline"
          >{{ row.newText }}</ins
        >
        <del
          v-else-if="row.status === 'removed'"
          class="rounded-sm bg-destructive/25 text-destructive line-through decoration-destructive/60"
          >{{ row.oldText }}</del
        >
        <span
          v-else
          class="text-muted-foreground"
          >{{ row.newText }}</span
        >
      </span>
    </div>
  </div>
</template>
