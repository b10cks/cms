<script setup lang="ts">
import TableEditorDialog from '~/components/editor/TableEditorDialog.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import Label from '~/components/ui/form/Label.vue'
import { ensureTableValue, getTableColumns, getTableHeaderPreview } from '~/lib/tableField'

const modelValue = defineModel<TableValue | null>()

const props = defineProps<{
  item: TableSchema & { key: string }
  spaceId: string
  readOnly?: boolean
}>()

const showDialog = ref(false)

const normalizedValue = computed(() => ensureTableValue(props.item, modelValue.value))
const columns = computed(() => getTableColumns(props.item))
const headerPreview = computed(() => getTableHeaderPreview(props.item, normalizedValue.value))
const updateModelValue = (value: TableValue) => {
  modelValue.value = value
}

watch(
  [() => props.item.columns, () => props.item.has_thead, modelValue],
  () => {
    const nextValue = ensureTableValue(props.item, modelValue.value)
    if (JSON.stringify(modelValue.value || null) === JSON.stringify(nextValue)) {
      return
    }

    modelValue.value = nextValue
  },
  { immediate: true, deep: true }
)
</script>

<template>
  <div class="grid gap-2">
    <Label
      :label="item.name || item.key"
      :required="item.required"
    />

    <div class="rounded border border-border bg-surface/40 p-2">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-3">
          <p
            v-if="item.description"
            class="text-sm text-muted-foreground"
          >
            {{ item.description }}
          </p>

          <div class="flex flex-wrap gap-2">
            <Badge
              variant="surface"
              size="sm"
            >
              {{ $t('components.tableBlock.columnsBadge', { count: columns.length }) }}
            </Badge>
            <Badge
              variant="surface"
              size="sm"
            >
              {{ $t('components.tableBlock.rowsBadge', { count: normalizedValue.rows.length }) }}
            </Badge>
          </div>
        </div>

        <Button
          v-if="!readOnly"
          type="button"
          @click="showDialog = true"
        >
          {{ $t('actions.blocks.table.editTable') }}
        </Button>
      </div>

      <p class="text-xs font-semibold uppercase tracking-wide text-muted">
        {{ $t('components.tableBlock.previewTitle') }}
      </p>
      <div class="mt-2 flex flex-wrap gap-2">
        <span
          v-for="(label, index) in headerPreview"
          :key="`${item.key}-header-${index}`"
          class="rounded-sm bg-elevated px-2 py-1 text-xs text-primary"
        >
          {{ label }}
        </span>
      </div>
    </div>

    <TableEditorDialog
      :open="showDialog"
      :item="item"
      :model-value="normalizedValue"
      :space-id="spaceId"
      :read-only="readOnly"
      @update:open="showDialog = $event"
      @update:model-value="updateModelValue"
    />
  </div>
</template>
