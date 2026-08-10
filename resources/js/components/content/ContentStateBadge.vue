<script setup lang="ts">
import type { BadgeVariants } from '~/components/ui/badge'
import { Badge } from '~/components/ui/badge'

const props = withDefaults(
  defineProps<{
    status: 'draft' | 'published' | 'changed' | 'missing'
    size?: BadgeVariants['size']
  }>(),
  {
    size: 'sm',
  }
)

const badge = computed<{
  variant: BadgeVariants['variant']
  type?: BadgeVariants['type']
  label: string
}>(() => {
  if (props.status === 'published') {
    return {
      variant: 'success',
      label: 'labels.contents.status.published',
    }
  }

  // Live, but the draft has moved on: the outline keeps the published green
  // while reading as unfinished next to a solid one.
  if (props.status === 'changed') {
    return {
      variant: 'success',
      type: 'outline',
      label: 'labels.contents.status.changed',
    }
  }

  if (props.status === 'missing') {
    return {
      variant: 'warning',
      label: 'labels.contents.localization.status.missing',
    }
  }

  return {
    variant: 'default',
    label: 'labels.contents.status.draft',
  }
})

const isDotOnly = computed(() => props.size === 'indicator')
</script>

<template>
  <Badge
    :variant="badge.variant"
    :type="badge.type"
    :size="size"
  >
    <span
      v-if="isDotOnly"
      class="sr-only"
    >
      {{ $t(badge.label) }}
    </span>
    <template v-else>
      {{ $t(badge.label) }}
    </template>
  </Badge>
</template>
