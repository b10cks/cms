<script setup lang="ts">
import { Icon as IconifyIcon } from '@iconify/vue'
import { sanitizeSvgBody } from '~/lib/sanitize'

const props = defineProps<{
  body: string
  width?: number
  height?: number
  size?: string | number
}>()

const iconData = computed(() => ({
  // Uploaded SVG markup is injected into the DOM by Iconify — sanitize it
  // client-side so any embedded script/event handler cannot execute.
  body: sanitizeSvgBody(props.body),
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
