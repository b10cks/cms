<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { buildIlumUrl, type IlumModifiers } from '~/lib/ilum'
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
  modifiers?: IlumModifiers
  class?: HTMLAttributes['class']
  loading?: 'lazy' | 'eager'
  decoding?: 'async' | 'auto' | 'sync'
}>()

// SVGs scale natively — rasterizing them through ilum only degrades quality
const isSvg = computed(() => /\.svg$/i.test(props.src.split('?')[0] ?? ''))

const imageUrl = computed(() => {
  const baseURL = (runtimeConfig.public.ilum.baseURL || '').replace(/\/$/, '')

  if (isSvg.value) {
    return buildIlumUrl(props.src, {}, baseURL)
  }

  return buildIlumUrl(
    props.src,
    {
      ...props.modifiers,
      width: props.width ?? props.modifiers?.width,
      height: props.height ?? props.modifiers?.height,
      crop: props.crop ?? props.modifiers?.crop,
      gravity: props.gravity ?? props.modifiers?.gravity,
      format: props.format ?? props.modifiers?.format,
      quality: props.quality ?? props.modifiers?.quality,
    },
    baseURL
  )
})
</script>

<template>
  <img
    :src="imageUrl"
    :alt="alt"
    :width="isSvg ? undefined : width"
    :height="isSvg ? undefined : height"
    :class="class"
    :loading="loading || 'lazy'"
    :decoding="decoding || 'async'"
  />
</template>
