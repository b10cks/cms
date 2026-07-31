import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const copyToSystemClipboard = vi.fn<(value?: string) => Promise<void>>()

// The system clipboard is the transport boundary here; localStorage stays real
// because it is the documented source of truth.
vi.mock('@vueuse/core', async () => {
  const actual = await vi.importActual<typeof import('@vueuse/core')>('@vueuse/core')

  return {
    ...actual,
    useClipboard: () =>
      ({
        copy: copyToSystemClipboard,
        copied: ref(false),
        text: ref(''),
        isSupported: ref(true),
      }) as unknown as ReturnType<typeof actual.useClipboard>,
  }
})

const STORAGE_KEY = 'schema-field-clipboard'

// This runner exposes no localStorage, and useStorage silently degrades to a
// detached ref without one — which would leave the persisted payload (and its
// parse guards) untested. A plain-object backend is enough; vueuse falls back to
// its own same-document sync event when the backend is not a real `Storage`.
const backend = new Map<string, string>()
const storageArea = {
  getItem: (key: string) => backend.get(key) ?? null,
  setItem: (key: string, value: string) => void backend.set(key, String(value)),
  removeItem: (key: string) => void backend.delete(key),
  clear: () => backend.clear(),
  key: (index: number) => Array.from(backend.keys())[index] ?? null,
  get length() {
    return backend.size
  },
}

Object.defineProperty(window, 'localStorage', { configurable: true, value: storageArea })

const { useFieldClipboard } = await import('~/composables/useFieldClipboard')

const textField = { type: 'text', name: 'Title' } as unknown as SchemaType

const stored = () => JSON.parse(storageArea.getItem(STORAGE_KEY) || 'null')

/** Seat a raw payload the way another tab would, so the parse guards run. */
const putRaw = (value: string) => {
  storageArea.setItem(STORAGE_KEY, value)
  window.dispatchEvent(
    new CustomEvent('vueuse-storage', {
      detail: { key: STORAGE_KEY, newValue: value, oldValue: null, storageArea },
    })
  )
}

beforeEach(() => {
  copyToSystemClipboard.mockReset()
  copyToSystemClipboard.mockResolvedValue(undefined)
  // Module-scoped storage is shared across every caller by design.
  useFieldClipboard().clearField()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('copyField', () => {
  it('stores the field under the clipboard marker with its key', async () => {
    await useFieldClipboard().copyField('title', textField)

    expect(stored()).toMatchObject({
      type: 'b10cks-schema-field',
      key: 'title',
      field: { type: 'text', name: 'Title' },
    })
  })

  it('stamps the copy time', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T10:00:00.000Z'))

    await useFieldClipboard().copyField('title', textField)

    expect(stored().timestamp).toBe(Date.parse('2026-07-29T10:00:00.000Z'))
  })

  it('deep-clones the field so later schema edits do not rewrite the clipboard', async () => {
    const field = { type: 'option', options: [{ name: 'A', value: 'a' }] } as unknown as SchemaType

    await useFieldClipboard().copyField('status', field)
    ;(field as unknown as { options: Array<{ name: string }> }).options[0].name = 'mutated'

    expect(stored().field.options[0].name).toBe('A')
  })

  it('mirrors the serialized payload onto the system clipboard', async () => {
    await useFieldClipboard().copyField('title', textField)

    expect(copyToSystemClipboard).toHaveBeenCalledWith(
      window.localStorage.getItem(STORAGE_KEY) as string
    )
  })

  it('keeps the stored field when the system clipboard rejects', async () => {
    copyToSystemClipboard.mockRejectedValue(new Error('denied'))

    await expect(useFieldClipboard().copyField('title', textField)).resolves.toBeUndefined()
    expect(stored()).toMatchObject({ key: 'title' })
  })

  it('drops keys that JSON cannot represent', async () => {
    await useFieldClipboard().copyField('title', {
      type: 'text',
      render: () => null,
      missing: undefined,
    } as unknown as SchemaType)

    expect(Object.keys(stored().field)).toEqual(['type'])
  })

  it('overwrites the previous entry', async () => {
    const clipboard = useFieldClipboard()

    await clipboard.copyField('first', textField)
    await clipboard.copyField('second', textField)

    expect(clipboard.clipboardField.value?.key).toBe('second')
  })
})

describe('clipboardField', () => {
  it('is null while nothing has been copied', () => {
    const { clipboardField, hasField } = useFieldClipboard()

    expect(clipboardField.value).toBeNull()
    expect(hasField.value).toBe(false)
  })

  it('exposes the parsed item once a field is copied', async () => {
    const clipboard = useFieldClipboard()

    await clipboard.copyField('title', textField)

    expect(clipboard.hasField.value).toBe(true)
    expect(clipboard.clipboardField.value).toMatchObject({ key: 'title', field: { type: 'text' } })
  })

  it('is shared between separate callers', async () => {
    await useFieldClipboard().copyField('title', textField)

    expect(useFieldClipboard().clipboardField.value?.key).toBe('title')
  })

  it.each([
    ['malformed JSON', 'not json at all'],
    ['a JSON scalar', '"just a string"'],
    ['null', 'null'],
    ['the wrong type marker', JSON.stringify({ type: 'other', key: 'a', field: {} })],
    ['a non-string key', JSON.stringify({ type: 'b10cks-schema-field', key: 7, field: {} })],
    ['a missing key', JSON.stringify({ type: 'b10cks-schema-field', field: {} })],
    [
      'a non-object field',
      JSON.stringify({ type: 'b10cks-schema-field', key: 'a', field: 'text' }),
    ],
    ['a missing field', JSON.stringify({ type: 'b10cks-schema-field', key: 'a' })],
  ])('rejects %s', (_label, payload) => {
    putRaw(payload)

    const { clipboardField, hasField } = useFieldClipboard()

    expect(clipboardField.value).toBeNull()
    expect(hasField.value).toBe(false)
  })

  it('rejects an array field', () => {
    putRaw(JSON.stringify({ type: 'b10cks-schema-field', key: 'a', field: [] }))

    expect(useFieldClipboard().hasField.value).toBe(false)
  })
})

describe('clearField', () => {
  it('empties the clipboard', async () => {
    const clipboard = useFieldClipboard()

    await clipboard.copyField('title', textField)
    clipboard.clearField()

    expect(clipboard.hasField.value).toBe(false)
    expect(clipboard.clipboardField.value).toBeNull()
  })

  it('is idempotent on an empty clipboard', () => {
    const clipboard = useFieldClipboard()

    clipboard.clearField()
    clipboard.clearField()

    expect(clipboard.hasField.value).toBe(false)
  })
})
