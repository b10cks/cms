<script setup lang="ts">
import type { Component } from 'vue'

import { TableCell, TableRow } from '~/components/ui/table'

const { $t } = useI18n()

const props = withDefaults(
  defineProps<{
    colspan?: number
    // A component, never an icon name: `<Component :is>` would resolve a string
    // as a tag name and render an unknown element.
    icon?: Component
    label?: string
  }>(),
  {
    colspan: 3,
    icon: undefined,
    label: undefined,
  }
)

const iconComponent = computed(() => (typeof props.icon === 'string' ? undefined : props.icon))

const labelText = computed(() => {
  return props.label || $t('labels.noResults')
})
</script>

<template>
  <TableRow>
    <TableCell
      :colspan="colspan"
      class="bg-surface py-12 text-center select-none"
    >
      <div class="flex flex-col items-center justify-center gap-6">
        <Component
          :is="iconComponent"
          v-if="iconComponent"
          class="w-32 text-muted"
        />
        <div class="font-semibold text-muted">{{ labelText }}</div>
        <slot name="actions" />
      </div>
    </TableCell>
  </TableRow>
</template>
