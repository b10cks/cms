<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'

import TableColumnOptions from '~/components/blocks/TableColumnOptions.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField, InputField, SelectField } from '~/components/ui/form'
import { ensureTableValue, getTableColumns } from '~/lib/tableField'

const props = defineProps<{
  value: TableSchema
  name: string
  readonly?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const list = ref<HTMLDivElement | null>(null)

if (!props.readonly) {
  ;(useSortable as any)(list, props.value.columns, {
    handle: '[draggable]',
    animation: 150,
    onEnd: () => commitColumns([...props.value.columns]),
  })
}

const syncDefault = (
  columns: TableColumn[],
  hasThead: boolean = props.value.has_thead
): TableValue => {
  return ensureTableValue(
    {
      columns,
      has_thead: hasThead,
    },
    props.value.default
  )
}

const commitColumns = (columns: TableColumn[]) => {
  emit('update:item-value', 'columns', columns)
  emit('update:item-value', 'default', syncDefault(columns))
}

const commitHasThead = (hasThead: boolean | 'indeterminate') => {
  const nextHasThead = hasThead === true
  emit('update:item-value', 'has_thead', nextHasThead)
  emit('update:item-value', 'default', syncDefault(getTableColumns(props.value), nextHasThead))
}

const slugifyColumnKey = (value: string) =>
  value
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_-]/g, '')

const getNextColumnKey = () => {
  const existingKeys = new Set(props.value.columns.map((column) => column.key))
  let index = props.value.columns.length + 1
  let key = `column_${index}`

  while (existingKeys.has(key)) {
    index += 1
    key = `column_${index}`
  }

  return key
}

const normalizeColumnForType = (
  column: TableColumn,
  nextType: TableColumn['type']
): TableColumn => {
  if (nextType === 'option') {
    const optionColumn = column as Partial<Extract<TableColumn, { type: 'option' }>>

    return {
      key: column.key,
      label: column.label,
      type: 'option',
      source: optionColumn.source === 'datasource' ? 'datasource' : 'self',
      options: [...(optionColumn.options || [])],
      data_source_id: optionColumn.data_source_id ?? null,
    }
  }

  return {
    key: column.key,
    label: column.label,
    type: nextType,
  } as TableColumn
}

const updateColumn = (index: number, patch: Partial<TableColumn>) => {
  const currentColumn = props.value.columns[index]

  if (!currentColumn) {
    return
  }

  const nextType = (patch.type || currentColumn.type) as TableColumn['type']
  const normalizedColumn = normalizeColumnForType(
    {
      ...currentColumn,
      ...patch,
    } as TableColumn,
    nextType
  )

  const nextColumns = props.value.columns.map((column, columnIndex) =>
    columnIndex === index ? normalizedColumn : column
  )

  commitColumns(nextColumns)
}

const addColumn = () => {
  commitColumns([
    ...props.value.columns,
    {
      key: getNextColumnKey(),
      label: '',
      type: 'text',
    },
  ])
}

const removeColumn = (index: number) => {
  commitColumns(props.value.columns.filter((_, columnIndex) => columnIndex !== index))
}
</script>

<template>
  <div class="grid gap-6">
    <CheckboxField
      name="has_thead"
      :model-value="value.has_thead"
      :label="$t('labels.blocks.fields.table.headerToggle')"
      :description="$t('labels.blocks.fields.table.headerToggleDescription')"
      :disabled="readonly"
      @update:model-value="commitHasThead"
    />

    <div class="grid gap-3 rounded-lg bg-background p-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h5 class="font-semibold text-primary">
            {{ $t('labels.blocks.fields.table.columnsTitle') }}
          </h5>
          <p class="text-sm text-muted-foreground">
            {{ $t('labels.blocks.fields.table.columnsDescription') }}
          </p>
        </div>

        <Button
          type="button"
          :disabled="readonly"
          @click="addColumn"
        >
          <Icon name="lucide:plus" />
          <span>{{ $t('actions.blocks.table.addColumn') }}</span>
        </Button>
      </div>

      <div
        ref="list"
        class="grid gap-1"
      >
        <div
          v-for="(column, index) in value.columns"
          :key="`${name}-column-${index}`"
          class="grid gap-4 rounded border border-border bg-surface p-2"
        >
          <div class="grid gap-3 md:grid-cols-[auto_1fr_1fr_110px_auto] md:items-start">
            <button
              type="button"
              draggable
              :disabled="readonly"
              :aria-label="$t('actions.dragToReorder')"
              class="h-full flex items-center cursor-ns-resize py-2 text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Icon
                name="lucide:grip-vertical"
                draggable
              />
            </button>

            <InputField
              :name="`${name}-column-key-${index}`"
              :model-value="column.key"
              :label="$t('labels.blocks.fields.table.columnKey')"
              :disabled="readonly"
              @update:model-value="updateColumn(index, { key: String($event || '') })"
              @blur="
                updateColumn(index, {
                  key: slugifyColumnKey(String(column.key || '')),
                })
              "
            />

            <InputField
              :name="`${name}-column-label-${index}`"
              :model-value="column.label"
              :label="$t('labels.blocks.fields.table.columnLabel')"
              :disabled="readonly"
              @update:model-value="updateColumn(index, { label: String($event || '') })"
            />

            <SelectField
              :name="`${name}-column-type-${index}`"
              :model-value="column.type"
              :label="$t('labels.blocks.fields.table.columnType')"
              :disabled="readonly"
              :options="[
                { value: 'text', label: $t('labels.blocks.fieldTypes.text.label') },
                { value: 'number', label: $t('labels.blocks.fieldTypes.number.label') },
                { value: 'option', label: $t('labels.blocks.fieldTypes.option.label') },
                { value: 'boolean', label: $t('labels.blocks.fieldTypes.boolean.label') },
              ]"
              @update:model-value="updateColumn(index, { type: $event as TableColumn['type'] })"
            />

            <button
              type="button"
              :disabled="readonly"
              :aria-label="$t('actions.blocks.table.deleteColumn')"
              class="mt-7 cursor-pointer p-2 text-muted-foreground hover:text-destructive disabled:cursor-not-allowed disabled:opacity-50"
              @click="removeColumn(index)"
            >
              <Icon name="lucide:trash-2" />
            </button>
          </div>

          <TableColumnOptions
            v-if="column.type === 'option'"
            :name="`${name}-column-options-${index}`"
            :column="column"
            :readonly="readonly"
            @update:column="updateColumn(index, $event)"
          />
        </div>
      </div>
    </div>
  </div>
</template>
