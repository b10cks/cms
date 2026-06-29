<script setup lang="ts">
import { Icon as IconifyIcon } from '@iconify/vue'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
  name?: string | null
  size?: string | number
  class?: HTMLAttributes['class']
}>()

const iconData = computed(() => {
  const icon = props.name
  if (!icon) return null

  if (icon.startsWith('lucide:')) return icon
  if (icon.startsWith('flag:')) return icon

  if (icon.startsWith('b10cks:')) {
    return `custom:${icon.slice('b10cks:'.length)}`
  }

  return icon
})

const iconSize = computed(() => {
  if (props.size) {
    return typeof props.size === 'number' ? `${props.size}px` : props.size
  }
  return '1rem'
})
</script>

<template>
  <IconifyIcon
    v-if="iconData"
    :icon="iconData"
    :width="iconSize"
    :height="iconSize"
    :class="props.class"
  />
</template>
