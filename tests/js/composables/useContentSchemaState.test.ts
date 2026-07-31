import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick, ref, type Ref } from 'vue'

import type { ContentResource } from '~/types/contents'

import {
  isFieldVisible,
  normalizeSchema,
  normalizeSchemaField,
  normalizeSchemaType,
  useContentSchemaState,
} from '~/composables/useContentSchemaState'

import { withSetup, type Harness } from '../support/harness'

type SchemaMap = Record<string, SchemaType>

const field = (type: string, extra: Record<string, unknown> = {}) =>
  ({ type, ...extra }) as unknown as SchemaType

const normalized = (type: string, extra: Record<string, unknown> = {}, key = 'f') =>
  normalizeSchemaField(key, field(type, extra)) as unknown as Record<string, unknown>

describe('normalizeSchemaType', () => {
  it.each([
    ['multiAsset', 'multi_assets'],
    ['reference', 'references'],
    ['block', 'blocks'],
  ])('maps the legacy alias %s to %s', (input, expected) => {
    expect(normalizeSchemaType(input)).toBe(expected)
  })

  it.each([
    'blocks',
    'text',
    'textarea',
    'markdown',
    'richtext',
    'number',
    'boolean',
    'option',
    'options',
    'link',
    'asset',
    'multi_assets',
    'icon',
    'geo',
    'price',
    'references',
    'date',
    'meta',
    'table',
    'plugin',
    'serial',
  ])('passes the canonical type %s through', (type) => {
    expect(normalizeSchemaType(type)).toBe(type)
  })

  it.each([undefined, null, '', 'nonsense', 'Text', 'multiassets'])(
    'returns an empty string for %o',
    (type) => {
      expect(normalizeSchemaType(type)).toBe('')
    }
  )

  it('is the very normalizer ~/lib/tableField exports', async () => {
    // One list, one call site: a second switch would have to be kept in step
    // with every new field type.
    const { normalizeSchemaTypeName } = await import('~/lib/tableField')

    expect(normalizeSchemaType).toBe(normalizeSchemaTypeName)
  })
})

describe('normalizeSchemaField', () => {
  it('stamps the key and keeps every declared property', () => {
    expect(normalized('text', { description: 'Hi', order: 2 })).toMatchObject({
      key: 'f',
      type: 'text',
      description: 'Hi',
      order: 2,
    })
  })

  it('names the field after its key when it has no name', () => {
    expect(normalized('text').name).toBe('f')
    expect(normalized('text', { name: 'Title' }).name).toBe('Title')
  })

  it('leaves an unknown type as an empty string and treats it as non-canonical', () => {
    expect(normalized('nonsense')).toMatchObject({
      type: '',
      translatable: false,
      indexable: false,
      source: undefined,
    })
  })

  it('normalizes a legacy type alias in place', () => {
    expect(normalized('multiAsset').type).toBe('multi_assets')
  })

  it('coerces required to a boolean', () => {
    expect(normalized('text', { required: 'yes' }).required).toBe(true)
    expect(normalized('text').required).toBe(false)
  })

  describe('translatable', () => {
    it.each(['text', 'textarea', 'markdown', 'richtext', 'number', 'link', 'meta', 'date', 'table', 'plugin'])(
      'honours the flag for %s',
      (type) => {
        expect(normalized(type, { translatable: true }).translatable).toBe(true)
      }
    )

    it.each(['boolean', 'option', 'options', 'asset', 'multi_assets', 'references', 'blocks', 'icon', 'geo', 'price', 'serial'])(
      'refuses the flag for %s',
      (type) => {
        expect(normalized(type, { translatable: true }).translatable).toBe(false)
      }
    )

    it('is false without the flag even on a translatable type', () => {
      expect(normalized('text').translatable).toBe(false)
    })
  })

  describe('indexable', () => {
    it.each(['text', 'textarea', 'markdown', 'richtext', 'meta'])('defaults %s to true', (type) => {
      expect(normalized(type).indexable).toBe(true)
    })

    it.each(['number', 'boolean', 'table', 'blocks'])('defaults %s to false', (type) => {
      expect(normalized(type).indexable).toBe(false)
    })

    it('respects an explicit flag either way', () => {
      expect(normalized('text', { indexable: false }).indexable).toBe(false)
      expect(normalized('number', { indexable: true }).indexable).toBe(true)
    })
  })

  describe('option and options fields', () => {
    it.each(['option', 'options'])('defaults the %s source to self', (type) => {
      expect(normalized(type)).toMatchObject({ source: 'self', data_source_id: null })
    })

    it('keeps a datasource source and its id', () => {
      expect(normalized('option', { source: 'datasource', data_source_id: 'ds-1' })).toMatchObject({
        source: 'datasource',
        data_source_id: 'ds-1',
      })
    })

    it('treats any other source as self', () => {
      expect(normalized('option', { source: 'weird' }).source).toBe('self')
    })

    it('derives allowed_values from the self-sourced options', () => {
      expect(
        (
          normalized('option', {
            options: [{ name: 'A', value: 'a' }, { name: 'B', value: 'b' }],
          }).validation as FieldValidation
        ).allowed_values
      ).toEqual(['a', 'b'])
    })

    it('drops only blank option values from allowed_values, keeping a numeric zero', () => {
      expect(
        (
          normalized('option', { options: [{ value: '' }, { value: 0 }, { value: 'a' }] })
            .validation as FieldValidation
        ).allowed_values
      ).toEqual(['0', 'a'])
    })

    it('prefers an explicitly declared allowed_values list', () => {
      expect(
        (
          normalized('option', {
            options: [{ value: 'a' }],
            validation: { allowed_values: ['x'] },
          }).validation as FieldValidation
        ).allowed_values
      ).toEqual(['x'])
    })

    it('leaves allowed_values untouched for a datasource-sourced field', () => {
      expect(
        (
          normalized('option', { source: 'datasource', options: [{ value: 'a' }] })
            .validation as FieldValidation
        ).allowed_values
      ).toBeUndefined()
    })

    it('derives no allowed_values for a field that is not option-like', () => {
      expect((normalized('text').validation as FieldValidation).allowed_values).toBeUndefined()
    })
  })

  describe('icon fields', () => {
    it('defaults the source to all', () => {
      expect(normalized('icon').source).toBe('all')
    })

    it.each(['registry', 'all', 'collections'])('keeps the %s source', (source) => {
      expect(normalized('icon', { source }).source).toBe(source)
    })

    it('falls back to all for an unknown icon source', () => {
      expect(normalized('icon', { source: 'weird' }).source).toBe('all')
    })

    it('gets no data_source_id', () => {
      expect(normalized('icon').data_source_id).toBeUndefined()
    })
  })

  describe('geo fields', () => {
    it('defaults the key style, altitude and map', () => {
      expect(normalized('geo')).toMatchObject({
        key_style: 'lat_lng',
        altitude: false,
        map: true,
      })
    })

    it('keeps the declared key style and coerces altitude', () => {
      expect(normalized('geo', { key_style: 'latitude_longitude', altitude: 1 })).toMatchObject({
        key_style: 'latitude_longitude',
        altitude: true,
      })
    })

    it('lets the map be turned off', () => {
      expect(normalized('geo', { map: false }).map).toBe(false)
    })

    it('leaves the geo keys undefined on other types', () => {
      expect(normalized('text')).toMatchObject({
        key_style: undefined,
        altitude: undefined,
        map: undefined,
      })
    })
  })

  describe('price fields', () => {
    it('defaults to EUR with no extra currencies', () => {
      expect(normalized('price')).toMatchObject({ base_currency: 'EUR', currencies: [] })
    })

    it('keeps the declared currencies', () => {
      expect(normalized('price', { base_currency: 'USD', currencies: ['CHF'] })).toMatchObject({
        base_currency: 'USD',
        currencies: ['CHF'],
      })
    })

    it('leaves the price keys undefined on other types', () => {
      expect(normalized('text')).toMatchObject({ base_currency: undefined, currencies: undefined })
    })
  })

  describe('table fields', () => {
    it('normalizes the columns and seeds a default value', () => {
      expect(
        normalized('table', {
          has_thead: true,
          columns: [{ key: 'name', label: 'Name', type: 'text' }, { key: 'rte', type: 'richtext' }],
        })
      ).toMatchObject({
        has_thead: true,
        columns: [{ key: 'name', label: 'Name', type: 'text' }],
        default: { header: { name: 'Name' }, rows: [] },
      })
    })

    it('gives a table without columns an empty column list and value', () => {
      expect(normalized('table')).toMatchObject({
        has_thead: false,
        columns: [],
        default: { header: {}, rows: [] },
      })
    })

    it('normalizes a configured default against the columns', () => {
      expect(
        normalized('table', {
          columns: [{ key: 'name', label: 'Name', type: 'text' }],
          default: { rows: [{ id: 'r1', cells: { name: 'A', gone: 'x' } }] },
        }).default
      ).toEqual({ header: { name: '' }, rows: [{ id: 'r1', cells: { name: 'A' } }] })
    })

    it('leaves the table keys undefined and the default untouched on other types', () => {
      expect(normalized('text', { default: 'hi' })).toMatchObject({
        has_thead: undefined,
        columns: undefined,
        default: 'hi',
      })
    })
  })

  describe('serial fields', () => {
    it('defaults the format, scope, uniqueness and move behaviour', () => {
      expect(normalized('serial')).toMatchObject({
        format: '{counter}',
        scope: ['block', 'parent'],
        unique: 'scope',
        on_move: 'keep',
        editable: false,
      })
    })

    it('is readonly unless it was explicitly opened for editing', () => {
      expect(normalized('serial').readonly).toBe(true)
      expect(normalized('serial', { editable: true }).readonly).toBe(false)
    })

    it('ignores a declared readonly flag on a serial', () => {
      // Actual behaviour: editable, not readonly, decides for serials.
      expect(normalized('serial', { readonly: false }).readonly).toBe(true)
      expect(normalized('serial', { editable: true, readonly: true }).readonly).toBe(false)
    })

    it('falls back to the default format when the declared one is blank', () => {
      expect(normalized('serial', { format: '' }).format).toBe('{counter}')
    })

    it('keeps the declared serial configuration', () => {
      expect(
        normalized('serial', {
          format: 'INV-{counter}',
          scope: ['space', 'year'],
          unique: 'space',
          on_move: 'reallocate',
        })
      ).toMatchObject({
        format: 'INV-{counter}',
        scope: ['space', 'year'],
        unique: 'space',
        on_move: 'reallocate',
      })
    })

    it('leaves the serial keys undefined on other types and honours readonly there', () => {
      expect(normalized('text', { readonly: true })).toMatchObject({
        format: undefined,
        scope: undefined,
        unique: undefined,
        on_move: undefined,
        editable: undefined,
        readonly: true,
      })
    })
  })

  describe('plugin fields', () => {
    it('keeps the handle and object-shaped options', () => {
      expect(
        normalized('plugin', { plugin_handle: 'colour-picker', options: { mode: 'hex' } })
      ).toMatchObject({ type: 'plugin', plugin_handle: 'colour-picker', options: { mode: 'hex' } })
    })

    it('does not mistake the plugin options object for an option list', () => {
      expect(
        (
          normalized('plugin', { options: { mode: 'hex' } }).validation as FieldValidation
        ).allowed_values
      ).toBeUndefined()
    })

    it('gets none of the option, icon, geo, price, table or serial extras', () => {
      expect(normalized('plugin')).toMatchObject({
        source: undefined,
        data_source_id: undefined,
        key_style: undefined,
        base_currency: undefined,
        columns: undefined,
        format: undefined,
      })
    })
  })

  describe('validation shape', () => {
    it('keeps a declared validation block', () => {
      expect(normalized('text', { validation: { min_length: 2, pattern: '^a' } }).validation).toMatchObject(
        { min_length: 2, pattern: '^a' }
      )
    })

    it('lifts the field-level min and max into validation', () => {
      expect(normalized('references', { min: 1, max: 3 }).validation).toMatchObject({
        min: 1,
        max: 3,
        min_items: 1,
        max_items: 3,
      })
    })

    it('prefers the validation block over the field-level values', () => {
      expect(
        normalized('references', { min: 1, max: 3, validation: { min_items: 5, max_items: 9 } })
          .validation
      ).toMatchObject({ min_items: 5, max_items: 9, min: 1, max: 3 })
    })

    it('accepts the legacy minimum/maximum aliases for min and max', () => {
      expect(normalized('number', { minimum: 2, maximum: 8 }).validation).toMatchObject({
        min: 2,
        max: 8,
      })
    })

    it('accepts maximum as a max_length while minimum never reaches min_length', () => {
      // Deliberately asymmetric, mirroring SchemaField::normalizeValidation on
      // the backend: legacy `maximum` doubles as a text length, `minimum` does not.
      const validation = normalized('text', { minimum: 2, maximum: 8 }).validation as FieldValidation

      expect(validation.max_length).toBe(8)
      expect(validation.min_length).toBeUndefined()
    })

    it('reuses the field-level min for both the numeric and the item bounds', () => {
      expect(normalized('number', { min: 5 }).validation).toMatchObject({ min: 5, min_items: 5 })
    })
  })

  describe('conditions', () => {
    it('is null without conditions or dependencies', () => {
      expect(normalized('text').conditions).toBeNull()
    })

    it('defaults the mode to all', () => {
      expect(
        normalized('text', { conditions: { rules: [{ field: 'a', operator: 'equals', value: 1 }] } })
          .conditions
      ).toEqual({ mode: 'all', rules: [{ field: 'a', operator: 'equals', value: 1 }] })
    })

    it('keeps the any mode', () => {
      expect(
        (normalized('text', { conditions: { mode: 'any', rules: [] } }).conditions as { mode: string })
          .mode
      ).toBe('any')
    })

    it('coerces an unknown mode to all', () => {
      expect(
        (normalized('text', { conditions: { mode: 'either', rules: [] } }).conditions as {
          mode: string
        }).mode
      ).toBe('all')
    })

    it('drops rules that name no field', () => {
      expect(
        (
          normalized('text', {
            conditions: {
              mode: 'all',
              rules: [{ field: '', operator: 'equals' }, undefined, { field: 'a', operator: 'equals' }],
            },
          }).conditions as { rules: unknown[] }
        ).rules
      ).toEqual([{ field: 'a', operator: 'equals' }])
    })

    it('tolerates conditions without a rules array', () => {
      expect(normalized('text', { conditions: {} }).conditions).toEqual({ mode: 'all', rules: [] })
    })

    describe('legacy dependencies', () => {
      it.each([
        ['=', 'equals'],
        ['!=', 'not_equals'],
        ['>', 'gt'],
        ['>=', 'gte'],
        ['<', 'lt'],
        ['<=', 'lte'],
        ['empty', 'is_empty'],
        ['not_empty', 'is_not_empty'],
      ])('maps the legacy operator %s to %s', (legacy, expected) => {
        expect(
          normalized('text', { dependencies: [{ field: 'a', operator: legacy, value: 1 }] })
            .conditions
        ).toEqual({ mode: 'all', rules: [{ field: 'a', operator: expected, value: 1 }] })
      })

      it('passes a canonical operator through and defaults a missing one to equals', () => {
        expect(
          (
            normalized('text', {
              dependencies: [{ field: 'a', operator: 'contains' }, { field: 'b' }],
            }).conditions as { rules: Array<{ operator: string }> }
          ).rules.map((rule) => rule.operator)
        ).toEqual(['contains', 'equals'])
      })

      it('keeps the all mode for a dependency list whose every rule was dropped', () => {
        expect(normalized('text', { dependencies: [{ operator: '=' }] }).conditions).toEqual({
          mode: 'all',
          rules: [],
        })
      })

      it('drops a legacy dependency that names no field, like conditions.rules', () => {
        expect(
          (normalized('text', { dependencies: [{ operator: '=' }] }).conditions as {
            rules: Array<{ field: string }>
          }).rules
        ).toEqual([])
      })

      it('always uses the all mode for legacy dependencies', () => {
        expect(
          (normalized('text', { dependencies: [{ field: 'a' }] }).conditions as { mode: string }).mode
        ).toBe('all')
      })

      it('is ignored once conditions are present', () => {
        expect(
          normalized('text', {
            conditions: { mode: 'any', rules: [] },
            dependencies: [{ field: 'a', operator: '=' }],
          }).conditions
        ).toEqual({ mode: 'any', rules: [] })
      })

      it('is ignored when dependencies is not an array', () => {
        expect(normalized('text', { dependencies: { field: 'a' } }).conditions).toBeNull()
      })
    })
  })
})

describe('normalizeSchema', () => {
  it('normalizes every entry and stamps its key', () => {
    const schema = normalizeSchema({ title: field('text'), when: field('date') })

    expect(Object.keys(schema)).toEqual(['title', 'when'])
    expect(schema.title.key).toBe('title')
    expect(schema.when.key).toBe('when')
  })

  it('tolerates a missing schema', () => {
    expect(normalizeSchema(null)).toEqual({})
    expect(normalizeSchema(undefined)).toEqual({})
  })
})

describe('isFieldVisible', () => {
  const controlled = (rules: unknown[], mode: 'all' | 'any' = 'all') =>
    field('text', { key: 'body', conditions: { mode, rules } }) as SchemaType & { key: string }

  const schema: SchemaMap = { status: field('text'), count: field('number') }

  const visible = (rules: unknown[], scope: Record<string, unknown>, mode: 'all' | 'any' = 'all') =>
    isFieldVisible(controlled(rules, mode), schema, scope)

  it('is visible without conditions', () => {
    expect(isFieldVisible(field('text', { key: 'body' }), schema, {})).toBe(true)
  })

  it('is visible when every rule was dropped for naming no field', () => {
    expect(visible([{ operator: 'equals', value: 'x' }], {})).toBe(true)
  })

  describe('operators', () => {
    it('equals compares loosely', () => {
      expect(visible([{ field: 'count', operator: 'equals', value: '1' }], { count: 1 })).toBe(true)
      expect(visible([{ field: 'status', operator: 'equals', value: 'live' }], { status: 'live' })).toBe(true)
      expect(visible([{ field: 'status', operator: 'equals', value: 'live' }], { status: 'draft' })).toBe(false)
    })

    it('equals does not treat a never-set controller as equal to an explicit null', () => {
      expect(visible([{ field: 'status', operator: 'equals', value: null }], {})).toBe(false)
    })

    it('equals with no expected value matches an unset controller', () => {
      expect(visible([{ field: 'status', operator: 'equals' }], {})).toBe(true)
      expect(visible([{ field: 'status', operator: 'equals' }], { status: 'live' })).toBe(false)
    })

    it('not_equals is the loose negation', () => {
      expect(visible([{ field: 'status', operator: 'not_equals', value: 'live' }], { status: 'draft' })).toBe(true)
      expect(visible([{ field: 'count', operator: 'not_equals', value: '1' }], { count: 1 })).toBe(false)
    })

    it('in matches membership of the expected list', () => {
      expect(visible([{ field: 'status', operator: 'in', value: ['a', 'b'] }], { status: 'b' })).toBe(true)
      expect(visible([{ field: 'status', operator: 'in', value: ['a'] }], { status: 'b' })).toBe(false)
    })

    it('in requires strict equality against the list entries', () => {
      expect(visible([{ field: 'count', operator: 'in', value: ['1'] }], { count: 1 })).toBe(false)
    })

    it('not_in matches absence from the expected list', () => {
      expect(visible([{ field: 'status', operator: 'not_in', value: ['a'] }], { status: 'b' })).toBe(true)
      expect(visible([{ field: 'status', operator: 'not_in', value: ['b'] }], { status: 'b' })).toBe(false)
    })

    it('hides the field for in but not for not_in when the expected value is not a list', () => {
      // Nothing is a member of a non-list, so `not_in` against one is vacuously
      // true — a malformed rule must not make the field disappear.
      expect(visible([{ field: 'status', operator: 'in', value: 'a' }], { status: 'a' })).toBe(false)
      expect(visible([{ field: 'status', operator: 'not_in', value: 'a' }], { status: 'b' })).toBe(true)
    })

    it.each([[null], [undefined], ['']])('is_empty matches %o', (value) => {
      expect(visible([{ field: 'status', operator: 'is_empty' }], { status: value })).toBe(true)
    })

    it('is_empty matches an empty array and a missing key', () => {
      expect(visible([{ field: 'status', operator: 'is_empty' }], { status: [] })).toBe(true)
      expect(visible([{ field: 'status', operator: 'is_empty' }], {})).toBe(true)
    })

    it('is_empty does not match 0 or false', () => {
      expect(visible([{ field: 'count', operator: 'is_empty' }], { count: 0 })).toBe(false)
      expect(visible([{ field: 'status', operator: 'is_empty' }], { status: false })).toBe(false)
    })

    it('is_not_empty is the exact inverse', () => {
      expect(visible([{ field: 'status', operator: 'is_not_empty' }], { status: 'x' })).toBe(true)
      expect(visible([{ field: 'status', operator: 'is_not_empty' }], { status: ['x'] })).toBe(true)
      expect(visible([{ field: 'status', operator: 'is_not_empty' }], { status: [] })).toBe(false)
      expect(visible([{ field: 'status', operator: 'is_not_empty' }], {})).toBe(false)
      expect(visible([{ field: 'count', operator: 'is_not_empty' }], { count: 0 })).toBe(true)
    })

    it.each([
      ['gt', 3, 2, true],
      ['gt', 2, 2, false],
      ['gte', 2, 2, true],
      ['lt', 1, 2, true],
      ['lt', 2, 2, false],
      ['lte', 2, 2, true],
    ])('%s compares %o against %o', (operator, actual, expected, result) => {
      expect(visible([{ field: 'count', operator, value: expected }], { count: actual })).toBe(result)
    })

    it('coerces numeric strings before comparing', () => {
      expect(visible([{ field: 'count', operator: 'gt', value: '2' }], { count: '10' })).toBe(true)
    })

    it('hides the field when a numeric comparison is not numeric', () => {
      expect(visible([{ field: 'status', operator: 'gt', value: 1 }], { status: 'abc' })).toBe(false)
      expect(visible([{ field: 'status', operator: 'lt', value: 1 }], { status: 'abc' })).toBe(false)
    })

    it('contains checks array membership', () => {
      expect(visible([{ field: 'status', operator: 'contains', value: 'a' }], { status: ['a', 'b'] })).toBe(true)
      expect(visible([{ field: 'status', operator: 'contains', value: 'c' }], { status: ['a'] })).toBe(false)
    })

    it('contains is case-insensitive on strings', () => {
      expect(visible([{ field: 'status', operator: 'contains', value: 'LIV' }], { status: 'live' })).toBe(true)
    })

    it('contains matches everything when the expected value is genuinely empty', () => {
      // `''` is a substring of everything; a rule with no needle is vacuously true.
      expect(visible([{ field: 'status', operator: 'contains', value: '' }], { status: 'live' })).toBe(true)
      expect(visible([{ field: 'status', operator: 'contains' }], {})).toBe(true)
    })

    it('contains sees a numeric zero on either side', () => {
      expect(visible([{ field: 'count', operator: 'contains', value: '0' }], { count: 0 })).toBe(true)
      expect(visible([{ field: 'count', operator: 'contains', value: 0 }], { count: 100 })).toBe(true)
      expect(visible([{ field: 'count', operator: 'contains', value: 0 }], { count: 12 })).toBe(false)
    })

    it('stays visible for an operator it does not know', () => {
      // A field must not silently disappear because the frontend is older than
      // the operator its schema uses.
      expect(visible([{ field: 'status', operator: 'starts_with', value: 'l' }], { status: 'live' })).toBe(true)
      expect(visible([{ field: 'status', operator: 'starts_with', value: 'z' }], { status: 'live' })).toBe(true)
    })
  })

  describe('modes', () => {
    const rules = [
      { field: 'status', operator: 'equals', value: 'live' },
      { field: 'count', operator: 'gt', value: 2 },
    ]

    it('all requires every rule', () => {
      expect(visible(rules, { status: 'live', count: 3 })).toBe(true)
      expect(visible(rules, { status: 'live', count: 1 })).toBe(false)
    })

    it('any requires a single rule', () => {
      expect(visible(rules, { status: 'live', count: 1 }, 'any')).toBe(true)
      expect(visible(rules, { status: 'draft', count: 1 }, 'any')).toBe(false)
    })

    it('any with no rules at all stays visible', () => {
      expect(visible([], {}, 'any')).toBe(true)
    })
  })

  describe('unknown controller fields', () => {
    it('treats an unreferenced field as an unset text field', () => {
      expect(visible([{ field: 'ghost', operator: 'is_empty' }], {})).toBe(true)
      expect(visible([{ field: 'ghost', operator: 'is_not_empty' }], {})).toBe(false)
    })

    it('still reads a value the schema never declared', () => {
      expect(visible([{ field: 'ghost', operator: 'equals', value: 'x' }], { ghost: 'x' })).toBe(true)
    })
  })

  describe('localized scopes', () => {
    const localizedSchema: SchemaMap = {
      status: field('text'),
      headline: field('text', { translatable: true }),
    }

    it('reads a translatable controller from the effective scope', () => {
      expect(
        isFieldVisible(
          controlled([{ field: 'headline', operator: 'equals', value: 'base' }]),
          localizedSchema,
          { headline: 'local' },
          { headline: 'base' }
        )
      ).toBe(true)
    })

    it('reads a non-translatable controller from the local scope when it has a value', () => {
      expect(
        isFieldVisible(
          controlled([{ field: 'status', operator: 'equals', value: 'local' }]),
          localizedSchema,
          { status: 'local' },
          { status: 'base' }
        )
      ).toBe(true)
    })

    it('falls back to the effective scope when the local scope omits the key entirely', () => {
      expect(
        isFieldVisible(
          controlled([{ field: 'status', operator: 'equals', value: 'base' }]),
          localizedSchema,
          {},
          { status: 'base' }
        )
      ).toBe(true)
    })

    it('keeps reading the local scope when the key is present but undefined', () => {
      // `undefined` in the local scope counts as absent, so the effective scope wins.
      expect(
        isFieldVisible(
          controlled([{ field: 'status', operator: 'is_empty' }]),
          localizedSchema,
          { status: undefined },
          { status: 'base' }
        )
      ).toBe(false)
    })

    it('evaluates against the local scope when there is no effective scope at all', () => {
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

      expect(
        isFieldVisible(
          controlled([{ field: 'status', operator: 'equals', value: 'live' }]),
          localizedSchema,
          { status: 'live' },
          null as unknown as Record<string, unknown>
        )
      ).toBe(true)
      // …and not by way of the catch-all, which would hide the real evaluation.
      expect(warn).not.toHaveBeenCalled()

      warn.mockRestore()
    })

    it('does not fall back when the effective scope lacks the key too', () => {
      expect(
        isFieldVisible(
          controlled([{ field: 'status', operator: 'is_empty' }]),
          localizedSchema,
          {},
          {}
        )
      ).toBe(true)
    })
  })

  it('stays visible and warns when evaluation blows up', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    expect(
      isFieldVisible(
        controlled([{ field: 'status', operator: 'equals', value: 'live' }]),
        null as unknown as SchemaMap,
        { status: 'live' }
      )
    ).toBe(true)
    expect(warn).toHaveBeenCalledWith(
      expect.stringContaining('failed to evaluate visibility for field "body"'),
      expect.anything()
    )

    warn.mockRestore()
  })
})

describe('useContentSchemaState', () => {
  let harness: Harness<ReturnType<typeof useContentSchemaState>> | undefined

  interface StateOptions {
    content?: Record<string, unknown> | null
    schema?: SchemaMap
    blocks?: unknown[]
    block?: { slug: string; name?: string }
    effective?: Record<string, unknown>
    ignoreAbsent?: boolean
  }

  const state = (options: StateOptions = {}) => {
    const content = ref(
      options.content === null
        ? null
        : ({
            id: 'c1',
            content: options.content ?? {},
            block_schema: options.schema ?? {},
            block: options.block,
          } as unknown as ContentResource)
    ) as Ref<ContentResource | null>
    const blocks = ref(options.blocks) as Ref<BlockResource[] | undefined>
    const effectiveContent = ref(options.effective) as Ref<Record<string, unknown> | undefined>

    harness = withSetup(() =>
      useContentSchemaState({
        content,
        blocks,
        effectiveContent: options.effective === undefined ? undefined : effectiveContent,
        ignoreAbsentNonTranslatableFields: options.ignoreAbsent,
      })
    )

    return { ...harness.result, content, blocks, effectiveContent }
  }

  /** Validation is debounced; force it before reading the result. */
  const errorsOf = (result: ReturnType<typeof useContentSchemaState>) => {
    result.validateAllForSubmit({ silent: true })
    return result.getClientErrors()
  }

  const errorsFor = (options: StateOptions) => errorsOf(state(options))

  const block = (slug: string, schema: SchemaMap) => ({ slug, name: slug, schema })

  afterEach(() => {
    harness?.unmount()
    harness = undefined
    vi.useRealTimers()
  })

  describe('blocksBySlug', () => {
    it('keys the given blocks by slug', () => {
      const { blocksBySlug } = state({ blocks: [block('hero', {}), block('cta', {})] })

      expect(Object.keys(blocksBySlug.value).sort()).toEqual(['cta', 'hero'])
    })

    it("adds the content's own block from its inlined schema", () => {
      const { blocksBySlug } = state({
        block: { slug: 'page', name: 'Page' },
        schema: { title: field('text') },
      })

      expect(blocksBySlug.value.page).toEqual({
        slug: 'page',
        name: 'Page',
        schema: { title: { type: 'text' } },
      })
    })

    it("prefers the content's inlined schema over the block list entry", () => {
      const { blocksBySlug } = state({
        blocks: [block('page', { stale: field('text') })],
        block: { slug: 'page' },
        schema: { fresh: field('text') },
      })

      expect(Object.keys(blocksBySlug.value.page.schema ?? {})).toEqual(['fresh'])
    })

    it('is empty without blocks or a content block', () => {
      expect(state().blocksBySlug.value).toEqual({})
    })
  })

  describe('sanitizedContent', () => {
    it('is empty without content', () => {
      expect(state({ content: null }).sanitizedContent.value).toEqual({})
    })

    it('keeps every visible field', () => {
      expect(
        state({
          content: { title: 'Hi', count: 0 },
          schema: { title: field('text'), count: field('number') },
        }).sanitizedContent.value
      ).toEqual({ title: 'Hi', count: 0 })
    })

    it('keeps values the schema no longer declares', () => {
      expect(
        state({ content: { legacy: 'keep' }, schema: { title: field('text') } }).sanitizedContent
          .value
      ).toEqual({ legacy: 'keep' })
    })

    it('drops a field whose condition does not hold', () => {
      expect(
        state({
          content: { mode: 'simple', advanced: 'gone' },
          schema: {
            mode: field('text'),
            advanced: field('text', {
              conditions: { mode: 'all', rules: [{ field: 'mode', operator: 'equals', value: 'pro' }] },
            }),
          },
        }).sanitizedContent.value
      ).toEqual({ mode: 'simple' })
    })

    it('keeps a field whose condition holds', () => {
      expect(
        state({
          content: { mode: 'pro', advanced: 'kept' },
          schema: {
            mode: field('text'),
            advanced: field('text', {
              conditions: { mode: 'all', rules: [{ field: 'mode', operator: 'equals', value: 'pro' }] },
            }),
          },
        }).sanitizedContent.value
      ).toEqual({ mode: 'pro', advanced: 'kept' })
    })

    it('cascades a pruned field into the conditions that depend on it', () => {
      // `b` is pruned away, so `c`'s `b is_empty` rule holds and `c` is revealed
      // — the chain collapses in one pass rather than reading b's stale value.
      expect(
        state({
          content: { a: 'no', b: 'yes', c: 'kept' },
          schema: {
            a: field('text'),
            b: field('text', {
              conditions: { mode: 'all', rules: [{ field: 'a', operator: 'equals', value: 'yes' }] },
            }),
            c: field('text', {
              conditions: { mode: 'all', rules: [{ field: 'b', operator: 'is_empty' }] },
            }),
          },
        }).sanitizedContent.value
      ).toEqual({ a: 'no', c: 'kept' })
    })

    it('collapses a chain whose downstream field is only visible while its controller lives', () => {
      expect(
        state({
          content: { a: 'yes', b: 'yes', c: 'gone' },
          schema: {
            a: field('text'),
            b: field('text', {
              conditions: { mode: 'all', rules: [{ field: 'a', operator: 'equals', value: 'no' }] },
            }),
            c: field('text', {
              conditions: { mode: 'all', rules: [{ field: 'b', operator: 'is_not_empty' }] },
            }),
          },
        }).sanitizedContent.value
      ).toEqual({ a: 'yes' })
    })

    it('does not alias the source content', () => {
      const { sanitizedContent, content } = state({
        content: { nested: { title: 'Hi' } },
        schema: { nested: field('meta') },
      })

      ;(sanitizedContent.value.nested as { title: string }).title = 'changed'

      expect(
        ((content.value?.content ?? {}) as { nested: { title: string } }).nested.title
      ).toBe('Hi')
    })

    describe('nested blocks', () => {
      const schema: SchemaMap = { body: field('blocks') }
      const blocks = [
        block('hero', {
          headline: field('text'),
          sub: field('text', {
            conditions: { mode: 'all', rules: [{ field: 'headline', operator: 'is_not_empty' }] },
          }),
        }),
      ]

      it('prunes hidden fields inside a block item', () => {
        expect(
          state({
            content: { body: [{ id: 'b1', block: 'hero', headline: '', sub: 'gone' }] },
            schema,
            blocks,
          }).sanitizedContent.value
        ).toEqual({ body: [{ id: 'b1', block: 'hero', headline: '' }] })
      })

      it('keeps a block item whose slug is unknown', () => {
        expect(
          state({
            content: { body: [{ id: 'b1', block: 'missing', anything: 1 }] },
            schema,
            blocks,
          }).sanitizedContent.value
        ).toEqual({ body: [{ id: 'b1', block: 'missing', anything: 1 }] })
      })

      it('leaves non-object block entries alone', () => {
        expect(
          state({ content: { body: ['plain', null, 3] }, schema, blocks }).sanitizedContent.value
        ).toEqual({ body: ['plain', null, 3] })
      })

      it('leaves a non-array blocks value alone', () => {
        expect(
          state({ content: { body: 'oops' }, schema, blocks }).sanitizedContent.value
        ).toEqual({ body: 'oops' })
      })

      it('recurses through blocks inside blocks', () => {
        expect(
          state({
            content: {
              body: [{ id: 's', block: 'section', items: [{ id: 'c', block: 'hero', headline: '', sub: 'gone' }] }],
            },
            schema,
            blocks: [block('section', { items: field('blocks') }), ...blocks],
          }).sanitizedContent.value
        ).toEqual({
          body: [{ id: 's', block: 'section', items: [{ id: 'c', block: 'hero', headline: '' }] }],
        })
      })
    })

    describe('ignoreAbsentNonTranslatableFields', () => {
      const schema: SchemaMap = {
        title: field('text', { translatable: true }),
        slug: field('text'),
        body: field('blocks'),
      }

      it('drops every non-translatable field that is not a blocks field', () => {
        expect(
          state({
            content: { title: 'Titel', slug: 'home', body: [] },
            schema,
            effective: { title: 'Title', slug: 'home' },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({ title: 'Titel', body: [] })
      })

      it('drops a translatable field the source no longer has a value for', () => {
        expect(
          state({
            content: { title: 'Orphan' },
            schema,
            effective: { title: null },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({})
      })

      it('keeps a translatable field whose source value is falsy but present', () => {
        expect(
          state({
            content: { title: 'Titel' },
            schema,
            effective: { title: '' },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({ title: 'Titel' })
      })

      it('drops block items the source does not have', () => {
        expect(
          state({
            content: { body: [{ id: 'b1', block: 'hero' }, { id: 'ghost', block: 'hero' }] },
            schema,
            blocks: [block('hero', {})],
            effective: { body: [{ id: 'b1', block: 'hero' }] },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({ body: [{ id: 'b1', block: 'hero' }] })
      })

      it('keeps an unstamped block item and copies the id and slug from the source', () => {
        expect(
          state({
            content: { body: [{ headline: 'Titel' }] },
            schema,
            blocks: [block('hero', { headline: field('text', { translatable: true }) })],
            effective: { body: [{ id: 'b1', block: 'hero', headline: 'Title' }] },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({ body: [{ id: 'b1', block: 'hero', headline: 'Titel' }] })
      })

      it('keeps a translatable field whose condition depends on a non-translatable controller', () => {
        // The controller is stripped from the translation but still drives the
        // condition from the source — only condition-hidden fields cascade.
        expect(
          state({
            content: { headline: 'Titel' },
            schema: {
              mode: field('text'),
              headline: field('text', {
                translatable: true,
                conditions: {
                  mode: 'all',
                  rules: [{ field: 'mode', operator: 'equals', value: 'pro' }],
                },
              }),
            },
            effective: { mode: 'pro', headline: 'Headline' },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({ headline: 'Titel' })
      })

      it('keeps every source block item when none of them carries an id', () => {
        expect(
          state({
            content: { body: [{ block: 'hero' }, { block: 'hero' }] },
            schema,
            blocks: [block('hero', {})],
            effective: { body: [{ block: 'hero' }] },
            ignoreAbsent: true,
          }).sanitizedContent.value
        ).toEqual({ body: [{ block: 'hero' }, { block: 'hero' }] })
      })
    })

    it('falls back to the raw content and warns when pruning blows up', () => {
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
      // markRaw keeps the watcher's deep traversal away from the throwing
      // getter, so the failure happens inside pruneScope where it is caught.
      const brokenSchema = markRaw({
        get bad(): SchemaType {
          throw new Error('boom')
        },
      }) as unknown as SchemaMap
      const content = ref({
        id: 'c1',
        content: { title: 'Hi' },
        block_schema: brokenSchema,
      } as unknown as ContentResource) as Ref<ContentResource | null>

      harness = withSetup(() =>
        useContentSchemaState({ content, blocks: ref(undefined) as Ref<BlockResource[] | undefined> })
      )

      expect(harness.result.sanitizedContent.value).toEqual({ title: 'Hi' })
      expect(warn).toHaveBeenCalledWith(
        expect.stringContaining('failed to sanitize content'),
        expect.anything()
      )

      warn.mockRestore()
    })
  })

  describe('client validation', () => {
    it('has no errors for a valid document', () => {
      expect(
        errorsFor({ content: { title: 'Hi' }, schema: { title: field('text', { required: true }) } })
      ).toEqual({})
    })

    it('reports a required field by its name, under a content-prefixed path', () => {
      expect(
        errorsFor({
          content: { title: '' },
          schema: { title: field('text', { required: true, name: 'Headline' }) },
        })
      ).toEqual({ 'content.title': ['Headline is required.'] })
    })

    it('names a required field after its key when it has no name', () => {
      expect(
        errorsFor({ content: {}, schema: { title: field('text', { required: true }) } })
      ).toEqual({ 'content.title': ['title is required.'] })
    })

    it.each([[null], [undefined], ['']])('treats %o as missing for a required field', (value) => {
      expect(
        errorsFor({ content: { title: value }, schema: { title: field('text', { required: true }) } })
      ).toHaveProperty('content.title')
    })

    it('accepts 0 and false for a required field', () => {
      expect(
        errorsFor({
          content: { count: 0, flag: false },
          schema: {
            count: field('number', { required: true }),
            flag: field('boolean', { required: true }),
          },
        })
      ).toEqual({})
    })

    it('skips a field its condition hides', () => {
      expect(
        errorsFor({
          content: { mode: 'simple' },
          schema: {
            mode: field('text'),
            advanced: field('text', {
              required: true,
              conditions: { mode: 'all', rules: [{ field: 'mode', operator: 'equals', value: 'pro' }] },
            }),
          },
        })
      ).toEqual({})
    })

    it('validates the local value rather than the source document', () => {
      // Visibility is decided from the pruned local scope, so the values have to
      // come from there too — otherwise a cleared field validates as filled.
      expect(
        errorsFor({
          content: { title: '' },
          schema: { title: field('text', { required: true }) },
          effective: { title: 'Source' },
        })
      ).toEqual({ 'content.title': ['title is required.'] })
    })

    it('has no errors without content at all', () => {
      expect(errorsFor({ content: null })).toEqual({})
    })

    describe('text lengths and patterns', () => {
      const schema = (validation: Record<string, unknown>): SchemaMap => ({
        title: field('text', { validation }),
      })

      it('enforces min_length and max_length', () => {
        expect(errorsFor({ content: { title: 'a' }, schema: schema({ min_length: 3 }) })).toEqual({
          'content.title': ['title must be at least 3 characters.'],
        })
        expect(errorsFor({ content: { title: 'abcd' }, schema: schema({ max_length: 3 }) })).toEqual({
          'content.title': ['title may not be greater than 3 characters.'],
        })
      })

      it.each(['textarea', 'markdown', 'richtext'])('measures a %s field too', (type) => {
        expect(
          errorsFor({
            content: { title: 'ab' },
            schema: { title: field(type, { validation: { min_length: 3 } }) },
          })
        ).toHaveProperty('content.title')
      })

      it('measures a non-string richtext value by its JSON length', () => {
        expect(
          errorsFor({
            content: { title: { type: 'doc' } },
            schema: { title: field('richtext', { validation: { max_length: 3 } }) },
          })
        ).toHaveProperty('content.title')
      })

      it('accepts a bare regular expression', () => {
        expect(errorsFor({ content: { title: 'abc' }, schema: schema({ pattern: '^a' }) })).toEqual({})
        expect(errorsFor({ content: { title: 'bbc' }, schema: schema({ pattern: '^a' }) })).toEqual({
          'content.title': ['title has an invalid format.'],
        })
      })

      it('accepts a PHP-delimited pattern with flags', () => {
        expect(errorsFor({ content: { title: 'ABC' }, schema: schema({ pattern: '/^a/i' }) })).toEqual({})
        expect(errorsFor({ content: { title: 'ABC' }, schema: schema({ pattern: '/^a/' }) })).toEqual({
          'content.title': ['title has an invalid format.'],
        })
      })

      it('ignores an unparseable pattern and leaves it to the backend', () => {
        expect(errorsFor({ content: { title: 'x' }, schema: schema({ pattern: '([' }) })).toEqual({})
      })
    })

    describe('numbers', () => {
      it('enforces the bounds', () => {
        expect(
          errorsFor({ content: { n: 1 }, schema: { n: field('number', { validation: { min: 2 } }) } })
        ).toEqual({ 'content.n': ['n must be at least 2.'] })
        expect(
          errorsFor({ content: { n: 5 }, schema: { n: field('number', { validation: { max: 2 } }) } })
        ).toEqual({ 'content.n': ['n may not be greater than 2.'] })
      })

      it('accepts a bound of 0', () => {
        expect(
          errorsFor({ content: { n: -1 }, schema: { n: field('number', { validation: { min: 0 } }) } })
        ).toHaveProperty('content.n')
      })

      it('enforces a field-level min lifted into validation', () => {
        expect(errorsFor({ content: { n: 1 }, schema: { n: field('number', { min: 2 }) } })).toEqual({
          'content.n': ['n must be at least 2.'],
        })
      })

      it('rejects a non-numeric value', () => {
        expect(errorsFor({ content: { n: 'abc' }, schema: { n: field('number') } })).toEqual({
          'content.n': ['n must be a number.'],
        })
      })

      it('accepts a numeric string', () => {
        expect(
          errorsFor({ content: { n: '3' }, schema: { n: field('number', { validation: { max: 5 } }) } })
        ).toEqual({})
      })
    })

    describe('options', () => {
      it('rejects a value outside the self-sourced options', () => {
        expect(
          errorsFor({
            content: { s: 'nope' },
            schema: { s: field('option', { options: [{ name: 'A', value: 'a' }] }) },
          })
        ).toEqual({ 'content.s': ['s must use an allowed option.'] })
      })

      it('accepts a value inside the options', () => {
        expect(
          errorsFor({
            content: { s: 'a' },
            schema: { s: field('option', { options: [{ value: 'a' }] }) },
          })
        ).toEqual({})
      })

      it('accepts anything when the field declares no options', () => {
        expect(errorsFor({ content: { s: 'anything' }, schema: { s: field('option') } })).toEqual({})
      })

      it('honours an explicit allowed_values list on a self-sourced field', () => {
        // normalizeSchemaField already prefers a declared allow-list over the
        // options, and validation reads the same list.
        expect(
          errorsFor({
            content: { s: 'x' },
            schema: {
              s: field('option', { options: [{ value: 'a' }], validation: { allowed_values: ['x'] } }),
            },
          })
        ).toEqual({})
      })

      it('accepts an option value of 0', () => {
        expect(
          errorsFor({
            content: { s: 0 },
            schema: { s: field('option', { options: [{ name: 'Zero', value: 0 }] }) },
          })
        ).toEqual({})
      })

      it('uses allowed_values for a datasource-sourced field', () => {
        const schema: SchemaMap = {
          s: field('option', { source: 'datasource', validation: { allowed_values: ['a'] } }),
        }

        expect(errorsFor({ content: { s: 'a' }, schema })).toEqual({})
        expect(errorsFor({ content: { s: 'b' }, schema })).toHaveProperty('content.s')
      })

      it('accepts any datasource value when nothing is allow-listed', () => {
        expect(
          errorsFor({
            content: { s: 'anything' },
            schema: { s: field('option', { source: 'datasource' }) },
          })
        ).toEqual({})
      })

      it('requires an options field to be a list, reporting it once', () => {
        expect(errorsFor({ content: { s: 'a' }, schema: { s: field('options') } })).toEqual({
          'content.s': ['s must be a list.'],
        })
      })

      it('rejects entries outside the allowed options', () => {
        expect(
          errorsFor({
            content: { s: ['a', 'zz'] },
            schema: { s: field('options', { options: [{ value: 'a' }] }) },
          })
        ).toEqual({ 'content.s': ['s must only use allowed options.'] })
      })

      it('accepts a list whose entries are all allowed', () => {
        expect(
          errorsFor({
            content: { s: ['a'] },
            schema: { s: field('options', { options: [{ value: 'a' }, { value: 'b' }] }) },
          })
        ).toEqual({})
      })
    })

    describe('dates', () => {
      it('rejects an unparseable date', () => {
        expect(errorsFor({ content: { d: 'not-a-date' }, schema: { d: field('date') } })).toEqual({
          'content.d': ['d must be a valid date.'],
        })
      })

      it('enforces the bounds', () => {
        expect(
          errorsFor({
            content: { d: '2026-01-01' },
            schema: { d: field('date', { validation: { min: '2026-06-01' } }) },
          })
        ).toEqual({ 'content.d': ['d must be on or after 2026-06-01.'] })
        expect(
          errorsFor({
            content: { d: '2026-12-01' },
            schema: { d: field('date', { validation: { max: '2026-06-01' } }) },
          })
        ).toEqual({ 'content.d': ['d must be on or before 2026-06-01.'] })
      })

      it('accepts a date inside the bounds', () => {
        expect(
          errorsFor({
            content: { d: '2026-06-15' },
            schema: { d: field('date', { validation: { min: '2026-06-01', max: '2026-07-01' } }) },
          })
        ).toEqual({})
      })

      it('ignores a non-string date value', () => {
        expect(errorsFor({ content: { d: 12345 }, schema: { d: field('date') } })).toEqual({})
      })
    })

    describe('lists', () => {
      it.each(['options', 'multi_assets', 'references', 'blocks'])(
        'requires a %s value to be a list',
        (type) => {
          expect(errorsFor({ content: { l: 'nope' }, schema: { l: field(type) } })).toHaveProperty(
            'content.l'
          )
        }
      )

      it('enforces min_items and max_items', () => {
        expect(
          errorsFor({
            content: { l: [] },
            schema: { l: field('references', { validation: { min_items: 1 } }) },
          })
        ).toEqual({ 'content.l': ['l must contain at least 1 items.'] })
        expect(
          errorsFor({
            content: { l: ['a', 'b'] },
            schema: { l: field('references', { validation: { max_items: 1 } }) },
          })
        ).toEqual({ 'content.l': ['l may not contain more than 1 items.'] })
      })

      it('accepts a field-level min and max as item bounds', () => {
        expect(
          errorsFor({ content: { l: [] }, schema: { l: field('references', { min: 2 }) } })
        ).toEqual({ 'content.l': ['l must contain at least 2 items.'] })
      })

      it('treats an empty-string bound as absent', () => {
        expect(
          errorsFor({
            content: { l: [] },
            schema: { l: field('references', { validation: { min_items: '' } }) },
          })
        ).toEqual({})
      })

      it('skips the bounds entirely for an empty value', () => {
        // An empty (null) list is "empty" and short-circuits before the bounds.
        expect(
          errorsFor({
            content: { l: null },
            schema: { l: field('references', { validation: { min_items: 1 } }) },
          })
        ).toEqual({})
      })
    })

    describe('tables', () => {
      const columns = [
        { key: 'name', label: 'Name', type: 'text' },
        { key: 'qty', label: 'Qty', type: 'number' },
        { key: 'live', label: 'Live', type: 'boolean' },
      ]
      const table = (extra: Record<string, unknown> = {}) =>
        ({ t: field('table', { columns, ...extra }) }) as SchemaMap
      const value = (rows: unknown[], header: unknown = { name: 'Name' }) => ({ header, rows })

      it('accepts a well-formed table', () => {
        expect(
          errorsFor({
            content: { t: value([{ id: 'r1', cells: { name: 'A', qty: 1, live: true } }]) },
            schema: table(),
          })
        ).toEqual({})
      })

      it('rejects a non-object value', () => {
        expect(errorsFor({ content: { t: [] }, schema: table() })).toEqual({
          'content.t': ['t must be a table object.'],
        })
      })

      it('rejects a non-object header and a non-array rows', () => {
        expect(errorsFor({ content: { t: { header: 'x', rows: 'y' } }, schema: table() })).toEqual({
          'content.t': [
            't must contain a valid header object.',
            't must contain a valid rows array.',
          ],
        })
      })

      it('rejects a header entry for an unknown column or a non-string label', () => {
        expect(errorsFor({ content: { t: value([], { gone: 'x' }) }, schema: table() })).toEqual({
          'content.t': ['t contains an invalid header value.'],
        })
        expect(errorsFor({ content: { t: value([], { name: 7 }) }, schema: table() })).toEqual({
          'content.t': ['t contains an invalid header value.'],
        })
      })

      it('enforces the row count bounds', () => {
        expect(errorsFor({ content: { t: value([]) }, schema: table({ min: 1 }) })).toEqual({
          'content.t': ['t must contain at least 1 rows.'],
        })
        expect(
          errorsFor({
            content: { t: value([{ id: 'r1', cells: {} }, { id: 'r2', cells: {} }]) },
            schema: table({ max: 1 }),
          })
        ).toEqual({ 'content.t': ['t may not contain more than 1 rows.'] })
      })

      it('rejects a row that is not an object', () => {
        expect(errorsFor({ content: { t: value(['nope']) }, schema: table() })).toEqual({
          'content.t': ['t contains an invalid row.'],
        })
      })

      it('requires unique string row ids', () => {
        expect(
          errorsFor({
            content: { t: value([{ id: 'r1', cells: {} }, { id: 'r1', cells: {} }]) },
            schema: table(),
          })
        ).toEqual({ 'content.t': ['t rows must have unique ids.'] })
        expect(errorsFor({ content: { t: value([{ cells: {} }]) }, schema: table() })).toEqual({
          'content.t': ['t rows must have unique ids.'],
        })
      })

      it('requires the cells to be an object', () => {
        expect(errorsFor({ content: { t: value([{ id: 'r1', cells: [] }]) }, schema: table() })).toEqual(
          { 'content.t': ['t row cells must be an object.'] }
        )
      })

      it('rejects a cell for an unknown column', () => {
        expect(
          errorsFor({ content: { t: value([{ id: 'r1', cells: { gone: 'x' } }]) }, schema: table() })
        ).toEqual({ 'content.t': ['t contains a cell for an unknown column.'] })
      })

      it.each([
        [{ name: 7 }, 't text cells must be strings.'],
        [{ qty: 'x' }, 't number cells must be a number or null.'],
        [{ live: 'yes' }, 't boolean cells must be true or false.'],
      ])('rejects a mistyped cell %o', (cells, message) => {
        expect(errorsFor({ content: { t: value([{ id: 'r1', cells }]) }, schema: table() })).toEqual({
          'content.t': [message],
        })
      })

      it('accepts a null number cell but not a null text or boolean cell', () => {
        expect(
          errorsFor({ content: { t: value([{ id: 'r1', cells: { qty: null } }]) }, schema: table() })
        ).toEqual({})
        expect(
          errorsFor({ content: { t: value([{ id: 'r1', cells: { name: null } }]) }, schema: table() })
        ).toHaveProperty('content.t')
      })

      it('restricts an option cell to the column options', () => {
        const optionTable: SchemaMap = {
          t: field('table', {
            columns: [{ key: 's', label: 'S', type: 'option', options: [{ name: 'A', value: 'a' }] }],
          }),
        }

        expect(
          errorsFor({ content: { t: value([{ id: 'r1', cells: { s: 'a' } }], {}) }, schema: optionTable })
        ).toEqual({})
        expect(
          errorsFor({ content: { t: value([{ id: 'r1', cells: { s: 'b' } }], {}) }, schema: optionTable })
        ).toEqual({ 'content.t': ['t option cells must use an allowed option.'] })
        expect(
          errorsFor({ content: { t: value([{ id: 'r1', cells: { s: null } }], {}) }, schema: optionTable })
        ).toEqual({})
      })

      it('accepts any option cell string once the column is datasource-sourced', () => {
        expect(
          errorsFor({
            content: { t: value([{ id: 'r1', cells: { s: 'anything' } }], {}) },
            schema: {
              t: field('table', {
                columns: [{ key: 's', label: 'S', type: 'option', source: 'datasource' }],
              }),
            },
          })
        ).toEqual({})
      })

      it('collects every distinct problem under the same path', () => {
        expect(
          errorsFor({
            content: { t: value([{ id: 'r1', cells: { name: 1, qty: 'x' } }]) },
            schema: table(),
          })
        ).toEqual({
          'content.t': ['t text cells must be strings.', 't number cells must be a number or null.'],
        })
      })
    })

    describe('nested blocks', () => {
      const schema: SchemaMap = { body: field('blocks') }
      const blocks = [block('hero', { headline: field('text', { required: true }) })]

      it('reports a nested error under an indexed path', () => {
        expect(
          errorsFor({
            content: { body: [{ id: 'b1', block: 'hero', headline: '' }] },
            schema,
            blocks,
          })
        ).toEqual({ 'content.body.0.headline': ['headline is required.'] })
      })

      it('reports each failing item separately', () => {
        expect(
          Object.keys(
            errorsFor({
              content: {
                body: [
                  { id: 'b1', block: 'hero', headline: '' },
                  { id: 'b2', block: 'hero', headline: 'ok' },
                  { id: 'b3', block: 'hero', headline: '' },
                ],
              },
              schema,
              blocks,
            })
          )
        ).toEqual(['content.body.0.headline', 'content.body.2.headline'])
      })

      it('skips an item whose block is unknown', () => {
        expect(
          errorsFor({ content: { body: [{ id: 'b1', block: 'ghost' }] }, schema, blocks })
        ).toEqual({})
      })

      it('pairs a reordered translation block with its source item by id', () => {
        // Sanitization pairs by id, so validation has to as well — pairing by
        // index would blame the wrong block for the empty headline.
        expect(
          errorsFor({
            content: {
              body: [
                { id: 'b2', block: 'hero', headline: '' },
                { id: 'b1', block: 'hero', headline: 'Eins' },
              ],
            },
            schema,
            blocks: [block('hero', { headline: field('text', { translatable: true, required: true }) })],
            effective: {
              body: [
                { id: 'b1', block: 'hero', headline: 'One' },
                { id: 'b2', block: 'hero', headline: 'Two' },
              ],
            },
            ignoreAbsent: true,
          })
        ).toEqual({ 'content.body.0.headline': ['headline is required.'] })
      })

      it('validates through several levels', () => {
        expect(
          errorsFor({
            content: {
              body: [{ id: 's', block: 'section', items: [{ id: 'h', block: 'hero', headline: '' }] }],
            },
            schema,
            blocks: [block('section', { items: field('blocks') }), ...blocks],
          })
        ).toEqual({ 'content.body.0.items.0.headline': ['headline is required.'] })
      })
    })
  })

  describe('server errors', () => {
    it('are merged into the reported errors and win when reading a field', () => {
      const result = state({ content: { title: '' }, schema: { title: field('text', { required: true }) } })

      result.setServerErrors({ 'content.title': ['Server says no.'] })
      result.validateAllForSubmit()

      expect(result.getFieldError('content.title')).toBe('Server says no.')
      expect(result.validationSummary.value).toEqual({ isValid: false, issueCount: 1 })
    })

    it('merge with the client messages without duplicating them', () => {
      const result = state({ content: { title: '' }, schema: { title: field('text', { required: true }) } })

      result.setServerErrors({ 'content.title': ['title is required.', 'Server says no.'] })
      result.validateAllForSubmit()

      expect(result.getVisibleValidationEntries('content.title')[0].messages).toEqual([
        'title is required.',
        'Server says no.',
      ])
    })

    it('are cleared wholesale', () => {
      const result = state()

      result.setServerErrors({ 'content.title': ['nope'] })
      result.clearServerErrors()

      expect(result.getFieldError('content.title')).toBeNull()
    })

    it('drop the field entry as soon as the field is edited', () => {
      const result = state()

      result.setServerErrors({ 'content.title': ['nope'], 'content.slug': ['nope'] })
      result.markFieldDirty('content.title')

      expect(result.getFieldError('content.title')).toBeNull()
      expect(result.getFieldError('content.slug')).toBe('nope')
    })
  })

  describe('getFieldError and shouldShowFieldError', () => {
    const invalid = () =>
      state({ content: { title: '' }, schema: { title: field('text', { required: true }) } })

    it('is null for a path with no error', () => {
      expect(invalid().getFieldError('content.other')).toBeNull()
    })

    it('reports the first client message once validation has run', () => {
      const result = invalid()
      result.validateAllForSubmit({ silent: true })

      expect(result.getFieldError('content.title')).toBe('title is required.')
    })

    it('stays hidden until the field is dirty or a submit was attempted', () => {
      const result = invalid()
      result.validateAllForSubmit({ silent: true })

      expect(result.shouldShowFieldError('content.title')).toBe(false)

      result.markFieldDirty('content.title')

      expect(result.shouldShowFieldError('content.title')).toBe(true)
    })

    it('shows every error once a submit was attempted', () => {
      const result = invalid()
      result.validateAllForSubmit()

      expect(result.submitAttempted.value).toBe(true)
      expect(result.shouldShowFieldError('content.title')).toBe(true)
    })

    it('never shows an error for a path that has none', () => {
      const result = invalid()
      result.validateAllForSubmit()

      expect(result.shouldShowFieldError('content.other')).toBe(false)
    })
  })

  describe('getVisibleValidationEntries', () => {
    const twoErrors = () => {
      const result = state({
        content: { title: '', body: [{ id: 'b1', block: 'hero', headline: '' }] },
        schema: { title: field('text', { required: true }), body: field('blocks') },
        blocks: [block('hero', { headline: field('text', { required: true }) })],
      })
      result.validateAllForSubmit()
      return result
    }

    it('returns every entry sorted by path once a submit was attempted', () => {
      expect(twoErrors().getVisibleValidationEntries().map((entry) => entry.path)).toEqual([
        'content.body.0.headline',
        'content.title',
      ])
    })

    it('returns nothing while nothing is dirty and no submit was attempted', () => {
      const result = state({ content: { title: '' }, schema: { title: field('text', { required: true }) } })
      result.validateAllForSubmit({ silent: true })

      expect(result.getVisibleValidationEntries()).toEqual([])
    })

    it('returns only the dirty entries', () => {
      const result = state({
        content: { title: '', slug: '' },
        schema: {
          title: field('text', { required: true }),
          slug: field('text', { required: true }),
        },
      })
      result.validateAllForSubmit({ silent: true })
      result.markFieldDirty('content.slug')

      expect(result.getVisibleValidationEntries().map((entry) => entry.path)).toEqual([
        'content.slug',
      ])
    })

    it('filters by an exact path when the prefix does not end in a dot', () => {
      expect(twoErrors().getVisibleValidationEntries('content.title').map((e) => e.path)).toEqual([
        'content.title',
      ])
      expect(twoErrors().getVisibleValidationEntries('content.body')).toEqual([])
    })

    it('filters by subtree when the prefix ends in a dot', () => {
      expect(twoErrors().getVisibleValidationEntries('content.body.').map((e) => e.path)).toEqual([
        'content.body.0.headline',
      ])
    })
  })

  describe('getValidationIssueSignature', () => {
    it('lists path and first message per entry', () => {
      const result = state({ content: { title: '' }, schema: { title: field('text', { required: true }) } })
      result.validateAllForSubmit({ silent: true })

      expect(result.getValidationIssueSignature()).toBe('content.title:title is required.')
    })

    it('is empty while the document is valid', () => {
      const result = state({ content: { title: 'Hi' }, schema: { title: field('text') } })
      result.validateAllForSubmit({ silent: true })

      expect(result.getValidationIssueSignature()).toBe('')
    })

    it('changes when the failing field changes', () => {
      const result = state({
        content: { title: '', slug: '' },
        schema: {
          title: field('text', { required: true }),
          slug: field('text', { required: true }),
        },
      })
      result.validateAllForSubmit({ silent: true })
      const before = result.getValidationIssueSignature()

      result.setServerErrors({})
      const content = result as unknown as { content: Ref<ContentResource | null> }
      ;(content.content.value as ContentResource).content = { title: 'Hi', slug: '' }
      result.validateAllForSubmit({ silent: true })

      expect(result.getValidationIssueSignature()).not.toBe(before)
      expect(result.getValidationIssueSignature()).toBe('content.slug:slug is required.')
    })
  })

  describe('validateAllForSubmit', () => {
    it('is true for a valid document', () => {
      expect(
        state({ content: { title: 'Hi' }, schema: { title: field('text') } }).validateAllForSubmit()
      ).toBe(true)
    })

    it('is false for an invalid document and marks the submit attempt', () => {
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })

      expect(result.validateAllForSubmit()).toBe(false)
      expect(result.submitAttempted.value).toBe(true)
    })

    it('leaves the submit flag alone when silent', () => {
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })

      expect(result.validateAllForSubmit({ silent: true })).toBe(false)
      expect(result.submitAttempted.value).toBe(false)
    })

    it('ignores server errors when deciding whether a submit may proceed', () => {
      const result = state({ content: { title: 'Hi' }, schema: { title: field('text') } })
      result.setServerErrors({ 'content.title': ['Server says no.'] })

      expect(result.validateAllForSubmit()).toBe(true)
    })

    it('does not wait for the debounce', () => {
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })

      // Nothing has been validated yet — the watcher is debounced.
      expect(result.getClientErrors()).toEqual({})
      expect(result.validateAllForSubmit()).toBe(false)
      expect(result.getClientErrors()).toHaveProperty('content.title')
    })
  })

  describe('getFirstInvalidFieldPath', () => {
    it('is the alphabetically first failing path', () => {
      const result = state({
        content: { title: '', body: [{ id: 'b1', block: 'hero', headline: '' }] },
        schema: { title: field('text', { required: true }), body: field('blocks') },
        blocks: [block('hero', { headline: field('text', { required: true }) })],
      })
      result.validateAllForSubmit()

      expect(result.getFirstInvalidFieldPath()).toBe('content.body.0.headline')
    })

    it('is null for a valid document', () => {
      const result = state({ content: { title: 'Hi' }, schema: { title: field('text') } })
      result.validateAllForSubmit()

      expect(result.getFirstInvalidFieldPath()).toBeNull()
    })
  })

  describe('revealValidationState', () => {
    it('refreshes the debounced errors and raises the submit flag', async () => {
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })

      await result.revealValidationState()

      expect(result.submitAttempted.value).toBe(true)
      expect(result.getClientErrors()).toHaveProperty('content.title')
    })

    it('re-arms the flag so a repeat submit still triggers watchers', async () => {
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })

      await result.revealValidationState()
      await result.revealValidationState()

      expect(result.submitAttempted.value).toBe(true)
    })
  })

  describe('focusFirstInvalidField', () => {
    beforeEach(() => {
      Element.prototype.scrollIntoView = vi.fn()
      document.body.innerHTML = ''
    })

    const invalidState = () =>
      state({ content: {}, schema: { title: field('text', { required: true }) } })

    it('scrolls the field container into view and focuses its input', async () => {
      document.body.innerHTML = `
        <div data-field-path="content.title"><input id="title" /></div>
      `
      const result = invalidState()

      await result.focusFirstInvalidField()

      expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({
        behavior: 'smooth',
        block: 'center',
      })
      expect(document.activeElement?.id).toBe('title')
    })

    it('prefers the declared validation target', async () => {
      document.body.innerHTML = `
        <div data-field-path="content.title">
          <input id="decoy" />
          <div data-validation-target="true"><input id="real" /></div>
        </div>
      `

      await invalidState().focusFirstInvalidField()

      expect(document.activeElement?.id).toBe('real')
    })

    it('focuses a validation target that is itself focusable', async () => {
      document.body.innerHTML = `
        <div data-field-path="content.title">
          <input id="self" data-validation-target="true" />
        </div>
      `

      await invalidState().focusFirstInvalidField()

      expect(document.activeElement?.id).toBe('self')
    })

    it('skips elements taken out of the tab order', async () => {
      document.body.innerHTML = `
        <div data-field-path="content.title">
          <input id="skipped" tabindex="-1" />
          <input id="wanted" />
        </div>
      `

      await invalidState().focusFirstInvalidField()

      expect(document.activeElement?.id).toBe('wanted')
    })

    it('walks up to an ancestor container when the exact path is not rendered', async () => {
      document.body.innerHTML = `
        <div data-field-path="content.body.0"><input id="nested" /></div>
      `
      const result = state({
        content: { body: [{ id: 'b1', block: 'hero', headline: '' }] },
        schema: { body: field('blocks') },
        blocks: [block('hero', { headline: field('text', { required: true }) })],
      })

      await result.focusFirstInvalidField()

      expect(document.activeElement?.id).toBe('nested')
    })

    it('refreshes the debounced errors before deciding what to focus', async () => {
      // Submitting inside the 300ms debounce window must still focus the field.
      document.body.innerHTML = '<div data-field-path="content.title"><input id="title" /></div>'
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })

      await result.focusFirstInvalidField()

      expect(document.activeElement?.id).toBe('title')
      expect(Element.prototype.scrollIntoView).toHaveBeenCalled()
    })

    it('does nothing when the container is not in the document', async () => {
      await invalidState().focusFirstInvalidField()

      expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled()
    })

    it('does nothing for a valid document', async () => {
      document.body.innerHTML = '<div data-field-path="content.title"><input id="title" /></div>'
      const result = state({ content: { title: 'Hi' }, schema: { title: field('text') } })

      await result.focusFirstInvalidField()

      expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled()
      expect(result.submitAttempted.value).toBe(false)
    })
  })

  describe('resetValidationState', () => {
    it('clears the dirty flags, the submit attempt and the server errors', () => {
      const result = state({ content: {}, schema: { title: field('text', { required: true }) } })
      result.setServerErrors({ 'content.title': ['Server says no.'] })
      result.markFieldDirty('content.other')
      result.validateAllForSubmit()

      result.resetValidationState()

      expect(result.submitAttempted.value).toBe(false)
      expect(result.serverErrors.value).toEqual({})
      expect(result.shouldShowFieldError('content.title')).toBe(false)
      // Client errors are recomputed rather than dropped.
      expect(result.getClientErrors()).toHaveProperty('content.title')
    })
  })

  describe('debounced revalidation', () => {
    it('validates 300ms after an edit rather than on every keystroke', async () => {
      vi.useFakeTimers()
      const result = state({ content: { title: '' }, schema: { title: field('text', { required: true }) } })

      await vi.advanceTimersByTimeAsync(300)
      await nextTick()

      expect(result.getClientErrors()).toHaveProperty('content.title')

      ;(result.content.value as ContentResource).content = { title: 'Hi' }
      await nextTick()

      // Still stale immediately after the edit…
      expect(result.getClientErrors()).toHaveProperty('content.title')

      await vi.advanceTimersByTimeAsync(300)
      await nextTick()

      expect(result.getClientErrors()).toEqual({})
    })
  })
})
