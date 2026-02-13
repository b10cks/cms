<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types'
import { TableCell, TableRow } from '~/components/ui/table'

const { $t } = useI18n()

const props = withDefaults(
  defineProps<{
    colspan?: number
    icon?: string
    label?: string | CleanTranslation
  }>(),
  {
    colspan: 3,
    icon: '',
    label: undefined,
  }
)

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
          :is="icon"
          class="w-32 text-muted"
        />
        <div class="font-semibold text-muted">{{ labelText }}</div>
        <slot name="actions" />
      </div>
    </TableCell>
  </TableRow>
</template>
