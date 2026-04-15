<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { buildIlumUrl } from '~/lib/ilum'
import { runtimeConfig } from '~/lib/runtime-config'

const props = defineProps<{
  src: string
  width?: number
  height?: number
  alt?: string
  format?: string
  quality?: number
  crop?: 'fill' | 'fit' | 'crop'
  gravity?: 'face' | 'center' | 'auto' | string
  class?: HTMLAttributes['class']
  loading?: 'lazy' | 'eager'
  decoding?: 'async' | 'auto' | 'sync'
}>()

const imageUrl = computed(() => {
  const baseURL = (runtimeConfig.public.ilum.baseURL || '').replace(/\/$/, '')

  return buildIlumUrl(props.src, {
    width: props.width,
    height: props.height,
    crop: props.crop,
    gravity: props.gravity,
    format: props.format,
    quality: props.quality,
  }, baseURL)
})
</script>

<template>
  <img
    :src="imageUrl"
    :alt="alt"
    :width="width"
    :height="height"
    :class="class"
    :loading="loading || 'lazy'"
    :decoding="decoding || 'async'"
  />
</template>
