<script setup lang="ts">
import type { BadgeVariants } from '~/components/ui/badge'
import { Badge } from '~/components/ui/badge'

const props = defineProps<{
  badge: string | null | undefined
  size?: BadgeVariants['size']
  class?: string
}>()

type PredefinedBadge = 'sandbox' | 'development' | 'staging' | 'production'

const PREDEFINED_BADGE_VARIANTS: Record<PredefinedBadge, BadgeVariants['variant']> = {
  sandbox: 'secondary',
  development: 'info',
  staging: 'warning',
  production: 'success',
}

const isPredefined = (value: string): value is PredefinedBadge => {
  return value in PREDEFINED_BADGE_VARIANTS
}

const variant = computed<BadgeVariants['variant']>(() => {
  if (!props.badge) return 'default'
  if (isPredefined(props.badge)) return PREDEFINED_BADGE_VARIANTS[props.badge]
  return 'default'
})

const label = computed(() => {
  if (!props.badge) return ''
  // Capitalize first letter for display
  return props.badge.charAt(0).toUpperCase() + props.badge.slice(1)
})
</script>

<template>
  <Badge
    v-if="badge"
    :variant="variant"
    :size="size ?? 'xs'"
    :class="props.class"
  >
    {{ label }}
  </Badge>
</template>
