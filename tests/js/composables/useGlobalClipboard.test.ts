import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

const SPACE = 'space-1'

/**
 * The browser seams the composable is built on: the system clipboard, the
 * localStorage mirror, the permission state and window focus. jsdom has no
 * `navigator.clipboard` and vitest's jsdom here has no `localStorage` at all,
 * so these have to be driveable for the read paths to be reachable.
 */
const clipboardText = ref('')
const isSupported = ref(true)
const copy = vi.fn(async (text: string) => {
  clipboardText.value = text
})
const storage = ref<string | null>(null)
const permission = ref<PermissionState | undefined>('prompt')
const focused = ref(true)

vi.mock('@vueuse/core', async () => {
  const actual = await vi.importActual<typeof import('@vueuse/core')>('@vueuse/core')

  return {
    ...actual,
    useClipboard: () => ({ copy, text: clipboardText, isSupported }),
    useLocalStorage: () => storage,
    usePermission: () => permission,
    useWindowFocus: () => focused,
  }
})

const { useGlobalClipboard } = await import('~/composables/useGlobalClipboard')

const clipboard = () => useGlobalClipboard()

const writeRaw = (value: unknown) => {
  storage.value = typeof value === 'string' ? value : JSON.stringify(value)
}

let consoleWarn: ReturnType<typeof vi.spyOn>

// The clipboard refs live at module scope and are shared by every caller —
// that is the point of the composable — so they have to be reset per test.
beforeEach(async () => {
  consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {})
  clipboardText.value = ''
  permission.value = 'prompt'
  focused.value = true
  // clearClipboard writes to the system clipboard too, so the spy is reset after.
  await clipboard().clearClipboard()
  copy.mockClear()
})

afterEach(() => {
  consoleWarn.mockRestore()
  vi.useRealTimers()
})

describe('copyItem', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T12:00:00.000Z'))
  })

  it('stores a single block under the singular type', async () => {
    await clipboard().copyItem({ id: 'b1', headline: 'Hi' }, SPACE, 'card')

    expect(await clipboard().getClipboardItem()).toEqual({
      type: 'blocks-editor-clipboard-item',
      data: { id: 'b1', headline: 'Hi' },
      timestamp: Date.parse('2026-07-29T12:00:00.000Z'),
      spaceId: SPACE,
      blockType: 'card',
    })
  })

  it('writes to the system clipboard and mirrors it in storage', async () => {
    await clipboard().copyItem({ id: 'b1' }, SPACE)

    expect(copy).toHaveBeenCalledTimes(1)
    expect(storage.value).toBe(clipboardText.value)
  })

  it('stores a list of blocks under the plural type', async () => {
    await clipboard().copyItem([{ id: 'b1' }, { id: 'b2' }], SPACE)
    const item = await clipboard().getClipboardItem()

    expect(item?.type).toBe('blocks-editor-clipboard-items')
    expect(((item?.data ?? []) as Record<string, unknown>[]).map((entry) => entry.id)).toEqual([
      'b1',
      'b2',
    ])
  })

  it('does not mark a copy as cut', async () => {
    await clipboard().copyItem({ id: 'b1' }, SPACE)

    expect((await clipboard().getClipboardItem())?._isCut).toBeUndefined()
  })

  it('falls back to storage when the system clipboard write fails', async () => {
    copy.mockRejectedValueOnce(new Error('denied'))

    await clipboard().copyItem({ id: 'b1' }, SPACE)

    expect(storage.value).toContain('blocks-editor-clipboard-item')
    expect(clipboard().hasClipboardItem.value).toBe(true)
    expect(consoleWarn).toHaveBeenCalled()
  })

  it('detaches the stored copy from the source object', async () => {
    const source = { id: 'b1', nested: { value: 1 } }

    await clipboard().copyItem(source, SPACE)
    source.nested.value = 2

    const item = await clipboard().getClipboardItem()

    expect(((item?.data ?? {}) as typeof source).nested.value).toBe(1)
  })

  it('replaces whatever was on the clipboard before', async () => {
    await clipboard().cutItem({ id: 'b1' }, SPACE)
    await clipboard().copyItem({ id: 'b2' }, SPACE)
    const item = await clipboard().getClipboardItem()

    expect(((item?.data ?? {}) as Record<string, unknown>).id).toBe('b2')
    expect(item?._isCut).toBeUndefined()
  })
})

describe('cutItem', () => {
  it('marks a single cut block', async () => {
    await clipboard().cutItem({ id: 'b1' }, SPACE)

    expect((await clipboard().getClipboardItem())?._isCut).toBe(true)
  })

  it('marks a multi-block cut', async () => {
    await clipboard().cutItem([{ id: 'b1' }], SPACE)
    const item = await clipboard().getClipboardItem()

    expect(item?.type).toBe('blocks-editor-clipboard-items')
    expect(item?._isCut).toBe(true)
  })
})

describe('hasClipboardItem', () => {
  it('flips once something is copied and back on clear', async () => {
    const store = clipboard()

    expect(store.hasClipboardItem.value).toBe(false)

    await store.copyItem({ id: 'b1' }, SPACE)
    expect(store.hasClipboardItem.value).toBe(true)

    await store.clearClipboard()
    expect(store.hasClipboardItem.value).toBe(false)
  })

  it('is shared between separate callers', async () => {
    await clipboard().copyItem({ id: 'b1' }, SPACE)

    expect(clipboard().hasClipboardItem.value).toBe(true)
    expect((await clipboard().getClipboardItem())?.spaceId).toBe(SPACE)
  })

  it('stays false for storage the caller never wrote through the composable', async () => {
    writeRaw({ type: 'blocks-editor-clipboard-item', data: {}, timestamp: 0, spaceId: SPACE })

    // ACTUAL BEHAVIOUR: the flag is only recomputed by the focus/permission
    // watchers and the poll, none of which run without clipboard-read permission.
    // A pasteable item can therefore exist while the flag says otherwise.
    expect(clipboard().hasClipboardItem.value).toBe(false)
    expect(await clipboard().getClipboardItem()).not.toBeNull()
  })

  it('is recomputed when clipboard-read permission is granted', async () => {
    writeRaw({ type: 'blocks-editor-clipboard-item', data: { id: 'b1' }, timestamp: 0, spaceId: SPACE })

    permission.value = 'granted'
    await nextTick()
    await Promise.resolve()

    expect(clipboard().hasClipboardItem.value).toBe(true)
  })

  it('is recomputed when the window regains focus', async () => {
    permission.value = 'granted'
    focused.value = false
    await nextTick()

    writeRaw({ type: 'blocks-editor-clipboard-item', data: { id: 'b1' }, timestamp: 0, spaceId: SPACE })
    focused.value = true
    await nextTick()
    await Promise.resolve()

    expect(clipboard().hasClipboardItem.value).toBe(true)
  })

  it('stays false when neither source holds a valid item', async () => {
    writeRaw('not json at all')
    clipboardText.value = 'also not json'

    permission.value = 'granted'
    await nextTick()
    await Promise.resolve()

    expect(clipboard().hasClipboardItem.value).toBe(false)
  })
})

describe('clearClipboard', () => {
  it('empties the storage mirror as well as the flag', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1' }, SPACE)
    await store.clearClipboard()

    expect(storage.value).toBeNull()
    expect(store.hasClipboardItem.value).toBe(false)
  })

  it('clears the system clipboard too, so nothing pastes afterwards', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1' }, SPACE)
    await store.clearClipboard()

    // getClipboardItem prefers the system clipboard, so leaving the item there
    // would keep pasting while hasClipboardItem says the clipboard is empty.
    expect(await store.getClipboardItem()).toBeNull()
    expect(await store.pasteItem()).toBeNull()
  })

  // The system clipboard is shared with every other application, so clearing an
  // editor selection must not destroy what the user copied somewhere else.
  it('leaves foreign system-clipboard content alone', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1' }, SPACE)
    clipboardText.value = 'a shopping list the user copied from another app'
    copy.mockClear()

    await store.clearClipboard()

    expect(copy).not.toHaveBeenCalled()
    expect(clipboardText.value).toBe('a shopping list the user copied from another app')
    // Our own mirror is gone either way, so nothing of ours can still paste.
    expect(storage.value).toBeNull()
    expect(await store.getClipboardItem()).toBeNull()
  })

  it('survives a system clipboard that refuses the write', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1' }, SPACE)
    copy.mockRejectedValueOnce(new Error('denied'))

    await expect(store.clearClipboard()).resolves.toBeUndefined()
    expect(storage.value).toBeNull()
    expect(store.hasClipboardItem.value).toBe(false)
  })
})

describe('getClipboardItem', () => {
  it('prefers a valid item on the system clipboard over storage', async () => {
    clipboardText.value = JSON.stringify({
      type: 'blocks-editor-clipboard-item',
      data: { id: 'system' },
      timestamp: 1,
      spaceId: SPACE,
    })
    writeRaw({
      type: 'blocks-editor-clipboard-item',
      data: { id: 'stored' },
      timestamp: 1,
      spaceId: SPACE,
    })

    const item = await clipboard().getClipboardItem()

    expect(((item?.data ?? {}) as Record<string, unknown>).id).toBe('system')
  })

  it('falls back to storage when the system clipboard holds something else', async () => {
    clipboardText.value = 'some copied prose'
    writeRaw({
      type: 'blocks-editor-clipboard-item',
      data: { id: 'stored' },
      timestamp: 1,
      spaceId: SPACE,
    })

    const item = await clipboard().getClipboardItem()

    expect(((item?.data ?? {}) as Record<string, unknown>).id).toBe('stored')
    expect(consoleWarn).toHaveBeenCalled()
  })

  it('falls back to storage when the system clipboard holds valid JSON of the wrong shape', async () => {
    clipboardText.value = JSON.stringify({ hello: 'world' })
    writeRaw({
      type: 'blocks-editor-clipboard-item',
      data: { id: 'stored' },
      timestamp: 1,
      spaceId: SPACE,
    })

    const item = await clipboard().getClipboardItem()

    expect(((item?.data ?? {}) as Record<string, unknown>).id).toBe('stored')
  })

  it('returns null for unparseable storage', async () => {
    writeRaw('{not json')

    expect(await clipboard().getClipboardItem()).toBeNull()
    expect(consoleWarn).toHaveBeenCalled()
  })

  it('returns null when nothing is stored', async () => {
    expect(await clipboard().getClipboardItem()).toBeNull()
  })

  const rejected: Array<[string, unknown]> = [
    ['a bare string', 'hello'],
    ['an array', [{ type: 'blocks-editor-clipboard-item' }]],
    ['null', null],
    ['a missing timestamp', { type: 'blocks-editor-clipboard-item', data: {}, spaceId: SPACE }],
    [
      'a string timestamp',
      { type: 'blocks-editor-clipboard-item', data: {}, timestamp: '1', spaceId: SPACE },
    ],
    ['a missing spaceId', { type: 'blocks-editor-clipboard-item', data: {}, timestamp: 1 }],
    ['an unknown type', { type: 'something-else', data: {}, timestamp: 1, spaceId: SPACE }],
    [
      'a single item whose data is an array',
      { type: 'blocks-editor-clipboard-item', data: [], timestamp: 1, spaceId: SPACE },
    ],
    [
      'a single item with a non-boolean cut flag',
      {
        type: 'blocks-editor-clipboard-item',
        data: {},
        timestamp: 1,
        spaceId: SPACE,
        _isCut: 'yes',
      },
    ],
    [
      'a multi item whose data is not an array',
      { type: 'blocks-editor-clipboard-items', data: {}, timestamp: 1, spaceId: SPACE },
    ],
    [
      'a multi item holding a non-object entry',
      {
        type: 'blocks-editor-clipboard-items',
        data: [{ id: 'b1' }, 'nope'],
        timestamp: 1,
        spaceId: SPACE,
      },
    ],
  ]

  it.each(rejected)('rejects %s', async (_label, payload) => {
    writeRaw(payload)

    expect(await clipboard().getClipboardItem()).toBeNull()
  })

  it('accepts an empty multi-item list', async () => {
    writeRaw({ type: 'blocks-editor-clipboard-items', data: [], timestamp: 1, spaceId: SPACE })

    expect((await clipboard().getClipboardItem())?.type).toBe('blocks-editor-clipboard-items')
  })

  it('rejects a multi item with a non-boolean cut flag, like the singular one', async () => {
    writeRaw({
      type: 'blocks-editor-clipboard-items',
      data: [{ id: 'b1' }],
      timestamp: 1,
      spaceId: SPACE,
      _isCut: 'yes',
    })

    // Truthy garbage would otherwise be honoured as a cut.
    expect(await clipboard().getClipboardItem()).toBeNull()
  })

  it('accepts a boolean cut flag on a multi item', async () => {
    writeRaw({
      type: 'blocks-editor-clipboard-items',
      data: [{ id: 'b1' }],
      timestamp: 1,
      spaceId: SPACE,
      _isCut: true,
    })

    expect((await clipboard().getClipboardItem())?._isCut).toBe(true)
  })
})

describe('pasteItem', () => {
  it('returns null with an empty clipboard', async () => {
    expect(await clipboard().pasteItem()).toBeNull()
  })

  it('gives every copied block a fresh id', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1', headline: 'Hi' }, SPACE)
    const pasted = (await store.pasteItem()) as Record<string, unknown>

    expect(pasted.headline).toBe('Hi')
    expect(pasted.id).toEqual(expect.any(String))
    expect(pasted.id).not.toBe('b1')
  })

  it('rewrites ids at every depth, through arrays', async () => {
    const store = clipboard()

    await store.copyItem(
      { id: 'root', items: [{ id: 'child', items: [{ id: 'grandchild' }] }] },
      SPACE
    )
    const pasted = (await store.pasteItem()) as Record<string, unknown>
    const child = (pasted.items as Record<string, unknown>[])[0]
    const grandchild = (child.items as Record<string, unknown>[])[0]

    expect([pasted.id, child.id, grandchild.id]).not.toContain('root')
    expect(new Set([pasted.id, child.id, grandchild.id]).size).toBe(3)
  })

  it('leaves other id-like keys alone', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1', block_id: 'block-card', parent_id: 'p1' }, SPACE)
    const pasted = (await store.pasteItem()) as Record<string, unknown>

    expect(pasted.block_id).toBe('block-card')
    expect(pasted.parent_id).toBe('p1')
  })

  it('returns a list for a multi-block clipboard, each with a new id', async () => {
    const store = clipboard()

    await store.copyItem([{ id: 'b1' }, { id: 'b2' }], SPACE)
    const pasted = (await store.pasteItem()) as Record<string, unknown>[]

    expect(pasted).toHaveLength(2)
    expect(pasted.map((entry) => entry.id)).not.toEqual(['b1', 'b2'])
  })

  it('pastes the same clipboard twice with different ids', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1' }, SPACE)
    const first = (await store.pasteItem()) as Record<string, unknown>
    const second = (await store.pasteItem()) as Record<string, unknown>

    expect(first.id).not.toBe(second.id)
  })

  it('preserves falsy values and nulls', async () => {
    const store = clipboard()

    await store.copyItem({ id: 'b1', count: 0, label: '', flag: false, missing: null }, SPACE)
    const pasted = (await store.pasteItem()) as Record<string, unknown>

    expect(pasted).toMatchObject({ count: 0, label: '', flag: false, missing: null })
  })

  it('keeps a cut item on the clipboard after pasting', async () => {
    const store = clipboard()

    await store.cutItem({ id: 'b1' }, SPACE)
    await store.pasteItem()

    // Clearing after a cut-paste is the caller's job; the composable keeps it.
    expect(store.hasClipboardItem.value).toBe(true)
    expect((await store.getClipboardItem())?._isCut).toBe(true)
  })

  it('pastes an item written by another tab', async () => {
    writeRaw({
      type: 'blocks-editor-clipboard-item',
      data: { id: 'b1', headline: 'From elsewhere' },
      timestamp: 1,
      spaceId: SPACE,
    })

    expect((await clipboard().pasteItem()) as Record<string, unknown>).toMatchObject({
      headline: 'From elsewhere',
    })
  })

  it('does not paste across spaces on its own', async () => {
    await clipboard().copyItem({ id: 'b1' }, 'other-space')

    // No space check here: the paste target has to compare spaceId itself.
    expect(await clipboard().pasteItem()).not.toBeNull()
  })
})

describe('lifecycle', () => {
  const mountConsumer = () =>
    mount(
      defineComponent({
        setup() {
          useGlobalClipboard()
          return () => h('div')
        },
      })
    )

  it('registers no lifecycle hooks when called outside a component', () => {
    consoleWarn.mockClear()

    clipboard()

    // Vue warns "onMounted is called when there is no active component
    // instance" — which is what the module-scope onMounted used to do.
    expect(consoleWarn).not.toHaveBeenCalled()
  })

  it('primes the clipboard permission from a mounted component', async () => {
    const readText = vi.fn(async () => '')
    Object.defineProperty(navigator, 'clipboard', { value: { readText }, configurable: true })

    const wrapper = mountConsumer()
    await nextTick()

    expect(readText).toHaveBeenCalled()

    wrapper.unmount()
    Reflect.deleteProperty(navigator, 'clipboard')
  })

  it('stops polling once the last component consumer unmounts', async () => {
    const wrapper = mountConsumer()
    // Let the mount-time check settle before the clipboard is written to.
    await nextTick()
    await Promise.resolve()
    wrapper.unmount()

    vi.useFakeTimers()
    permission.value = 'granted'
    writeRaw({ type: 'blocks-editor-clipboard-item', data: { id: 'b1' }, timestamp: 0, spaceId: SPACE })
    await vi.advanceTimersByTimeAsync(5000)

    // No timer survives the last consumer: the poll used to run for the
    // lifetime of the page with no way to stop it.
    expect(clipboard().hasClipboardItem.value).toBe(false)
  })
})
