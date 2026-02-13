<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '~/components/ui/tooltip/index'
import type { User } from '~/types/users'
import type { AvatarVariants } from './index'
import { Avatar, avatarVariants } from './index'

interface AvatarListUser extends User {
  joined_at?: string
}

const props = withDefaults(
  defineProps<{
    users: AvatarListUser[]
    max?: number
    size?: AvatarVariants['size']
    class?: HTMLAttributes['class']
    showTooltip?: boolean
    tooltipSide?: 'top' | 'right' | 'bottom' | 'left'
  }>(),
  {
    max: 3,
    size: 'default',
    showTooltip: true,
    tooltipSide: 'top',
  }
)

const displayUsers = computed(() => {
  return props.users.slice(0, props.max)
})

const remainingCount = computed(() => {
  return Math.max(0, props.users.length - props.max)
})

const hasRemaining = computed(() => {
  return remainingCount.value > 0
})

const tooltipText = computed(() => {
  if (!props.showTooltip) return ''
  return props.users.map((user) => `${user.firstname} ${user.lastname}`).join(', ')
})

const overlapClass = computed(() => {
  switch (props.size) {
    case 'sm':
      return '-space-x-1'
    case 'lg':
      return '-space-x-3'
    default:
      return '-space-x-2'
  }
})

const placeholderSizeClass = computed(() => {
  switch (props.size) {
    case 'sm':
      return 'size-4 text-[8px]'
    case 'lg':
      return 'size-12 text-sm'
    default:
      return 'size-8 text-xs'
  }
})
</script>

<template>
  <TooltipProvider :delay-duration="200">
    <Tooltip>
      <TooltipTrigger
        type="button"
        :class="cn('flex items-center', overlapClass, props.class)"
      >
        <TransitionGroup
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="transform scale-10 opacity-0"
          enter-to-class="transform scale-100 opacity-100"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="transform scale-100 opacity-100"
          leave-to-class="transform scale-10 opacity-0"
        >
          <Avatar
            v-for="user in displayUsers"
            :key="user.id"
            :name="`${user.firstname} ${user.lastname}`"
            :avatar="user.avatar"
            :size="size"
            class="ring-2 ring-background"
          />
          <div
            v-if="hasRemaining"
            :class="
              cn(
                avatarVariants({ size }),
                placeholderSizeClass,
                'text-muted-foreground flex items-center justify-center bg-muted font-medium ring-2 ring-background'
              )
            "
          >
            +{{ remainingCount }}
          </div>
        </TransitionGroup>
      </TooltipTrigger>
      <TooltipContent
        v-if="showTooltip"
        :side="tooltipSide"
      >
        <p>{{ tooltipText }}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
