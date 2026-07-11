<script setup lang="ts">
import DiffSegments from '~/components/content/diff/DiffSegments.vue'
import { diffTextSegments, toDisplayText, type DiffSegment } from '~/utils/text-diff'

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
  cells: DiffSegment[][]
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

const headerCells = computed((): DiffSegment[][] =>
  columns.value.map((column) =>
    diffTextSegments(
      toDisplayText(oldTable.value.header[column]),
      toDisplayText(newTable.value.header[column])
    )
  )
)

// Prefixes keep real ids and positional fallbacks from colliding.
const rowKey = (row: TableRowLike, index: number): string =>
  typeof row.id === 'string' && row.id !== '' ? `id:${row.id}` : `#${index}`

const rows = computed((): DiffRow[] => {
  const cellSegments = (
    oldCells: Record<string, unknown>,
    newCells: Record<string, unknown>,
    status: RowStatus
  ): DiffSegment[][] =>
    columns.value.map((column): DiffSegment[] => {
      const oldText = toDisplayText(oldCells[column])
      const newText = toDisplayText(newCells[column])
      if (status === 'added') return [{ type: 'equal', text: newText }]
      if (status === 'removed') return [{ type: 'equal', text: oldText }]
      return oldText === newText ? [{ type: 'equal', text: newText }] : diffTextSegments(oldText, newText)
    })

  const oldById = new Map(oldTable.value.rows.map((row, index) => [rowKey(row, index), row]))
  const newKeys = new Set(newTable.value.rows.map((row, index) => rowKey(row, index)))

  const result: DiffRow[] = newTable.value.rows.map((row, index) => {
    const key = rowKey(row, index)
    const oldRow = oldById.get(key)
    const status: RowStatus = oldRow ? 'unchanged' : 'added'
    return { key, status, cells: cellSegments(oldRow?.cells ?? {}, row.cells ?? {}, status) }
  })

  for (const [index, row] of oldTable.value.rows.entries()) {
    const key = rowKey(row, index)
    if (!newKeys.has(key)) {
      result.push({ key: `removed-${key}`, status: 'removed', cells: cellSegments(row.cells ?? {}, {}, 'removed') })
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
            v-for="(column, columnIndex) in columns"
            :key="column"
            class="text-muted-foreground px-2 py-1 text-left text-xs font-semibold"
          >
            <DiffSegments :segments="headerCells[columnIndex]" />
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
            v-for="(column, columnIndex) in columns"
            :key="column"
            class="px-2 py-1 align-top"
          >
            <DiffSegments :segments="row.cells[columnIndex]" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
