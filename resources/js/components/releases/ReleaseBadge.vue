<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import type { Release } from '~/types/releases'

const { getReleaseState } = useReleases(inject<string>('spaceId') || '')

defineProps<{
  release: Release
}>()

// `outline` is a badge *type*, not a variant — the two compose.
const stateConfig = {
  draft: { label: 'Draft', variant: 'secondary', type: 'default' },
  scheduled: { label: 'Scheduled', variant: 'secondary', type: 'outline' },
  pending: { label: 'Pending', variant: 'secondary', type: 'outline' },
  published: { label: 'Published', variant: 'accent', type: 'default' },
} as const
</script>

<template>
  <Badge
    :variant="stateConfig[getReleaseState(release)].variant"
    :type="stateConfig[getReleaseState(release)].type"
  >
    {{ stateConfig[getReleaseState(release)].label }}
  </Badge>
</template>
