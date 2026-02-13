<script setup lang="ts">
import NuxtImg from '~/components/NuxtImg.vue'

import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import type { AvatarVariants } from '.'
import { avatarVariants } from '.'

const props = defineProps<{
  name: string
  avatar?: string
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
      return 16
    case 'lg':
      return 48
    default:
      return 32
  }
})
</script>

<template>
  <div :class="cn(avatarVariants({ size }), props.class)">
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
      class="text-xs font-bold"
      >{{ initials }}</span
    >
  </div>
</template>
