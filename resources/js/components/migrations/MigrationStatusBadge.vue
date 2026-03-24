<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'

interface Props {
  state: MigrationState
}

const props = defineProps<Props>()

const variant = computed<'default' | 'secondary' | 'destructive'>(() => {
  switch (props.state) {
    case 'completed':
      return 'default'
    case 'pending':
    case 'processing':
      return 'secondary'
    case 'failed':
      return 'destructive'
    default:
      return 'secondary'
  }
})

const isLoading = computed(() => props.state === 'pending' || props.state === 'processing')

const icon = computed(() => {
  switch (props.state) {
    case 'completed':
      return 'lucide:check-circle'
    case 'processing':
      return 'lucide:loader'
    case 'pending':
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
    <span class="capitalize">{{ $t(`labels.migrations.states.${state}`) }}</span>
  </Badge>
</template>
