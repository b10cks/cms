<script setup lang="ts">
import NuxtImg from '~/components/NuxtImg.vue'

import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
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
      return 16
    case 'lg':
      return 48
    default:
      return 32
  }
})
</script>

<template>
  <div
    :class="cn(avatarVariants({ size }), 'border-2', props.class)"
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
      class="text-xs font-bold"
      >{{ initials }}</span
    >
  </div>
</template>
