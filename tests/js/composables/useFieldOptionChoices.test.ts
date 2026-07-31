import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const getEntries = vi.fn()

vi.mock('~/api', () => ({
  api: {
    forSpace: () => ({ dataSources: { getEntries } }),
  },
}))

const { normalizeFieldOptionChoices, useFieldOptionChoices } = await import(
  '~/composables/useFieldOptionChoices'
)

const SPACE = 'space-1'
const DATA_SOURCE = 'ds-1'

type OptionField = Parameters<typeof normalizeFieldOptionChoices>[0]

const selfField = (options: unknown[]) =>
  ({ type: 'option', source: 'self', options }) as unknown as OptionField

const entry = (key: string, value: unknown, is_active = true) => ({ key, value, is_active })

/** The composable reads `response.data`, not `response.data.data`. */
const entriesResponse = (entries: unknown[]) => ({ data: entries })

/** The params the composable always sends, and therefore the cache key it reads. */
const entriesKey = () =>
  queryKeys.dataEntries(SPACE, DATA_SOURCE).list({ per_page: 1000, sort: '+value' })

describe('normalizeFieldOptionChoices', () => {
  it('maps name to label and value to value', () => {
    expect(normalizeFieldOptionChoices(selfField([{ name: 'Live', value: 'live' }]))).toEqual([
      { label: 'Live', value: 'live' },
    ])
  })

  it('falls back to the value when the name is missing or blank', () => {
    expect(
      normalizeFieldOptionChoices(
        selfField([{ value: 'live' }, { name: '   ', value: 'draft' }, { name: null, value: 'x' }])
      )
    ).toEqual([
      { label: 'live', value: 'live' },
      { label: 'draft', value: 'draft' },
      { label: 'x', value: 'x' },
    ])
  })

  it('trims both the label and the value', () => {
    expect(normalizeFieldOptionChoices(selfField([{ name: ' Live ', value: ' live ' }]))).toEqual([
      { label: 'Live', value: 'live' },
    ])
  })

  it('stringifies a numeric name and value', () => {
    expect(normalizeFieldOptionChoices(selfField([{ name: 7, value: 42 }]))).toEqual([
      { label: '7', value: '42' },
    ])
  })

  it('drops options without a usable value', () => {
    expect(
      normalizeFieldOptionChoices(
        selfField([{ name: 'A', value: '' }, { name: 'B', value: '  ' }, { name: 'C' }, null])
      )
    ).toEqual([])
  })

  it('keeps an option whose value is 0', () => {
    // Only null/undefined make an option unusable — a numeric list starting at
    // zero must keep that choice selectable.
    expect(normalizeFieldOptionChoices(selfField([{ name: 'Zero', value: 0 }]))).toEqual([
      { label: 'Zero', value: '0' },
    ])
    expect(normalizeFieldOptionChoices(selfField([{ value: 0 }]))).toEqual([
      { label: '0', value: '0' },
    ])
  })

  it('returns nothing for a missing field or a field without options', () => {
    expect(normalizeFieldOptionChoices(null)).toEqual([])
    expect(normalizeFieldOptionChoices(undefined)).toEqual([])
    expect(normalizeFieldOptionChoices({ type: 'option' } as unknown as OptionField)).toEqual([])
  })

  describe('shaped names', () => {
    it('uses the first non-empty scalar field of an object name', () => {
      expect(
        normalizeFieldOptionChoices(
          selfField([{ name: { title: 'Widget', sku: 'W-1' }, value: 'w' }])
        )
      ).toEqual([{ label: 'Widget', value: 'w' }])
    })

    it('skips blank strings and non-scalars when picking the label', () => {
      expect(
        normalizeFieldOptionChoices(
          selfField([{ name: { a: '  ', b: null, c: ['x'], d: 'Real' }, value: 'w' }])
        )
      ).toEqual([{ label: 'Real', value: 'w' }])
    })

    it('accepts a numeric shape field, including 0', () => {
      expect(normalizeFieldOptionChoices(selfField([{ name: { count: 0 }, value: 'w' }]))).toEqual([
        { label: '0', value: 'w' },
      ])
    })

    it('falls back to the value when the shape holds nothing scalar', () => {
      expect(normalizeFieldOptionChoices(selfField([{ name: {}, value: 'w' }]))).toEqual([
        { label: 'w', value: 'w' },
      ])
    })
  })
})

describe('useFieldOptionChoices', () => {
  let harness: Harness<ReturnType<typeof useFieldOptionChoices>> | undefined

  const setup = (
    field: unknown,
    seed: Array<[readonly unknown[], unknown]> = []
  ) => {
    harness = withSetup(() => useFieldOptionChoices(SPACE, field as OptionField), { seed })
    return harness.result
  }

  beforeEach(() => {
    getEntries.mockReset()
    // An unmocked call would mean the composable queried when it should not.
    getEntries.mockImplementation(() => new Promise(() => {}))
  })

  afterEach(() => {
    harness?.unmount()
    harness = undefined
  })

  describe('self-sourced fields', () => {
    it('resolves the choices from the field itself and never queries', () => {
      const { choices, isLoading, isEmpty } = setup(selfField([{ name: 'Live', value: 'live' }]))

      expect(choices.value).toEqual([{ label: 'Live', value: 'live' }])
      expect(isLoading.value).toBe(false)
      expect(isEmpty.value).toBe(false)
      expect(getEntries).not.toHaveBeenCalled()
    })

    it('is empty when the field declares no usable option', () => {
      expect(setup(selfField([{ value: '' }])).isEmpty.value).toBe(true)
    })

    it('treats a field with no source at all as self-sourced', () => {
      expect(setup({ type: 'option', options: [{ name: 'A', value: 'a' }] }).choices.value).toEqual([
        { label: 'A', value: 'a' },
      ])
    })

    it('ignores a data_source_id while the source is self', () => {
      const { choices } = setup({
        type: 'option',
        source: 'self',
        data_source_id: DATA_SOURCE,
        options: [{ name: 'A', value: 'a' }],
      })

      expect(choices.value).toEqual([{ label: 'A', value: 'a' }])
      expect(getEntries).not.toHaveBeenCalled()
    })
  })

  describe('datasource-sourced fields', () => {
    const dataSourceField = { type: 'option', source: 'datasource', data_source_id: DATA_SOURCE }

    it('maps the active entries to choices keyed by entry key', () => {
      const { choices, isLoading, isEmpty } = setup(dataSourceField, [
        [entriesKey(), entriesResponse([entry('live', 'Live'), entry('draft', 'Draft')])],
      ])

      expect(choices.value).toEqual([
        { label: 'Live', value: 'live' },
        { label: 'Draft', value: 'draft' },
      ])
      expect(isLoading.value).toBe(false)
      expect(isEmpty.value).toBe(false)
    })

    it('drops inactive entries', () => {
      expect(
        setup(dataSourceField, [
          [entriesKey(), entriesResponse([entry('live', 'Live'), entry('gone', 'Gone', false)])],
        ]).choices.value
      ).toEqual([{ label: 'Live', value: 'live' }])
    })

    it('labels a shaped entry value from its first scalar field', () => {
      expect(
        setup(dataSourceField, [
          [entriesKey(), entriesResponse([entry('w', { title: 'Widget', sku: 'W-1' })])],
        ]).choices.value
      ).toEqual([{ label: 'Widget', value: 'w' }])
    })

    it('falls back to the entry key when the value is blank', () => {
      expect(
        setup(dataSourceField, [[entriesKey(), entriesResponse([entry('w', '  ')])]]).choices.value
      ).toEqual([{ label: 'w', value: 'w' }])
    })

    it('drops an entry whose key is empty, exactly like a self-sourced option', () => {
      expect(
        setup(dataSourceField, [[entriesKey(), entriesResponse([entry('', 'Live')])]]).choices.value
      ).toEqual([])
    })

    it('keeps an entry keyed 0', () => {
      expect(
        setup(dataSourceField, [[entriesKey(), entriesResponse([entry('0', 'Zero')])]]).choices.value
      ).toEqual([{ label: 'Zero', value: '0' }])
    })

    it('reports loading while the entries are in flight', () => {
      const { isLoading, isEmpty, choices } = setup(dataSourceField)

      expect(isLoading.value).toBe(true)
      // Not "empty" yet — nothing has been decided.
      expect(isEmpty.value).toBe(false)
      expect(choices.value).toEqual([])
    })

    it('does not query without a data_source_id and reports itself empty', () => {
      const { choices, isLoading, isEmpty } = setup({
        type: 'option',
        source: 'datasource',
        data_source_id: null,
      })

      expect(choices.value).toEqual([])
      expect(isLoading.value).toBe(false)
      expect(isEmpty.value).toBe(true)
      expect(getEntries).not.toHaveBeenCalled()
    })

    it('ignores the field-local options once the source is a datasource', () => {
      expect(
        setup(
          {
            type: 'option',
            source: 'datasource',
            data_source_id: DATA_SOURCE,
            options: [{ name: 'Local', value: 'local' }],
          },
          [[entriesKey(), entriesResponse([])]]
        ).choices.value
      ).toEqual([])
    })
  })

  it('follows a field that switches from self to a datasource', async () => {
    const field = ref<Record<string, unknown>>({
      type: 'option',
      source: 'self',
      options: [{ name: 'Local', value: 'local' }],
    })

    harness = withSetup(() => useFieldOptionChoices(SPACE, field as never), {
      seed: [[entriesKey(), entriesResponse([entry('remote', 'Remote')])]],
    })

    expect(harness.result.choices.value).toEqual([{ label: 'Local', value: 'local' }])

    field.value = { type: 'option', source: 'datasource', data_source_id: DATA_SOURCE }
    // The query key is reactive, but the observer re-reads the cache on the next tick.
    await nextTick()

    expect(harness.result.choices.value).toEqual([{ label: 'Remote', value: 'remote' }])
  })
})
