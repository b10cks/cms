<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Badge } from '~/components/ui/badge'

interface Props {
  state: BackupState
}

const props = defineProps<Props>()

const variant = computed<'default' | 'secondary' | 'destructive'>(() => {
  switch (props.state) {
    case 'active':
      return 'default'
    case 'pending':
      return 'secondary'
    case 'expired':
      return 'secondary'
    case 'failed':
      return 'destructive'
    default:
      return 'secondary'
  }
})

const isLoading = computed(() => props.state === 'pending')

const icon = computed(() => {
  switch (props.state) {
    case 'active':
      return 'lucide:check-circle'
    case 'pending':
      return 'lucide:loader'
    case 'expired':
      return 'lucide:clock'
    case 'failed':
      return 'lucide:x-circle'
    default:
      return 'lucide:help-circle'
  }
})
</script>

<template>
  <Badge
    :variant="variant"
    class="flex items-center gap-1"
  >
    <Icon
      :name="icon"
      :class="{ 'animate-spin': isLoading }"
    />
    <span class="capitalize">{{ state }}</span>
  </Badge>
</template>
