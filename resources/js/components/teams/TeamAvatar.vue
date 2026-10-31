<script setup lang="ts">
import NuxtImg from '~/components/NuxtImg.vue'
import { cn } from '~/lib/utils'

/** A team's square mark: its avatar image, else its initial on a tint of the team color. */
const props = withDefaults(
  defineProps<{
    name: string
    avatar?: string | null
    color?: string | null
    size?: 'sm' | 'md' | 'lg'
  }>(),
  {
    avatar: null,
    color: null,
    size: 'md',
  }
)

const sizes = {
  sm: { box: 'size-5 rounded text-[0.625rem]', px: 20 },
  md: { box: 'size-6 rounded-md text-[0.6875rem]', px: 24 },
  lg: { box: 'size-10 rounded-lg text-base', px: 40 },
}

const initial = computed(() => props.name.trim().charAt(0).toUpperCase())
</script>

<template>
  <span
    :class="
      cn(
        'flex shrink-0 items-center justify-center overflow-hidden bg-secondary font-semibold text-primary select-none',
        sizes[size].box
      )
    "
    :style="!avatar && color ? { backgroundColor: `${color}33`, color } : undefined"
    aria-hidden="true"
  >
    <NuxtImg
      v-if="avatar"
      :src="avatar"
      :alt="name"
      :width="sizes[size].px"
      :height="sizes[size].px"
      class="size-full object-cover"
    />
    <template v-else>{{ initial }}</template>
  </span>
</template>
