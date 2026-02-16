<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import type { Release } from '~/types/releases'

const { getReleaseState } = useReleases(inject<string>('spaceId') || '')

defineProps<{
  release: Release
}>()

const stateConfig = {
  draft: { label: 'Draft', variant: 'secondary' },
  scheduled: { label: 'Scheduled', variant: 'outline' },
  pending: { label: 'Pending', variant: 'outline' },
  published: { label: 'Published', variant: 'accent' },
} as const
</script>

<template>
  <Badge :variant="stateConfig[getReleaseState(release)].variant">
    {{ stateConfig[getReleaseState(release)].label }}
  </Badge>
</template>
