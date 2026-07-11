<script setup lang="ts">
import { chipClasses, type ChipStatus } from '~/components/content/diff/chips'
import { toDisplayText } from '~/utils/text-diff'

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const toList = (value: unknown): string[] => (Array.isArray(value) ? value.map(toDisplayText) : [])

// Multiset matching, so duplicate entries keep add/remove fidelity.
const items = computed((): { value: string; status: ChipStatus }[] => {
  const oldList = toList(props.oldValue)
  const newList = toList(props.newValue)

  const remaining = new Map<string, number>()
  for (const value of oldList) {
    remaining.set(value, (remaining.get(value) ?? 0) + 1)
  }

  const result: { value: string; status: ChipStatus }[] = newList.map((value) => {
    const left = remaining.get(value) ?? 0
    if (left > 0) {
      remaining.set(value, left - 1)
      return { value, status: 'unchanged' }
    }
    return { value, status: 'added' }
  })

  for (const value of oldList) {
    const left = remaining.get(value) ?? 0
    if (left > 0) {
      remaining.set(value, left - 1)
      result.push({ value, status: 'removed' })
    }
  }

  return result
})
</script>

<template>
  <div class="flex flex-wrap gap-1.5 text-sm">
    <span
      v-for="(item, index) in items"
      :key="`${item.status}-${item.value}-${index}`"
      class="rounded-full border px-2 py-0.5 text-xs"
      :class="chipClasses(item.status)"
    >
      {{ item.value }}
    </span>
  </div>
</template>
