<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import NuxtImg from '~/components/NuxtImg.vue'

import type { AvatarVariants } from '.'
import { avatarVariants } from '.'

const props = defineProps<{
  name: string
  avatar?: string | null
  borderColor?: string | null
  size?: AvatarVariants['size']
  class?: HTMLAttributes['class']
}>()

const initials = computed(() => {
  const name = props.name
  if (!name) return ''
  const names = name.split(' ')
  if (names.length === 1) return names[0].charAt(0).toUpperCase()
  return names[0].charAt(0).toUpperCase() + names[1].charAt(0).toUpperCase()
})

const width = computed(() => {
  switch (props.size) {
    case 'sm':
      return 32
    case 'lg':
      return 96
    default:
      return 64
  }
})

const fontSize = computed(() => {
  switch (props.size) {
    case 'sm':
      return 'text-[8px] font-bold'
    default:
      return 'text-xs font-bold'
  }
})
</script>

<template>
  <div
    :class="
      cn(avatarVariants({ size }), props.borderColor ? 'border-2' : 'border-none', props.class)
    "
    :style="{ borderColor: props.borderColor || 'transparent' }"
  >
    <NuxtImg
      v-if="props.avatar"
      :src="props.avatar"
      :alt="initials"
      :width="width"
      :height="width"
      class="h-full w-full object-cover"
    />
    <span
      v-else
      :class="fontSize"
      >{{ initials }}</span
    >
  </div>
</template>
