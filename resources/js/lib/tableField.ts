type TableFieldSchema = Pick<TableSchema, 'columns' | 'has_thead'>

type BlockSchemaResolver = (
  blockSlug: string
) => { schema: Record<string, SchemaType>; name?: string } | undefined

const TABLE_COLUMN_TYPES = ['text', 'number', 'option', 'boolean'] as const

const isObjectRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

export const cloneStructuredValue = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

// The single canonical schema-type normalizer. `useContentSchemaState` re-exports
// it as `normalizeSchemaType`; a new field type must only be added here.
export const normalizeSchemaTypeName = (type?: string | null): CanonicalSchemaTypeName | '' => {
  switch (type) {
    case 'multiAsset':
      return 'multi_assets'
    case 'reference':
      return 'references'
    case 'block':
      return 'blocks'
    case 'blocks':
    case 'text':
    case 'textarea':
    case 'markdown':
    case 'richtext':
    case 'number':
    case 'boolean':
    case 'option':
    case 'options':
    case 'link':
    case 'asset':
    case 'multi_assets':
    case 'icon':
    case 'geo':
    case 'price':
    case 'references':
    case 'date':
    case 'meta':
    case 'table':
    case 'plugin':
    case 'serial':
      return type
    default:
      return ''
  }
}

const normalizeTableColumn = (column: unknown): TableColumn | null => {
  if (!isObjectRecord(column)) {
    return null
  }

  const type = String(column.type || 'text')
  const key = String(column.key || '')
  const label = String(column.label || '')

  if (!TABLE_COLUMN_TYPES.includes(type as (typeof TABLE_COLUMN_TYPES)[number])) {
    return null
  }

  if (type === 'option') {
    return {
      key,
      label,
      type: 'option',
      source: column.source === 'datasource' ? 'datasource' : 'self',
      options: Array.isArray(column.options)
        ? column.options
            .map((option) =>
              isObjectRecord(option)
                ? {
                    name: String(option.name || ''),
                    value: String(option.value || ''),
                  }
                : null
            )
            .filter((option): option is OptionItem => Boolean(option))
        : [],
      data_source_id: column.data_source_id ? String(column.data_source_id) : null,
    }
  }

  return {
    key,
    label,
    type,
  } as TableColumn
}

export const getTableColumns = (field?: Partial<TableFieldSchema> | null): TableColumn[] => {
  return Array.isArray(field?.columns)
    ? field.columns.map((column) => normalizeTableColumn(column)).filter((column): column is TableColumn => Boolean(column))
    : []
}

export const getTableDefaultCellValue = (column: TableColumn): string | number | boolean | null => {
  switch (column.type) {
    case 'text':
      return ''
    case 'number':
      return null
    case 'option':
      return null
    case 'boolean':
      return false
  }
}

const normalizeTableCellValue = (
  column: TableColumn,
  value: unknown
): string | number | boolean | null => {
  switch (column.type) {
    case 'text':
      return typeof value === 'string' ? value : ''
    case 'number':
      return typeof value === 'number' && Number.isFinite(value) ? value : null
    case 'option':
      return value === null || typeof value === 'string' ? value : null
    case 'boolean':
      return typeof value === 'boolean' ? value : false
  }
}

export const createTableRow = (columns: TableColumn[], id: string): TableRow => ({
  id,
  cells: Object.fromEntries(columns.map((column) => [column.key, getTableDefaultCellValue(column)])),
})

export const ensureTableValue = (
  field: TableFieldSchema,
  value: unknown,
  options: { seedHeader?: boolean } = {}
): TableValue => {
  const columns = getTableColumns(field)
  const rawValue = isObjectRecord(value) ? value : {}
  const rawHeader = isObjectRecord(rawValue.header) ? rawValue.header : {}
  const rawRows = Array.isArray(rawValue.rows) ? rawValue.rows : []

  const header = Object.fromEntries(
    columns.map((column) => {
      const existing = rawHeader[column.key]

        return [
        column.key,
        typeof existing === 'string'
          ? existing
          : options.seedHeader !== false && field.has_thead
            ? column.label || column.key
            : '',
      ]
    })
  ) as Record<string, string>

  const rows = rawRows
    .map((row) => {
      if (!isObjectRecord(row)) {
        return null
      }

      const rowId = typeof row.id === 'string' ? row.id : ''
      if (!rowId) {
        return null
      }

      const rawCells = isObjectRecord(row.cells) ? row.cells : {}

      return {
        id: rowId,
        cells: Object.fromEntries(
          columns.map((column) => [column.key, normalizeTableCellValue(column, rawCells[column.key])])
        ),
      } satisfies TableRow
    })
    .filter((row): row is TableRow => Boolean(row))

  return {
    header,
    rows,
  }
}

export const getTableHeaderPreview = (field: TableFieldSchema, value: unknown): string[] => {
  const normalizedValue = ensureTableValue(field, value)

  return getTableColumns(field).map(
    (column) => normalizedValue.header[column.key] || column.label || column.key
  )
}

export const mergeLocalizedTableValue = (
  baseValue: unknown,
  overlayValue: unknown,
  field: TableFieldSchema
): TableValue => {
  const columns = getTableColumns(field)
  const baseTable = ensureTableValue(field, baseValue)
  const overlayTable = ensureTableValue(field, overlayValue, { seedHeader: false })
  const overlayRows = new Map(overlayTable.rows.map((row) => [row.id, row] as const))

  return {
    header: Object.fromEntries(
      columns.map((column) => [
        column.key,
        overlayTable.header[column.key] || baseTable.header[column.key] || column.label || column.key,
      ])
    ),
    rows: baseTable.rows.map((baseRow) => {
      const overlayRow = overlayRows.get(baseRow.id)

      return {
        id: baseRow.id,
        cells: Object.fromEntries(
          columns.map((column) => {
            if (column.type !== 'text') {
              return [column.key, baseRow.cells[column.key] ?? getTableDefaultCellValue(column)]
            }

            const overlayValue = overlayRow?.cells?.[column.key]

            return [
              column.key,
              typeof overlayValue === 'string'
                ? overlayValue
                : (baseRow.cells[column.key] ?? getTableDefaultCellValue(column)),
            ]
          })
        ),
      }
    }),
  }
}

const mergePlainContent = (base: unknown, overlay: unknown): unknown => {
  if (Array.isArray(base) && Array.isArray(overlay)) {
    return base.map((item, index) =>
      index in overlay ? mergePlainContent(item, overlay[index]) : cloneStructuredValue(item)
    )
  }

  if (Array.isArray(overlay)) {
    return cloneStructuredValue(overlay)
  }

  if (isObjectRecord(base) && isObjectRecord(overlay)) {
    const merged = cloneStructuredValue(base)

    Object.entries(overlay).forEach(([key, value]) => {
      merged[key] = key in merged ? mergePlainContent(merged[key], value) : cloneStructuredValue(value)
    })

    return merged
  }

  if (overlay !== undefined) {
    return cloneStructuredValue(overlay)
  }

  return cloneStructuredValue(base)
}

export const mergeLocalizedContentForSchema = (
  base: Record<string, unknown>,
  overlay: Record<string, unknown>,
  schema: Record<string, SchemaType>,
  getBlockSchema?: BlockSchemaResolver
): Record<string, unknown> => {
  const merged = (mergePlainContent(base, overlay) || {}) as Record<string, unknown>

  Object.entries(schema || {}).forEach(([key, field]) => {
    const type = normalizeSchemaTypeName(field?.type)

    if (type === 'table' && (field as TableSchema).translatable) {
      merged[key] = mergeLocalizedTableValue(
        base?.[key],
        overlay?.[key],
        field as TableSchema
      )
      return
    }

    if (type !== 'blocks' || !Array.isArray(merged[key])) {
      return
    }

    const baseItems = Array.isArray(base?.[key]) ? (base[key] as Array<Record<string, unknown>>) : []
    const overlayItems = Array.isArray(overlay?.[key])
      ? (overlay[key] as Array<Record<string, unknown>>)
      : []
    const overlayById = new Map(
      overlayItems
        .filter(
          (overlayItem): overlayItem is Record<string, unknown> =>
            isObjectRecord(overlayItem) &&
            typeof overlayItem.id === 'string' &&
            overlayItem.id !== ''
        )
        .map((overlayItem) => [overlayItem.id as string, overlayItem] as const)
    )

    merged[key] = (merged[key] as Array<Record<string, unknown>>).map((item, index) => {
      if (!isObjectRecord(item)) {
        return item
      }

      const baseItem = isObjectRecord(baseItems[index]) ? baseItems[index] : {}
      const baseId = typeof baseItem.id === 'string' && baseItem.id !== '' ? baseItem.id : null
      const overlayItem =
        (baseId ? overlayById.get(baseId) : undefined) ??
        (isObjectRecord(overlayItems[index]) ? overlayItems[index] : {})

      const blockSlug = String(item.block || baseItem.block || overlayItem.block || '')
      const nestedSchema = blockSlug ? getBlockSchema?.(blockSlug)?.schema : undefined

      if (!nestedSchema) {
        return item
      }

      return mergeLocalizedContentForSchema(baseItem, overlayItem, nestedSchema, getBlockSchema)
    })
  })

  return merged
}
