import { describe, expect, it } from 'vitest'

import {
  cloneStructuredValue,
  createTableRow,
  ensureTableValue,
  getTableColumns,
  getTableDefaultCellValue,
  getTableHeaderPreview,
  mergeLocalizedContentForSchema,
  mergeLocalizedTableValue,
  normalizeSchemaTypeName,
} from '~/lib/tableField'

const schema = (columns: unknown[], has_thead = false) =>
  ({ columns, has_thead }) as unknown as TableSchema

const textColumn = { key: 'name', label: 'Name', type: 'text' }
const numberColumn = { key: 'qty', label: 'Qty', type: 'number' }
const booleanColumn = { key: 'live', label: 'Live', type: 'boolean' }

describe('cloneStructuredValue', () => {
  it('deep-clones so the copy shares no references', () => {
    const source = { nested: { list: [1, 2] } }
    const clone = cloneStructuredValue(source)

    clone.nested.list.push(3)

    expect(source.nested.list).toEqual([1, 2])
  })
})

describe('normalizeSchemaTypeName', () => {
  it.each([
    ['multiAsset', 'multi_assets'],
    ['reference', 'references'],
    ['block', 'blocks'],
  ])('maps the legacy alias %s to %s', (input, expected) => {
    expect(normalizeSchemaTypeName(input)).toBe(expected)
  })

  it.each([
    'text',
    'blocks',
    'table',
    'richtext',
    'references',
    'date',
    'meta',
    'geo',
    'price',
    'icon',
    'serial',
    'plugin',
  ])('passes the canonical type %s through', (type) => {
    // This is the one canonical list; `useContentSchemaState.normalizeSchemaType`
    // re-exports it, so a new field type is only ever added here.
    expect(normalizeSchemaTypeName(type)).toBe(type)
  })

  it.each([undefined, null, '', 'nonsense'])('returns an empty string for %s', (type) => {
    expect(normalizeSchemaTypeName(type)).toBe('')
  })
})

describe('getTableColumns', () => {
  it('returns the normalized columns', () => {
    expect(getTableColumns(schema([textColumn, numberColumn]))).toEqual([
      { key: 'name', label: 'Name', type: 'text' },
      { key: 'qty', label: 'Qty', type: 'number' },
    ])
  })

  it('defaults a column with no type to text', () => {
    expect(getTableColumns(schema([{ key: 'name', label: 'Name' }]))).toEqual([
      { key: 'name', label: 'Name', type: 'text' },
    ])
  })

  it('coerces missing key and label to empty strings', () => {
    expect(getTableColumns(schema([{ type: 'text' }]))).toEqual([{ key: '', label: '', type: 'text' }])
  })

  it('drops columns of an unsupported type', () => {
    expect(getTableColumns(schema([{ key: 'rte', type: 'richtext' }, textColumn]))).toEqual([
      { key: 'name', label: 'Name', type: 'text' },
    ])
  })

  it('drops non-object columns', () => {
    expect(getTableColumns(schema(['nope', null, 42, ['a'], textColumn]))).toHaveLength(1)
  })

  it('returns nothing when columns is missing or not an array', () => {
    expect(getTableColumns(schema([]))).toEqual([])
    expect(getTableColumns(null)).toEqual([])
    expect(getTableColumns(undefined)).toEqual([])
    expect(getTableColumns({ columns: 'nope' } as never)).toEqual([])
  })

  describe('option columns', () => {
    it('normalizes self-sourced options', () => {
      expect(
        getTableColumns(
          schema([
            {
              key: 'status',
              label: 'Status',
              type: 'option',
              options: [{ name: 'Live', value: 'live' }],
            },
          ])
        )
      ).toEqual([
        {
          key: 'status',
          label: 'Status',
          type: 'option',
          source: 'self',
          options: [{ name: 'Live', value: 'live' }],
          data_source_id: null,
        },
      ])
    })

    it('keeps a datasource source and its id', () => {
      expect(
        getTableColumns(
          schema([{ key: 's', type: 'option', source: 'datasource', data_source_id: 'ds-1' }])
        )[0]
      ).toMatchObject({ source: 'datasource', data_source_id: 'ds-1' })
    })

    it('treats any other source as self', () => {
      expect(getTableColumns(schema([{ key: 's', type: 'option', source: 'weird' }]))[0]).toMatchObject(
        { source: 'self' }
      )
    })

    it('drops malformed options and defaults a missing list to empty', () => {
      expect(
        getTableColumns(
          schema([{ key: 's', type: 'option', options: ['nope', null, { name: 'A', value: 'a' }] }])
        )[0]
      ).toMatchObject({ options: [{ name: 'A', value: 'a' }] })
      expect(getTableColumns(schema([{ key: 's', type: 'option' }]))[0]).toMatchObject({ options: [] })
    })
  })
})

describe('getTableDefaultCellValue', () => {
  it.each([
    ['text', ''],
    ['number', null],
    ['option', null],
    ['boolean', false],
  ])('defaults a %s cell to %o', (type, expected) => {
    expect(getTableDefaultCellValue({ key: 'k', label: '', type } as TableColumn)).toBe(expected)
  })
})

describe('createTableRow', () => {
  it('builds a row of default cells under the given id', () => {
    expect(createTableRow(getTableColumns(schema([textColumn, numberColumn])), 'row-1')).toEqual({
      id: 'row-1',
      cells: { name: '', qty: null },
    })
  })

  it('builds an empty cell map when there are no columns', () => {
    expect(createTableRow([], 'row-1')).toEqual({ id: 'row-1', cells: {} })
  })
})

describe('ensureTableValue', () => {
  const field = schema([textColumn, numberColumn, booleanColumn], true)

  it('seeds the header from the labels when has_thead is on', () => {
    expect(ensureTableValue(field, null)).toEqual({
      header: { name: 'Name', qty: 'Qty', live: 'Live' },
      rows: [],
    })
  })

  it('falls back to the column key when a label is missing', () => {
    expect(ensureTableValue(schema([{ key: 'name', type: 'text' }], true), null).header).toEqual({
      name: 'name',
    })
  })

  it('leaves the header blank without has_thead', () => {
    expect(ensureTableValue(schema([textColumn]), null).header).toEqual({ name: '' })
  })

  it('leaves the header blank when seeding is explicitly disabled', () => {
    expect(ensureTableValue(field, null, { seedHeader: false }).header).toEqual({
      name: '',
      qty: '',
      live: '',
    })
  })

  it('keeps an existing header string, including a blank one', () => {
    expect(
      ensureTableValue(field, { header: { name: 'Produkt', qty: '' } }).header
    ).toEqual({ name: 'Produkt', qty: '', live: 'Live' })
  })

  it('drops header entries for columns that no longer exist', () => {
    expect(ensureTableValue(schema([textColumn]), { header: { name: 'A', gone: 'B' } }).header).toEqual({
      name: 'A',
    })
  })

  it('coerces each cell to its column type', () => {
    expect(
      ensureTableValue(field, {
        rows: [{ id: 'r1', cells: { name: 42, qty: 'nope', live: 'yes' } }],
      }).rows
    ).toEqual([{ id: 'r1', cells: { name: '', qty: null, live: false } }])
  })

  it('keeps well-typed cell values', () => {
    expect(
      ensureTableValue(field, { rows: [{ id: 'r1', cells: { name: 'A', qty: 7, live: true } }] }).rows
    ).toEqual([{ id: 'r1', cells: { name: 'A', qty: 7, live: true } }])
  })

  it('rejects non-finite numbers', () => {
    expect(
      ensureTableValue(schema([numberColumn]), {
        rows: [{ id: 'r1', cells: { qty: Number.NaN } }],
      }).rows[0].cells.qty
    ).toBeNull()
  })

  it('lets an option cell hold null or a string but nothing else', () => {
    const optionField = schema([{ key: 's', type: 'option' }])
    const cellFor = (value: unknown) =>
      ensureTableValue(optionField, { rows: [{ id: 'r1', cells: { s: value } }] }).rows[0].cells.s

    expect(cellFor('live')).toBe('live')
    expect(cellFor(null)).toBeNull()
    expect(cellFor(3)).toBeNull()
  })

  it('fills in cells missing from the row', () => {
    expect(ensureTableValue(field, { rows: [{ id: 'r1', cells: {} }] }).rows[0].cells).toEqual({
      name: '',
      qty: null,
      live: false,
    })
  })

  it('drops cells for columns that no longer exist', () => {
    expect(
      ensureTableValue(schema([textColumn]), {
        rows: [{ id: 'r1', cells: { name: 'A', gone: 'B' } }],
      }).rows[0].cells
    ).toEqual({ name: 'A' })
  })

  it('tolerates a row with no cells object', () => {
    expect(ensureTableValue(schema([textColumn]), { rows: [{ id: 'r1' }] }).rows).toEqual([
      { id: 'r1', cells: { name: '' } },
    ])
  })

  it('drops rows without a usable string id', () => {
    expect(
      ensureTableValue(schema([textColumn]), {
        rows: [{ id: 'r1' }, { id: '' }, { id: 7 }, {}, 'nope', null],
      }).rows.map((row) => row.id)
    ).toEqual(['r1'])
  })

  it.each([null, undefined, 'nope', 42, ['a']])('treats %o as an empty value', (value) => {
    expect(ensureTableValue(schema([textColumn]), value)).toEqual({ header: { name: '' }, rows: [] })
  })

  it('ignores a non-array rows key and a non-object header key', () => {
    expect(ensureTableValue(schema([textColumn]), { rows: 'nope', header: 'nope' })).toEqual({
      header: { name: '' },
      rows: [],
    })
  })

  it('does not alias the input value', () => {
    const value = { header: { name: 'A' }, rows: [{ id: 'r1', cells: { name: 'B' } }] }
    const result = ensureTableValue(schema([textColumn]), value)

    result.header.name = 'changed'
    result.rows[0].cells.name = 'changed'

    expect(value.header.name).toBe('A')
    expect(value.rows[0].cells.name).toBe('B')
  })
})

describe('getTableHeaderPreview', () => {
  it('prefers the stored header, then the label, then the key', () => {
    expect(
      getTableHeaderPreview(
        schema([textColumn, numberColumn, { key: 'bare', type: 'text' }]),
        { header: { name: 'Produkt' } }
      )
    ).toEqual(['Produkt', 'Qty', 'bare'])
  })

  it('is empty without columns', () => {
    expect(getTableHeaderPreview(schema([]), null)).toEqual([])
  })
})

describe('mergeLocalizedTableValue', () => {
  const field = schema([textColumn, numberColumn, booleanColumn], true)
  const base = {
    header: { name: 'Name', qty: 'Qty', live: 'Live' },
    rows: [
      { id: 'r1', cells: { name: 'Widget', qty: 3, live: true } },
      { id: 'r2', cells: { name: 'Gadget', qty: 5, live: false } },
    ],
  }

  it('lets the overlay win for translatable text cells', () => {
    const merged = mergeLocalizedTableValue(base, {
      rows: [{ id: 'r1', cells: { name: 'Gerät' } }],
    }, field)

    expect(merged.rows[0].cells.name).toBe('Gerät')
    expect(merged.rows[1].cells.name).toBe('Gadget')
  })

  it('keeps the base value for non-text cells even when the overlay sets one', () => {
    const merged = mergeLocalizedTableValue(base, {
      rows: [{ id: 'r1', cells: { name: 'Gerät', qty: 99, live: false } }],
    }, field)

    expect(merged.rows[0].cells).toEqual({ name: 'Gerät', qty: 3, live: true })
  })

  it('matches rows by id, not by position', () => {
    const merged = mergeLocalizedTableValue(base, {
      rows: [{ id: 'r2', cells: { name: 'Zweitens' } }],
    }, field)

    expect(merged.rows.map((row) => row.cells.name)).toEqual(['Widget', 'Zweitens'])
  })

  it('drops overlay rows the base does not have', () => {
    const merged = mergeLocalizedTableValue(base, {
      rows: [{ id: 'ghost', cells: { name: 'Nope' } }],
    }, field)

    expect(merged.rows.map((row) => row.id)).toEqual(['r1', 'r2'])
  })

  it('keeps the row shape when the base row is empty', () => {
    const merged = mergeLocalizedTableValue({ rows: [{ id: 'r1', cells: {} }] }, {}, field)

    expect(merged.rows[0].cells).toEqual({ name: '', qty: null, live: false })
  })

  it('prefers the overlay header, then the base header, then the label', () => {
    const merged = mergeLocalizedTableValue(
      { header: { name: 'Name', qty: 'Qty' } },
      { header: { name: 'Produkt' } },
      field
    )

    expect(merged.header).toEqual({ name: 'Produkt', qty: 'Qty', live: 'Live' })
  })

  it('falls back to the column key when neither side has a header or label', () => {
    expect(
      mergeLocalizedTableValue({}, {}, schema([{ key: 'bare', type: 'text' }])).header
    ).toEqual({ bare: 'bare' })
  })

  it('produces an empty table from two empty sides', () => {
    expect(mergeLocalizedTableValue(null, null, schema([textColumn]))).toEqual({
      header: { name: 'Name' },
      rows: [],
    })
  })
})

describe('mergeLocalizedContentForSchema', () => {
  const textField = { type: 'text' } as SchemaType

  it('lets the overlay win for plain fields', () => {
    expect(
      mergeLocalizedContentForSchema({ title: 'Home', slug: 'home' }, { title: 'Startseite' }, {
        title: textField,
        slug: textField,
      })
    ).toEqual({ title: 'Startseite', slug: 'home' })
  })

  it('keeps base keys the overlay omits and adds keys only the overlay has', () => {
    expect(mergeLocalizedContentForSchema({ a: 1 }, { b: 2 }, {})).toEqual({ a: 1, b: 2 })
  })

  it('merges arrays element-wise, keeping base elements past the overlay end', () => {
    expect(
      mergeLocalizedContentForSchema({ list: ['a', 'b', 'c'] }, { list: ['A'] }, {}).list
    ).toEqual(['A', 'b', 'c'])
  })

  it('replaces a non-array base with an overlay array', () => {
    expect(mergeLocalizedContentForSchema({ list: 'nope' }, { list: ['A'] }, {}).list).toEqual(['A'])
  })

  it('does not mutate the base', () => {
    const base = { nested: { title: 'Home' } }

    mergeLocalizedContentForSchema(base, { nested: { title: 'Startseite' } }, {})

    expect(base.nested.title).toBe('Home')
  })

  describe('translatable tables', () => {
    const tableField = {
      type: 'table',
      translatable: true,
      has_thead: true,
      columns: [textColumn, numberColumn],
    } as unknown as SchemaType

    it('merges cell by cell rather than replacing the whole value', () => {
      const merged = mergeLocalizedContentForSchema(
        { specs: { rows: [{ id: 'r1', cells: { name: 'Width', qty: 3 } }] } },
        { specs: { rows: [{ id: 'r1', cells: { name: 'Breite' } }] } },
        { specs: tableField }
      )

      expect((merged.specs as TableValue).rows[0].cells).toEqual({ name: 'Breite', qty: 3 })
    })

    it('leaves a non-translatable table to the plain merge', () => {
      const merged = mergeLocalizedContentForSchema(
        { specs: { rows: [{ id: 'r1', cells: { name: 'Width', qty: 3 } }] } },
        { specs: { rows: [{ id: 'r1', cells: { name: 'Breite' } }] } },
        { specs: { ...tableField, translatable: false } as SchemaType }
      )

      // Plain merge: the overlay row wins field by field, and no normalization
      // fills the missing cell back in.
      expect((merged.specs as { rows: Array<{ cells: Record<string, unknown> }> }).rows[0].cells).toEqual({
        name: 'Breite',
        qty: 3,
      })
    })
  })

  describe('nested blocks', () => {
    const blocksField = { type: 'blocks' } as SchemaType
    const getBlockSchema = (slug: string) =>
      slug === 'hero' ? { schema: { headline: textField, count: { type: 'number' } as SchemaType } } : undefined

    it('recurses into a block using the resolved schema', () => {
      const merged = mergeLocalizedContentForSchema(
        { body: [{ id: 'b1', block: 'hero', headline: 'Hello', count: 3 }] },
        { body: [{ id: 'b1', block: 'hero', headline: 'Hallo' }] },
        { body: blocksField },
        getBlockSchema
      )

      expect(merged.body).toEqual([{ id: 'b1', block: 'hero', headline: 'Hallo', count: 3 }])
    })

    it('pairs blocks by id even when the overlay reorders them', () => {
      const merged = mergeLocalizedContentForSchema(
        {
          body: [
            { id: 'b1', block: 'hero', headline: 'One' },
            { id: 'b2', block: 'hero', headline: 'Two' },
          ],
        },
        {
          body: [
            { id: 'b2', block: 'hero', headline: 'Zwei' },
            { id: 'b1', block: 'hero', headline: 'Eins' },
          ],
        },
        { body: blocksField },
        getBlockSchema
      )

      expect((merged.body as Array<{ headline: string }>).map((item) => item.headline)).toEqual([
        'Eins',
        'Zwei',
      ])
    })

    it('falls back to positional pairing when the base block has no id', () => {
      const merged = mergeLocalizedContentForSchema(
        { body: [{ block: 'hero', headline: 'One' }] },
        { body: [{ block: 'hero', headline: 'Eins' }] },
        { body: blocksField },
        getBlockSchema
      )

      expect((merged.body as Array<{ headline: string }>)[0].headline).toBe('Eins')
    })

    it('leaves a block alone when its schema cannot be resolved', () => {
      const merged = mergeLocalizedContentForSchema(
        { body: [{ id: 'b1', block: 'unknown', headline: 'One' }] },
        { body: [{ id: 'b1', block: 'unknown', headline: 'Eins' }] },
        { body: blocksField },
        getBlockSchema
      )

      // Still the plain-merged item — just not schema-aware.
      expect((merged.body as Array<{ headline: string }>)[0].headline).toBe('Eins')
    })

    it('leaves blocks alone without a resolver at all', () => {
      expect(
        mergeLocalizedContentForSchema(
          { body: [{ id: 'b1', block: 'hero', headline: 'One' }] },
          {},
          { body: blocksField }
        ).body
      ).toEqual([{ id: 'b1', block: 'hero', headline: 'One' }])
    })

    it('ignores a blocks field whose merged value is not an array', () => {
      expect(
        mergeLocalizedContentForSchema({ body: 'nope' }, {}, { body: blocksField }).body
      ).toBe('nope')
    })
  })

  it('tolerates a null schema', () => {
    expect(
      mergeLocalizedContentForSchema({ a: 1 }, { a: 2 }, null as unknown as Record<string, SchemaType>)
    ).toEqual({ a: 2 })
  })
})
