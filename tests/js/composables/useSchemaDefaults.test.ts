import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createBlockItemWithDefaults,
  createContentDefaultsBlockLookup,
  hydrateContentWithSchema,
  resolveFieldInitialValue,
} from '~/composables/useSchemaDefaults'

const field = (type: string, extra: Record<string, unknown> = {}) =>
  ({ type, ...extra }) as unknown as SchemaType

describe('resolveFieldInitialValue', () => {
  it.each([
    ['text', ''],
    ['textarea', ''],
    ['markdown', ''],
    ['number', 0],
    ['boolean', false],
    ['date', ''],
    ['option', null],
    ['asset', null],
    ['link', null],
    ['icon', null],
    ['geo', null],
    ['price', null],
    ['serial', null],
    ['plugin', null],
  ])('defaults a %s field to %o', (type, expected) => {
    expect(resolveFieldInitialValue(field(type))).toBe(expected)
  })

  it.each(['options', 'blocks', 'multi_assets'])('defaults a %s field to an empty array', (type) => {
    expect(resolveFieldInitialValue(field(type))).toEqual([])
  })

  it.each(['richtext', 'meta'])('defaults a %s field to an empty object', (type) => {
    expect(resolveFieldInitialValue(field(type))).toEqual({})
  })

  it('defaults an unknown type to null', () => {
    expect(resolveFieldInitialValue(field('not-a-real-type'))).toBeNull()
  })

  it('normalizes the legacy type aliases', () => {
    expect(resolveFieldInitialValue(field('block'))).toEqual([])
    expect(resolveFieldInitialValue(field('multiAsset'))).toEqual([])
    // `reference` is multi-valued unless max is 1.
    expect(resolveFieldInitialValue(field('reference'))).toEqual([])
  })

  describe('references cardinality', () => {
    it('is an array by default', () => {
      expect(resolveFieldInitialValue(field('references'))).toEqual([])
    })

    it('is null when max is 1', () => {
      expect(resolveFieldInitialValue(field('references', { max: 1 }))).toBeNull()
    })

    it.each(['max_items', 'max'])('is null when validation.%s is 1', (key) => {
      expect(resolveFieldInitialValue(field('references', { validation: { [key]: 1 } }))).toBeNull()
    })

    it('prefers the field-level max over validation', () => {
      expect(
        resolveFieldInitialValue(field('references', { max: 5, validation: { max_items: 1 } }))
      ).toEqual([])
    })
  })

  describe('explicit defaults', () => {
    it('wins over the type default', () => {
      expect(resolveFieldInitialValue(field('text', { default: 'hello' }))).toBe('hello')
      expect(resolveFieldInitialValue(field('number', { default: 42 }))).toBe(42)
      expect(resolveFieldInitialValue(field('boolean', { default: true }))).toBe(true)
    })

    it('deep-clones object and array defaults so blocks never share state', () => {
      const schemaField = field('options', { default: [{ a: 1 }] })
      const first = resolveFieldInitialValue(schemaField) as Array<{ a: number }>
      const second = resolveFieldInitialValue(schemaField) as Array<{ a: number }>

      first[0].a = 2

      expect(second[0].a).toBe(1)
      expect((schemaField as unknown as { default: Array<{ a: number }> }).default[0].a).toBe(1)
    })

    it('does not treat a falsy default as absent', () => {
      expect(resolveFieldInitialValue(field('text', { default: '' }))).toBe('')
      expect(resolveFieldInitialValue(field('number', { default: 0 }))).toBe(0)
      expect(resolveFieldInitialValue(field('boolean', { default: false }))).toBe(false)
    })

    it('falls through to the type default when the default is null', () => {
      expect(resolveFieldInitialValue(field('text', { default: null }))).toBe('')
      expect(resolveFieldInitialValue(field('options', { default: null }))).toEqual([])
      // …except for the nullable types, which keep null.
      expect(resolveFieldInitialValue(field('option', { default: null }))).toBeNull()
    })
  })

  describe('date fields', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      // Local time, since the helper formats from the local getters.
      vi.setSystemTime(new Date(2026, 6, 29, 9, 5))
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('stays empty without use_current_as_default', () => {
      expect(resolveFieldInitialValue(field('date'))).toBe('')
    })

    it.each([
      [undefined, '2026-07-29'],
      ['date', '2026-07-29'],
      ['time', '09:05'],
      ['datetime-local', '2026-07-29T09:05'],
    ])('formats the current %s as %s', (format, expected) => {
      expect(
        resolveFieldInitialValue(field('date', { use_current_as_default: true, format }))
      ).toBe(expected)
    })
  })

  describe('table fields', () => {
    const tableField = field('table', {
      has_thead: true,
      columns: [
        { key: 'name', label: 'Name', type: 'text' },
        { key: 'qty', label: 'Qty', type: 'number' },
      ],
    })

    it('seeds the header from the column labels and starts with no rows', () => {
      expect(resolveFieldInitialValue(tableField)).toEqual({
        header: { name: 'Name', qty: 'Qty' },
        rows: [],
      })
    })

    it('leaves the header blank without has_thead', () => {
      expect(
        resolveFieldInitialValue(field('table', { columns: [{ key: 'name', label: 'Name', type: 'text' }] }))
      ).toEqual({ header: { name: '' }, rows: [] })
    })

    it('normalizes a null default against the columns', () => {
      expect(
        resolveFieldInitialValue(
          field('table', { columns: [{ key: 'name', label: 'Name', type: 'text' }], default: null })
        )
      ).toEqual({ header: { name: '' }, rows: [] })
    })

    it('normalizes a configured default against the columns', () => {
      expect(
        resolveFieldInitialValue(
          field('table', {
            columns: [{ key: 'name', label: 'Name', type: 'text' }],
            default: { rows: [{ id: 'r1', cells: { name: 'A', gone: 'x' } }] },
          })
        )
      ).toEqual({ header: { name: '' }, rows: [{ id: 'r1', cells: { name: 'A' } }] })
    })

    it('coerces default cells to their column type and drops rows without an id', () => {
      expect(
        resolveFieldInitialValue(
          field('table', {
            columns: [
              { key: 'name', label: 'Name', type: 'text' },
              { key: 'qty', label: 'Qty', type: 'number' },
              { key: 'live', label: 'Live', type: 'boolean' },
            ],
            default: {
              rows: [
                { id: 'r1', cells: { name: 42, qty: 'nope', live: 'yes' } },
                { cells: { name: 'orphan' } },
              ],
            },
          })
        )
      ).toEqual({
        header: { name: '', qty: '', live: '' },
        rows: [{ id: 'r1', cells: { name: '', qty: null, live: false } }],
      })
    })

    it('does not leak the configured default into the returned value', () => {
      const schemaField = field('table', {
        columns: [{ key: 'name', label: 'Name', type: 'text' }],
        default: { rows: [{ id: 'r1', cells: { name: 'A' } }] },
      })
      const first = resolveFieldInitialValue(schemaField) as { rows: Array<{ cells: { name: string } }> }

      first.rows[0].cells.name = 'mutated'

      expect(
        (resolveFieldInitialValue(schemaField) as typeof first).rows[0].cells.name
      ).toBe('A')
    })
  })
})

describe('createContentDefaultsBlockLookup', () => {
  it('keys blocks by slug, keeping only slug and schema', () => {
    const lookup = createContentDefaultsBlockLookup([
      { slug: 'hero', schema: { title: field('text') }, name: 'Hero' } as never,
    ])

    expect(Object.keys(lookup)).toEqual(['hero'])
    expect(Object.keys(lookup.hero)).toEqual(['slug', 'schema'])
  })

  it('merges extra blocks, letting later entries win on a slug clash', () => {
    const lookup = createContentDefaultsBlockLookup(
      [{ slug: 'hero', schema: {} }],
      [{ slug: 'hero', schema: { title: field('text') } }, { slug: 'cta', schema: {} }]
    )

    expect(Object.keys(lookup).sort()).toEqual(['cta', 'hero'])
    expect(Object.keys(lookup.hero.schema)).toEqual(['title'])
  })
})

describe('hydrateContentWithSchema', () => {
  const schema = { title: field('text'), count: field('number'), tags: field('options') }

  it('fills in every missing field', () => {
    expect(hydrateContentWithSchema(schema, {}, {})).toEqual({ title: '', count: 0, tags: [] })
  })

  it('keeps existing values, including falsy ones', () => {
    expect(hydrateContentWithSchema(schema, { title: 'Hi', count: 0 }, {})).toEqual({
      title: 'Hi',
      count: 0,
      tags: [],
    })
  })

  it('keeps an explicit null rather than replacing it with the default', () => {
    expect(hydrateContentWithSchema({ title: field('text') }, { title: null }, {})).toEqual({
      title: null,
    })
  })

  it('preserves keys the schema no longer declares', () => {
    expect(hydrateContentWithSchema({ title: field('text') }, { legacy: 'keep' }, {})).toEqual({
      title: '',
      legacy: 'keep',
    })
  })

  it('does not mutate the input content', () => {
    const content = { title: 'Hi' }

    hydrateContentWithSchema(schema, content, {})

    expect(content).toEqual({ title: 'Hi' })
  })

  it('tolerates a null schema and null content', () => {
    expect(hydrateContentWithSchema(null, null, {})).toEqual({})
  })

  it('hydrates nested blocks against the block lookup', () => {
    const lookup = createContentDefaultsBlockLookup([
      { slug: 'hero', schema: { headline: field('text'), visible: field('boolean') } },
    ])

    expect(
      hydrateContentWithSchema(
        { body: field('blocks') },
        { body: [{ id: 'b1', block: 'hero', headline: 'Hi' }] },
        lookup
      )
    ).toEqual({
      body: [{ id: 'b1', block: 'hero', headline: 'Hi', visible: false }],
    })
  })

  it('recurses through nested blocks inside blocks', () => {
    const lookup = createContentDefaultsBlockLookup([
      { slug: 'section', schema: { items: field('blocks') } },
      { slug: 'card', schema: { title: field('text') } },
    ])

    expect(
      hydrateContentWithSchema(
        { body: field('blocks') },
        { body: [{ block: 'section', items: [{ block: 'card' }] }] },
        lookup
      )
    ).toEqual({ body: [{ block: 'section', items: [{ block: 'card', title: '' }] }] })
  })

  it('leaves block items alone when the block is unknown', () => {
    expect(
      hydrateContentWithSchema({ body: field('blocks') }, { body: [{ block: 'missing' }] }, {})
    ).toEqual({ body: [{ block: 'missing' }] })
  })

  it('leaves non-object block entries alone', () => {
    expect(
      hydrateContentWithSchema({ body: field('blocks') }, { body: ['plain', null] }, {})
    ).toEqual({ body: ['plain', null] })
  })

  it('does not recurse when the blocks value is not an array', () => {
    expect(
      hydrateContentWithSchema({ body: field('blocks') }, { body: 'oops' }, {})
    ).toEqual({ body: 'oops' })
  })
})

describe('createBlockItemWithDefaults', () => {
  it('stamps an id and the block slug alongside the defaults', () => {
    const item = createBlockItemWithDefaults({
      slug: 'hero',
      schema: { title: field('text'), visible: field('boolean') },
    })

    expect(item.block).toBe('hero')
    expect(item.id).toEqual(expect.any(String))
    expect(item.id).not.toBe('')
    expect(item).toMatchObject({ title: '', visible: false })
  })

  it('gives each item a distinct id', () => {
    const block = { slug: 'hero', schema: {} }

    expect(createBlockItemWithDefaults(block).id).not.toBe(createBlockItemWithDefaults(block).id)
  })

  it('hydrates nested blocks via the lookup', () => {
    const lookup = createContentDefaultsBlockLookup([{ slug: 'card', schema: { title: field('text') } }])
    const item = createBlockItemWithDefaults(
      { slug: 'section', schema: { items: field('blocks', { default: [{ block: 'card' }] }) } },
      lookup
    )

    expect((item as Record<string, unknown>).items).toEqual([{ block: 'card', title: '' }])
  })
})
