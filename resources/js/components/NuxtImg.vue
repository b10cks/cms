<script setup lang="ts">
import { runtimeConfig } from '~/lib/runtime-config'

interface IlumModifiers {
  width?: number
  height?: number
  crop?: 'fill' | 'fit' | 'crop'
  gravity?: 'face' | 'center' | 'auto' | string
  quality?: number
  format?: string
  x?: number
  y?: number
  targetWidth?: number
  targetHeight?: number
}

const props = defineProps<{
  src: string
  width?: number
  height?: number
  alt?: string
  format?: string
  quality?: number
  crop?: 'fill' | 'fit' | 'crop'
  gravity?: 'face' | 'center' | 'auto' | string
  class?: string
  loading?: 'lazy' | 'eager'
  decoding?: 'async' | 'auto' | 'sync'
}>()

const keyMap: Record<string, string> = {
  width: 'w',
  height: 'h',
  crop: 'c',
  gravity: 'g',
  quality: 'quality',
  format: 'format',
  x: 'x',
  y: 'y',
  targetWidth: 'tw',
  targetHeight: 'th',
}

function generateOperations(modifiers: IlumModifiers): string {
  const ops: string[] = []

  for (const [key, value] of Object.entries(modifiers)) {
    if (value !== undefined) {
      const mappedKey = keyMap[key]
      if (mappedKey) {
        if (mappedKey === 'g' && typeof value === 'string' && value.includes('_')) {
          ops.push(`${mappedKey}_${value}`)
        } else {
          ops.push(`${mappedKey}_${value}`)
        }
      }
    }
  }

  return ops.join(',')
}

const imageUrl = computed(() => {
  const baseURL = runtimeConfig.public.ilum.baseURL || ''
  const { format, quality, ...transformations } = {
    width: props.width,
    height: props.height,
    crop: props.crop,
    gravity: props.gravity,
    format: props.format,
    quality: props.quality,
  }

  let finalPath = props.src
  const ops = generateOperations(transformations as IlumModifiers)

  if (ops) {
    finalPath += `/${ops}`
  }

  const searchParams = new URLSearchParams()
  if (format) searchParams.set('format', format)
  if (quality) searchParams.set('quality', quality.toString())

  const queryString = searchParams.toString()
  if (queryString) {
    finalPath += `?${queryString}`
  }

  return baseURL + finalPath
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
