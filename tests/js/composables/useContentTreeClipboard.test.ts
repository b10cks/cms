import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ContentTreeClipboardSnapshotItem } from '~/types/contents'

import { useContentTreeClipboard } from '~/composables/useContentTreeClipboard'

type ItemLike = Pick<FlatContentMenuItem, 'id' | 'pid' | 'block_id' | 'type'>

const SPACE = 'space-1'

const item = (
  id: string,
  overrides: Partial<ItemLike> = {}
): ItemLike => ({ id, pid: null, block_id: 'block-page', type: 'root', ...overrides }) as ItemLike

const context = (...items: ItemLike[]) => ({
  itemsById: new Map(items.map((entry) => [entry.id, entry])),
})

const snapshot = (
  id: string,
  overrides: Partial<ContentTreeClipboardSnapshotItem> = {}
): ContentTreeClipboardSnapshotItem => ({
  id,
  parent_id: null,
  block_id: 'block-page',
  block_type: 'root',
  tree_index: 0,
  descendant_ids: [],
  ...overrides,
})

const clipboard = () => useContentTreeClipboard()

// clipboardState lives at module scope and is shared by every caller — the
// point of the composable — so it has to be reset between tests.
beforeEach(async () => {
  await clipboard().clearClipboard()
})

describe('normalizeRootSelection', () => {
  // parent chain: a > a1 > a2, plus a standalone b
  const parents = new Map<string, string | null>([
    ['a', null],
    ['a1', 'a'],
    ['a2', 'a1'],
    ['b', null],
  ])

  it('drops a child whose parent is also selected', () => {
    expect(clipboard().normalizeRootSelection(['a', 'a1'], parents)).toEqual(['a'])
  })

  it('drops a grandchild whose ancestor is selected', () => {
    expect(clipboard().normalizeRootSelection(['a', 'a2'], parents)).toEqual(['a'])
  })

  it('keeps siblings that are not nested in each other', () => {
    expect(clipboard().normalizeRootSelection(['a', 'b'], parents).sort()).toEqual(['a', 'b'])
  })

  it('keeps a child when only the child is selected', () => {
    expect(clipboard().normalizeRootSelection(['a2'], parents)).toEqual(['a2'])
  })

  it('deduplicates ids', () => {
    expect(clipboard().normalizeRootSelection(['a', 'a', 'a'], parents)).toEqual(['a'])
  })

  it('orders the result by tree position when an order map is given', () => {
    const order = new Map([
      ['b', 0],
      ['a', 1],
    ])

    expect(clipboard().normalizeRootSelection(['a', 'b'], parents, order)).toEqual(['b', 'a'])
  })

  it('sorts ids missing from the order map to the end', () => {
    const order = new Map([['b', 5]])

    expect(clipboard().normalizeRootSelection(['a', 'b'], parents, order)).toEqual(['b', 'a'])
  })

  it('tolerates a parent id that is not in the map', () => {
    expect(clipboard().normalizeRootSelection(['orphan'], new Map())).toEqual(['orphan'])
  })

  it('returns nothing for an empty selection', () => {
    expect(clipboard().normalizeRootSelection([], parents)).toEqual([])
  })
})

describe('buildSnapshot', () => {
  const options = {
    itemsById: new Map([
      ['a', item('a', { block_id: 'block-a' })],
      ['b', item('b', { pid: 'a', type: 'nestable' as ItemLike['type'] })],
    ]),
    descendantsById: new Map([['a', new Set(['b', 'c'])]]),
    treeOrderById: new Map([
      ['a', 1],
      ['b', 0],
    ]),
  }

  it('maps items to snapshots, carrying parent, block and descendants', () => {
    expect(clipboard().buildSnapshot(['a'], options)).toEqual([
      {
        id: 'a',
        parent_id: null,
        block_id: 'block-a',
        block_type: 'root',
        tree_index: 1,
        descendant_ids: ['b', 'c'],
      },
    ])
  })

  it('orders snapshots by tree index, not by the ids given', () => {
    expect(clipboard().buildSnapshot(['a', 'b'], options).map((entry) => entry.id)).toEqual(['b', 'a'])
  })

  it('skips ids that are not in the item map', () => {
    expect(clipboard().buildSnapshot(['a', 'ghost'], options).map((entry) => entry.id)).toEqual(['a'])
  })

  it('defaults a missing tree index to the end', () => {
    const [entry] = clipboard().buildSnapshot(['b'], { ...options, treeOrderById: new Map() })

    expect(entry.tree_index).toBe(Number.MAX_SAFE_INTEGER)
  })

  it('defaults missing descendants to an empty list', () => {
    expect(clipboard().buildSnapshot(['b'], options)[0].descendant_ids).toEqual([])
  })

  it('normalizes an undefined parent to null', () => {
    const [entry] = clipboard().buildSnapshot(['x'], {
      ...options,
      itemsById: new Map([['x', { id: 'x', block_id: 'b', type: 'root' } as ItemLike]]),
    })

    expect(entry.parent_id).toBeNull()
  })
})

describe('copy and cut', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T12:00:00.000Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('stores a single copied item, not marked as cut', async () => {
    const store = clipboard()

    await store.copyItem(snapshot('a'), SPACE)

    expect(await store.getClipboardItem()).toEqual({
      type: 'content-tree-clipboard-item',
      data: snapshot('a'),
      timestamp: Date.parse('2026-07-29T12:00:00.000Z'),
      spaceId: SPACE,
    })
  })

  it('stores a multi-item copy under the plural type', async () => {
    const store = clipboard()

    await store.copyItem([snapshot('a'), snapshot('b')], SPACE)
    const stored = await store.getClipboardItem()
    const data = (stored?.data ?? []) as ContentTreeClipboardSnapshotItem[]

    expect(stored?.type).toBe('content-tree-clipboard-items')
    expect(data.map((entry) => entry.id)).toEqual(['a', 'b'])
  })

  it('marks a cut item', async () => {
    const store = clipboard()

    await store.cutItem(snapshot('a'), SPACE)

    expect((await store.getClipboardItem())?._isCut).toBe(true)
  })

  it('marks a multi-item cut', async () => {
    const store = clipboard()

    await store.cutItem([snapshot('a')], SPACE)

    expect((await store.getClipboardItem())?._isCut).toBe(true)
  })

  it('flags that something is on the clipboard', async () => {
    const store = clipboard()

    expect(store.hasClipboardItem.value).toBe(false)

    await store.copyItem(snapshot('a'), SPACE)

    expect(store.hasClipboardItem.value).toBe(true)
  })

  it('shares state between separate calls of the composable', async () => {
    await clipboard().copyItem(snapshot('a'), SPACE)

    expect(clipboard().hasClipboardItem.value).toBe(true)
    expect((await clipboard().getClipboardItem())?.spaceId).toBe(SPACE)
  })

  it('clears the clipboard', async () => {
    const store = clipboard()

    await store.copyItem(snapshot('a'), SPACE)
    await store.clearClipboard()

    expect(store.hasClipboardItem.value).toBe(false)
    expect(await store.getClipboardItem()).toBeNull()
  })

  it('a later copy replaces the earlier one', async () => {
    const store = clipboard()

    await store.cutItem(snapshot('a'), SPACE)
    await store.copyItem(snapshot('b'), SPACE)
    const stored = await store.getClipboardItem()

    expect(((stored?.data ?? {}) as ContentTreeClipboardSnapshotItem).id).toBe('b')
    expect(stored?._isCut).toBeUndefined()
  })
})

describe('clipboard snapshots are detached', () => {
  const storedDescendants = async (store: ReturnType<typeof clipboard>) => {
    const stored = await store.getClipboardItem()

    return ((stored?.data ?? {}) as ContentTreeClipboardSnapshotItem).descendant_ids
  }

  it('does not alias the copied snapshot', async () => {
    const store = clipboard()
    const source = snapshot('a', { descendant_ids: ['b'] })

    await store.copyItem(source, SPACE)
    source.descendant_ids.push('c')

    expect(await storedDescendants(store)).toEqual(['b'])
  })

  it('hands out a fresh copy on every read', async () => {
    const store = clipboard()

    await store.copyItem(snapshot('a', { descendant_ids: ['b'] }), SPACE)
    const first = ((await store.getClipboardItem())?.data ?? {}) as ContentTreeClipboardSnapshotItem

    first.descendant_ids.push('mutated')

    expect(await storedDescendants(store)).toEqual(['b'])
  })
})

describe('normalizeClipboardItems', () => {
  it('unwraps both clipboard shapes to a list', () => {
    const store = clipboard()

    expect(
      store.normalizeClipboardItems({
        type: 'content-tree-clipboard-item',
        data: snapshot('a'),
        timestamp: 0,
        spaceId: SPACE,
      })
    ).toHaveLength(1)
    expect(
      store.normalizeClipboardItems({
        type: 'content-tree-clipboard-items',
        data: [snapshot('a'), snapshot('b')],
        timestamp: 0,
        spaceId: SPACE,
      })
    ).toHaveLength(2)
  })

  it('returns nothing for null', () => {
    expect(clipboard().normalizeClipboardItems(null)).toEqual([])
  })
})

describe('paste validation', () => {
  const copied = (data: ContentTreeClipboardSnapshotItem | ContentTreeClipboardSnapshotItem[]) =>
    Array.isArray(data)
      ? ({ type: 'content-tree-clipboard-items', data, timestamp: 0, spaceId: SPACE } as const)
      : ({ type: 'content-tree-clipboard-item', data, timestamp: 0, spaceId: SPACE } as const)

  const cut = (data: ContentTreeClipboardSnapshotItem | ContentTreeClipboardSnapshotItem[]) => ({
    ...copied(data),
    _isCut: true,
  })

  describe('canPasteIn', () => {
    it('allows pasting into an ordinary parent', () => {
      expect(
        clipboard().canPasteIn(copied(snapshot('a')), SPACE, item('target'), context(item('target')))
      ).toBe(true)
    })

    it('allows pasting at the root', () => {
      expect(clipboard().canPasteIn(copied(snapshot('a')), SPACE, null, context())).toBe(true)
    })

    it('rejects an empty clipboard', () => {
      expect(clipboard().canPasteIn(null, SPACE, item('target'), context(item('target')))).toBe(false)
    })

    it('rejects a clipboard from another space', () => {
      expect(
        clipboard().canPasteIn(copied(snapshot('a')), 'space-2', null, context())
      ).toBe(false)
    })

    it('rejects a single-type target, which cannot hold children', () => {
      const target = item('target', { type: 'single' })

      expect(clipboard().canPasteIn(copied(snapshot('a')), SPACE, target, context(target))).toBe(false)
    })

    it('rejects cutting an item into itself', () => {
      const target = item('a')

      expect(clipboard().canPasteIn(cut(snapshot('a')), SPACE, target, context(target))).toBe(false)
    })

    it('rejects cutting an item into its own descendant', () => {
      const target = item('child')

      expect(
        clipboard().canPasteIn(
          cut(snapshot('a', { descendant_ids: ['child'] })),
          SPACE,
          target,
          context(target)
        )
      ).toBe(false)
    })

    it('allows copying an item into its own descendant — a copy makes no cycle', () => {
      const target = item('child')

      expect(
        clipboard().canPasteIn(
          copied(snapshot('a', { descendant_ids: ['child'] })),
          SPACE,
          target,
          context(target)
        )
      ).toBe(true)
    })

    it('rejects the whole paste when any item in a multi-cut would cycle', () => {
      const target = item('child')

      expect(
        clipboard().canPasteIn(
          cut([snapshot('a'), snapshot('b', { descendant_ids: ['child'] })]),
          SPACE,
          target,
          context(target)
        )
      ).toBe(false)
    })
  })

  describe('single-type content', () => {
    const single = snapshot('s', { block_type: 'single', block_id: 'block-settings' })

    it('rejects pasting a single-type item under a parent', () => {
      const target = item('target')

      expect(clipboard().canPasteIn(copied(single), SPACE, target, context(target))).toBe(false)
    })

    it('allows pasting a single-type item at the root when none exists', () => {
      expect(clipboard().canPasteIn(copied(single), SPACE, null, context())).toBe(true)
    })

    it('rejects a second root single of the same block', () => {
      const existing = item('other', { type: 'single', block_id: 'block-settings' })

      expect(clipboard().canPasteIn(copied(single), SPACE, null, context(existing))).toBe(false)
    })

    it('allows a root single when the existing one uses a different block', () => {
      const existing = item('other', { type: 'single', block_id: 'block-other' })

      expect(clipboard().canPasteIn(copied(single), SPACE, null, context(existing))).toBe(true)
    })

    it('ignores a same-block single that is nested rather than at the root', () => {
      const nested = item('other', { type: 'single', block_id: 'block-settings', pid: 'parent' })

      expect(clipboard().canPasteIn(copied(single), SPACE, null, context(nested))).toBe(true)
    })

    it('allows moving the existing root single onto itself — it is the one being cut', () => {
      const existing = item('s', { type: 'single', block_id: 'block-settings' })

      expect(clipboard().canPasteIn(cut(single), SPACE, null, context(existing))).toBe(true)
    })

    it('still rejects a copy of the existing root single', () => {
      const existing = item('s', { type: 'single', block_id: 'block-settings' })

      expect(clipboard().canPasteIn(copied(single), SPACE, null, context(existing))).toBe(false)
    })
  })

  describe('canPasteAfter', () => {
    it('targets the sibling parent, so a single-type parent still blocks it', () => {
      const parent = item('parent', { type: 'single' })
      const sibling = item('sibling', { pid: 'parent' })

      expect(
        clipboard().canPasteAfter(copied(snapshot('a')), SPACE, sibling, context(parent, sibling))
      ).toBe(false)
    })

    it('allows pasting after a root sibling', () => {
      const sibling = item('sibling')

      expect(
        clipboard().canPasteAfter(copied(snapshot('a')), SPACE, sibling, context(sibling))
      ).toBe(true)
    })

    it('falls back to the root with no target', () => {
      expect(clipboard().canPasteAfter(copied(snapshot('a')), SPACE, null, context())).toBe(true)
    })

    it('rejects dropping a cut item next to itself', () => {
      const sibling = item('a')

      expect(clipboard().canPasteAfter(cut(snapshot('a')), SPACE, sibling, context(sibling))).toBe(
        false
      )
    })

    it('rejects dropping a cut item next to its own descendant', () => {
      const parent = item('parent')
      const sibling = item('child', { pid: 'parent' })

      expect(
        clipboard().canPasteAfter(
          cut(snapshot('a', { descendant_ids: ['child'] })),
          SPACE,
          sibling,
          context(parent, sibling)
        )
      ).toBe(false)
    })

    it('allows dropping a copy next to its own descendant', () => {
      const parent = item('parent')
      const sibling = item('child', { pid: 'parent' })

      expect(
        clipboard().canPasteAfter(
          copied(snapshot('a', { descendant_ids: ['child'] })),
          SPACE,
          sibling,
          context(parent, sibling)
        )
      ).toBe(true)
    })
  })
})
