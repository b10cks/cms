<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { TableCell, TableRow } from '~/components/ui/table'

const { $t } = useI18n()

const props = withDefaults(
  defineProps<{
    colspan?: number
    icon?: string
    label?: string
  }>(),
  {
    colspan: 3,
    icon: 'lucide:loader',
    label: undefined,
  }
)

const labelText = computed(() => {
  return props.label || $t('labels.loading')
})
</script>

<template>
  <TableRow>
    <TableCell
      :colspan="colspan"
      class="bg-surface py-6 text-center"
    >
      <div class="flex flex-row items-center justify-center gap-2">
        <Icon
          :name="icon"
          class="animate-spin"
        />
        <span>{{ labelText }}</span>
      </div>
    </TableCell>
  </TableRow>
</template>
