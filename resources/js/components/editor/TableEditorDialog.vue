<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'

import TableOptionCell from '~/components/editor/TableOptionCell.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'
import { ScrollArea } from '~/components/ui/scroll-area'
import { createTableRow, ensureTableValue, getTableColumns } from '~/lib/tableField'

const props = defineProps<{
  open: boolean
  item: TableSchema & { key: string }
  modelValue: TableValue
  spaceId: string
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:modelValue': [value: TableValue]
}>()

const ulid = useUlid()
const rowList = ref<HTMLElement | null>(null)

const columns = computed(() => getTableColumns(props.item))
const canEdit = computed(() => !props.readOnly)
const canAddRow = computed(
  () => props.item.max === null || localTable.value.rows.length < Number(props.item.max)
)

const cloneTableValue = (value: TableValue): TableValue => ({
  header: { ...value.header },
  rows: value.rows.map((row) => ({
    id: row.id,
    cells: { ...row.cells },
  })),
})

const localTable = ref<TableValue>({
  header: {},
  rows: [],
})

const syncLocalTable = () => {
  localTable.value = cloneTableValue(ensureTableValue(props.item, props.modelValue))
}

const commitTable = () => {
  emit('update:modelValue', cloneTableValue(localTable.value))
}

const sortableRows = computed({
  get: () => localTable.value.rows,
  set: (newRows) => {
    localTable.value = {
      ...localTable.value,
      rows: newRows.map((row) => ({
        id: row.id,
        cells: { ...row.cells },
      })),
    }
    commitTable()
  },
})

watch(
  () => [props.open, props.modelValue, props.item.columns, props.item.has_thead],
  ([isOpen]) => {
    if (!isOpen) return
    syncLocalTable()
  },
  { immediate: true, deep: true }
)

;(useSortable as any)(rowList, sortableRows, {
  handle: '[data-row-drag-handle]',
})

const updateHeader = (columnKey: string, value: string) => {
  localTable.value = {
    ...localTable.value,
    header: {
      ...localTable.value.header,
      [columnKey]: value,
    },
  }
  commitTable()
}

const updateCell = (
  rowIndex: number,
  columnKey: string,
  value: string | number | boolean | null
) => {
  const nextRows = [...localTable.value.rows]
  const row = nextRows[rowIndex]

  if (!row) return

  nextRows[rowIndex] = {
    ...row,
    cells: {
      ...row.cells,
      [columnKey]: value,
    },
  }

  localTable.value = {
    ...localTable.value,
    rows: nextRows,
  }
  commitTable()
}

const addRow = () => {
  if (!canAddRow.value) return

  localTable.value = {
    ...localTable.value,
    rows: [...localTable.value.rows, createTableRow(columns.value, ulid())],
  }
  commitTable()
}

const removeRow = (rowId: string) => {
  localTable.value = {
    ...localTable.value,
    rows: localTable.value.rows.filter((row) => row.id !== rowId),
  }
  commitTable()
}

const focusCell = (rowIndex: number, columnIndex: number) => {
  nextTick(() => {
    const element = document.querySelector(
      `[data-table-cell-row="${rowIndex}"][data-table-cell-col="${columnIndex}"]`
    ) as HTMLElement | null
    element?.focus()
  })
}

const shouldMoveHorizontal = (event: KeyboardEvent) => {
  const target = event.target as HTMLInputElement | null

  if (
    !target ||
    typeof target.selectionStart !== 'number' ||
    typeof target.selectionEnd !== 'number'
  ) {
    return true
  }

  if (target.selectionStart !== target.selectionEnd) {
    return false
  }

  if (event.key === 'ArrowLeft') {
    return target.selectionStart === 0
  }

  if (event.key === 'ArrowRight') {
    return target.selectionEnd === target.value.length
  }

  return true
}

const handleCellKeyDown = (event: KeyboardEvent, rowIndex: number, columnIndex: number) => {
  const lastRowIndex = localTable.value.rows.length - 1
  const lastColumnIndex = columns.value.length - 1

  switch (event.key) {
    case 'ArrowUp':
      if (rowIndex > 0) {
        event.preventDefault()
        focusCell(rowIndex - 1, columnIndex)
      }
      break
    case 'ArrowDown':
      if (rowIndex < lastRowIndex) {
        event.preventDefault()
        focusCell(rowIndex + 1, columnIndex)
      } else if (canEdit.value && canAddRow.value) {
        event.preventDefault()
        addRow()
        focusCell(localTable.value.rows.length - 1, columnIndex)
      }
      break
    case 'ArrowLeft':
      if (columnIndex > 0 && shouldMoveHorizontal(event)) {
        event.preventDefault()
        focusCell(rowIndex, columnIndex - 1)
      }
      break
    case 'ArrowRight':
      if (columnIndex < lastColumnIndex && shouldMoveHorizontal(event)) {
        event.preventDefault()
        focusCell(rowIndex, columnIndex + 1)
      }
      break
    case 'Enter':
      if (canEdit.value && rowIndex === lastRowIndex && canAddRow.value) {
        event.preventDefault()
        addRow()
        focusCell(localTable.value.rows.length - 1, columnIndex)
      }
      break
    case 'Tab':
      if (
        !event.shiftKey &&
        canEdit.value &&
        rowIndex === lastRowIndex &&
        columnIndex === lastColumnIndex &&
        canAddRow.value
      ) {
        event.preventDefault()
        addRow()
        focusCell(localTable.value.rows.length - 1, 0)
      }
      break
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="emit('update:open', $event)"
  >
    <DialogContent
      class="h-[90dvh] !max-w-[90dvw] p-0"
      :scroll-body="false"
    >
      <div class="flex h-full min-h-0 flex-col">
        <DialogHeader class="border-b border-border px-6 py-4">
          <div class="flex items-start justify-between gap-4">
            <div>
              <DialogTitle>{{ item.name || item.key }}</DialogTitle>
              <p class="mt-1 text-sm text-muted-foreground">
                {{ item.description || $t('components.tableBlock.dialogDescription') }}
              </p>
            </div>

            <Button
              v-if="canEdit"
              type="button"
              :disabled="!canAddRow"
              @click="addRow"
            >
              <Icon name="lucide:plus" />
              <span>{{ $t('actions.blocks.table.addRow') }}</span>
            </Button>
          </div>
        </DialogHeader>

        <ScrollArea class="min-h-0 flex-1">
          <div class="min-w-max p-6">
            <div class="min-w-[720px] overflow-hidden rounded-lg border border-border">
              <table class="w-full border-separate border-spacing-0">
                <thead v-if="item.has_thead">
                  <tr>
                    <th
                      class="w-20 border-b border-r border-border bg-surface px-3 py-2 text-left text-xs font-semibold uppercase text-muted-foreground"
                    >
                      #
                    </th>
                    <th
                      v-for="column in columns"
                      :key="`header-${column.key}`"
                      class="min-w-[180px] border-b border-r border-border bg-surface px-3 py-2 text-left last:border-r-0"
                    >
                      <Input
                        :model-value="localTable.header[column.key]"
                        :disabled="readOnly"
                        :placeholder="column.label || column.key"
                        @update:model-value="updateHeader(column.key, String($event || ''))"
                      />
                    </th>
                    <th class="w-16 border-b border-border bg-surface px-3 py-2" />
                  </tr>
                </thead>
              </table>

              <div
                v-if="localTable.rows.length === 0"
                class="grid min-w-[720px] grid-cols-[80px_repeat(var(--table-columns),minmax(180px,1fr))_64px]"
                :style="{ '--table-columns': String(columns.length) }"
              >
                <div class="border-r border-border bg-surface/40 px-3 py-3" />
                <div
                  class="border-r border-border px-3 py-6 text-center text-sm text-muted-foreground"
                  :style="{ gridColumn: `span ${Math.max(columns.length, 1)}` }"
                >
                  {{ $t('components.tableBlock.dialogDescription') }}
                </div>
                <div class="bg-surface/20 px-3 py-3" />
              </div>

              <div
                ref="rowList"
                class="grid"
              >
                <div
                  v-for="(row, rowIndex) in sortableRows"
                  :key="row.id"
                  class="grid min-w-[720px] grid-cols-[80px_repeat(var(--table-columns),minmax(180px,1fr))_64px]"
                  :style="{ '--table-columns': String(columns.length) }"
                >
                  <div class="border-r border-t border-border bg-surface/40 px-3 py-3">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                      <button
                        v-if="canEdit"
                        type="button"
                        draggable
                        data-row-drag-handle
                        :aria-label="$t('actions.dragToReorder')"
                        class="cursor-ns-resize"
                      >
                        <Icon name="lucide:grip-vertical" />
                      </button>
                      <span>{{ rowIndex + 1 }}</span>
                    </div>
                  </div>

                  <div
                    v-for="column in columns"
                    :key="`${row.id}-${column.key}`"
                    class="min-w-[180px] border-r border-t border-border px-3 py-3 last:border-r-0"
                  >
                    <Input
                      v-if="column.type === 'text'"
                      :model-value="String(row.cells[column.key] ?? '')"
                      :disabled="readOnly"
                      :data-table-cell-row="rowIndex"
                      :data-table-cell-col="columns.findIndex((entry) => entry.key === column.key)"
                      @update:model-value="updateCell(rowIndex, column.key, String($event || ''))"
                      @keydown="
                        handleCellKeyDown(
                          $event,
                          rowIndex,
                          columns.findIndex((entry) => entry.key === column.key)
                        )
                      "
                    />

                    <Input
                      v-else-if="column.type === 'number'"
                      :model-value="
                        row.cells[column.key] === null ? '' : String(row.cells[column.key] ?? '')
                      "
                      type="number"
                      :disabled="readOnly"
                      :data-table-cell-row="rowIndex"
                      :data-table-cell-col="columns.findIndex((entry) => entry.key === column.key)"
                      @update:model-value="
                        updateCell(
                          rowIndex,
                          column.key,
                          $event === '' || $event === null || $event === undefined
                            ? null
                            : Number($event)
                        )
                      "
                      @keydown="
                        handleCellKeyDown(
                          $event,
                          rowIndex,
                          columns.findIndex((entry) => entry.key === column.key)
                        )
                      "
                    />

                    <TableOptionCell
                      v-else-if="column.type === 'option'"
                      :name="`${item.key}-${row.id}-${column.key}`"
                      :column="column"
                      :space-id="spaceId"
                      :model-value="(row.cells[column.key] as string | null) ?? null"
                      :read-only="readOnly"
                      :data-table-cell-row="rowIndex"
                      :data-table-cell-col="columns.findIndex((entry) => entry.key === column.key)"
                      @update:model-value="updateCell(rowIndex, column.key, $event)"
                      @keydown="
                        handleCellKeyDown(
                          $event,
                          rowIndex,
                          columns.findIndex((entry) => entry.key === column.key)
                        )
                      "
                    />

                    <div
                      v-else
                      class="flex h-10 items-center justify-center"
                    >
                      <Checkbox
                        :model-value="Boolean(row.cells[column.key])"
                        :disabled="readOnly"
                        :data-table-cell-row="rowIndex"
                        :data-table-cell-col="
                          columns.findIndex((entry) => entry.key === column.key)
                        "
                        @update:model-value="updateCell(rowIndex, column.key, Boolean($event))"
                        @keydown="
                          handleCellKeyDown(
                            $event,
                            rowIndex,
                            columns.findIndex((entry) => entry.key === column.key)
                          )
                        "
                      />
                    </div>
                  </div>

                  <div class="border-t border-border px-3 py-3">
                    <button
                      v-if="canEdit"
                      type="button"
                      tabindex="-1"
                      :aria-label="$t('actions.remove')"
                      class="cursor-pointer text-muted-foreground hover:text-destructive"
                      @click="removeRow(row.id)"
                    >
                      <Icon name="lucide:trash-2" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </ScrollArea>
      </div>
    </DialogContent>
  </Dialog>
</template>
