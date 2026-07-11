<script setup lang="ts">
type ItemStatus = 'added' | 'removed' | 'unchanged'

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const toList = (value: unknown): string[] => (Array.isArray(value) ? value.map(String) : [])

const items = computed((): { value: string; status: ItemStatus }[] => {
  const oldList = toList(props.oldValue)
  const newList = toList(props.newValue)
  const oldSet = new Set(oldList)
  const newSet = new Set(newList)

  return [
    ...newList.map((value) => ({
      value,
      status: (oldSet.has(value) ? 'unchanged' : 'added') as ItemStatus,
    })),
    ...oldList
      .filter((value) => !newSet.has(value))
      .map((value) => ({ value, status: 'removed' as ItemStatus })),
  ]
})

const chipClasses = (status: ItemStatus): string => {
  switch (status) {
    case 'added':
      return 'border-success/30 bg-success/15 text-success'
    case 'removed':
      return 'border-destructive/30 bg-destructive/10 text-destructive line-through'
    default:
      return 'border-border text-muted-foreground'
  }
}
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
