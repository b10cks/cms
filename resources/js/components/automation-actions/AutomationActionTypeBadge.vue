<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import { getActionTypeLabel } from '~/utils/automations'

const props = defineProps<{
  type: AutomationActionType
}>()

const { t } = useI18n()

const variant = computed(() => {
  switch (props.type) {
    case 'webhook':
      return 'info'
    case 'email':
      return 'success'
    case 'void':
      return 'secondary'
  }
})
</script>

<template>
  <Badge
    :variant="variant"
    size="sm"
  >
    {{ getActionTypeLabel(t, type) }}
  </Badge>
</template>
