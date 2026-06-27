<script setup lang="ts">
import { Icon as IconifyIcon } from '@iconify/vue'

const props = defineProps<{
  body: string
  width?: number
  height?: number
  size?: string | number
}>()

const iconData = computed(() => ({
  body: props.body,
  width: props.width ?? 24,
  height: props.height ?? 24,
}))

const sizeValue = computed(() =>
  props.size ? (typeof props.size === 'number' ? `${props.size}px` : props.size) : '1.5rem'
)
</script>

<template>
  <IconifyIcon
    :icon="iconData"
    :width="sizeValue"
    :height="sizeValue"
  />
</template>
