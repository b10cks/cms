<script setup lang="ts">
import { Badge } from '~/components/ui/badge'

defineProps<{
  inherited?: boolean
}>()
</script>

<template>
  <Badge
    variant="secondary"
    size="xs"
    class="border-destructive/30 bg-destructive-background/20 text-destructive"
  >
    {{ inherited ? $t('labels.contents.wizard.deletedInherited') : $t('labels.contents.wizard.deleted') }}
  </Badge>
</template>
