<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { errorMessage } from '~/lib/toast-error'

import type { GlossaryConceptList } from './useGlossaryConceptList'

/** Deletes the selected rows, naming how many it reaches. The toast is the announcement. */
const props = defineProps<{
  list: GlossaryConceptList
  selected: ReadonlySet<string>
}>()

const emit = defineEmits<{ clear: [] }>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { mutateAsync } = props.list.rowsApi.useBulkMutation()

const remove = async () => {
  const count = props.selected.size
  const label = t('glossaryImport.editor.bulk.deleteCount', { count }, count)
  const confirmed = await alert.confirm(t('glossaryImport.editor.bulk.confirmDelete', { count }, count), {
    title: label,
    variant: 'destructive',
    confirmLabel: label,
  })
  if (!confirmed) return

  try {
    await mutateAsync({ action: 'delete', row_ids: [...props.selected] })
    toast.success(t('glossaryImport.editor.bulk.deleted', { count }, count))
    emit('clear')
  } catch (error) {
    toast.error(t('glossaryImport.errors.generic', { error: errorMessage(error) }))
  }
}
</script>

<template>
  <div
    v-if="selected.size > 0"
    role="toolbar"
    :aria-label="$t('glossaryImport.editor.bulk.toolbar')"
    class="fixed bottom-6 left-1/2 z-40 flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-1 rounded-2xl border border-border bg-popover px-2 py-1.5 text-popover-foreground shadow-soft-lg"
  >
    <span class="px-2 text-sm font-semibold tabular-nums">
      {{ $t('glossaryImport.editor.bulk.selected', { count: selected.size }, selected.size) }}
    </span>
    <Button
      variant="ghost"
      size="sm"
      @click="remove"
    >
      <Icon
        name="lucide:trash-2"
        class="text-destructive"
      />
      {{ $t('actions.delete') }}
    </Button>
    <Button
      variant="ghost"
      size="sm"
      :aria-label="$t('actions.clearSelection')"
      @click="emit('clear')"
    >
      <Icon name="lucide:x" />
    </Button>
  </div>
</template>
