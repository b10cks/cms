<script setup lang="ts">
import { Icon as IconifyIcon } from '@iconify/vue'

const props = defineProps<{
  name: string
  size?: string | number
  class?: string
}>()

const iconData = computed(() => {
  const icon = props.name

  if (icon.startsWith('lucide:')) {
    return icon
  }

  if (icon.startsWith('flag:')) {
    return icon
  }

  if (icon.startsWith('b10cks:')) {
    const iconName = icon.replace('b10cks:', '')
    return `custom:${iconName}`
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
    :icon="iconData"
    :width="iconSize"
    :height="iconSize"
    :class="props.class"
  />
</template>
