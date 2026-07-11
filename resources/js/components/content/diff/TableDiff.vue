<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffTextSegments } from '~/utils/text-diff'

type RowStatus = 'added' | 'removed' | 'unchanged'

interface TableRowLike {
  id?: unknown
  cells?: Record<string, unknown>
}

interface TableLike {
  header?: Record<string, unknown>
  rows?: TableRowLike[]
}

interface DiffRow {
  key: string
  status: RowStatus
  oldCells: Record<string, unknown>
  newCells: Record<string, unknown>
}

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const toTable = (value: unknown): Required<TableLike> => {
  const table = typeof value === 'object' && value !== null ? (value as TableLike) : {}
  return {
    header: typeof table.header === 'object' && table.header !== null ? table.header : {},
    rows: Array.isArray(table.rows) ? table.rows.filter((row) => typeof row === 'object' && row !== null) : [],
  }
}

const oldTable = computed(() => toTable(props.oldValue))
const newTable = computed(() => toTable(props.newValue))

const columns = computed((): string[] => [
  ...new Set([...Object.keys(newTable.value.header), ...Object.keys(oldTable.value.header)]),
])

const cellText = (value: unknown): string => (value == null ? '' : String(value))

const rowId = (row: TableRowLike, index: number): string =>
  typeof row.id === 'string' && row.id !== '' ? row.id : `#${index}`

const rows = computed((): DiffRow[] => {
  const oldById = new Map(oldTable.value.rows.map((row, index) => [rowId(row, index), row]))
  const newIds = new Set(newTable.value.rows.map((row, index) => rowId(row, index)))

  const result: DiffRow[] = newTable.value.rows.map((row, index) => {
    const key = rowId(row, index)
    const oldRow = oldById.get(key)
    return {
      key,
      status: (oldRow ? 'unchanged' : 'added') as RowStatus,
      oldCells: oldRow?.cells ?? {},
      newCells: row.cells ?? {},
    }
  })

  for (const [index, row] of oldTable.value.rows.entries()) {
    const key = rowId(row, index)
    if (!newIds.has(key)) {
      result.push({ key, status: 'removed', oldCells: row.cells ?? {}, newCells: {} })
    }
  }

  return result
})

const rowClasses = (status: RowStatus): string => {
  switch (status) {
    case 'added':
      return 'bg-success/10'
    case 'removed':
      return 'bg-destructive/10 text-destructive line-through'
    default:
      return ''
  }
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
      <thead>
        <tr class="border-b border-border">
          <th
            v-for="column in columns"
            :key="column"
            class="text-muted-foreground px-2 py-1 text-left text-xs font-semibold"
          >
            <DiffSegments
              :segments="
                diffTextSegments(cellText(oldTable.header[column]), cellText(newTable.header[column]))
              "
            />
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.key"
          class="border-b border-border/50"
          :class="rowClasses(row.status)"
        >
          <td
            v-for="column in columns"
            :key="column"
            class="px-2 py-1 align-top"
          >
            <DiffSegments
              v-if="row.status === 'unchanged'"
              :segments="diffTextSegments(cellText(row.oldCells[column]), cellText(row.newCells[column]))"
            />
            <span v-else>{{
              cellText(row.status === 'added' ? row.newCells[column] : row.oldCells[column])
            }}</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
