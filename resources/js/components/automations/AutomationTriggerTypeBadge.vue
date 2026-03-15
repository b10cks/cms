<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import { getTriggerTypeLabel } from '~/utils/automations'

const props = defineProps<{
  type: AutomationTriggerType
}>()

const { t } = useI18n()

const variant = computed(() => {
  switch (props.type) {
    case 'on_insert':
      return 'success'
    case 'on_update':
      return 'info'
    case 'on_delete':
      return 'destructive'
    case 'time_based':
      return 'warning'
    case 'manual':
      return 'accent'
  }
})
</script>

<template>
  <Badge
    :variant="variant"
    size="sm"
  >
    {{ getTriggerTypeLabel(t, type) }}
  </Badge>
</template>
