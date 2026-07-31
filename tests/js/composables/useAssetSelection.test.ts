import { describe, expect, it } from 'vitest'
import { ref } from 'vue'

import type { AssetSelectionEntry } from '~/composables/useAssetSelection'

import { useAssetSelection } from '~/composables/useAssetSelection'

const folder = (id: string): AssetSelectionEntry => ({
  type: 'folder',
  data: { id, name: `folder-${id}` } as AssetFolderResource,
})

const asset = (id: string): AssetSelectionEntry => ({
  type: 'asset',
  data: { id, filename: `${id}.jpg` } as unknown as AssetResource,
})

// f1, f2, a1, a2, a3 — folders sort before assets in the manager's grid.
const items: AssetSelectionEntry[] = [
  folder('f1'),
  folder('f2'),
  asset('a1'),
  asset('a2'),
  asset('a3'),
]

const setup = (list: AssetSelectionEntry[] = items) => useAssetSelection(list)

const selectedKeys = (selection: ReturnType<typeof useAssetSelection>) =>
  items.filter((entry) => selection.isSelected(entry)).map(selection.keyOf)

describe('keyOf', () => {
  it('namespaces the id by type so a folder and an asset can share one', () => {
    const selection = setup()

    expect(selection.keyOf(folder('x'))).toBe('folder:x')
    expect(selection.keyOf(asset('x'))).toBe('asset:x')
  })
})

describe('selectOnly', () => {
  it('replaces the whole selection', () => {
    const selection = setup()

    selection.selectOnly(asset('a1'))
    selection.selectOnly(folder('f1'))

    expect(selectedKeys(selection)).toEqual(['folder:f1'])
    expect(selection.selectionCount.value).toBe(1)
  })

  it('sets the anchor', () => {
    const selection = setup()

    selection.selectOnly(asset('a2'))

    expect(selection.anchorKey.value).toBe('asset:a2')
  })
})

describe('toggle', () => {
  it('adds and removes without touching the rest', () => {
    const selection = setup()

    selection.toggle(asset('a1'))
    selection.toggle(folder('f1'))

    expect(selectedKeys(selection)).toEqual(['folder:f1', 'asset:a1'])

    selection.toggle(asset('a1'))

    expect(selectedKeys(selection)).toEqual(['folder:f1'])
  })

  it('leaves the anchor on the last item actually selected', () => {
    const selection = setup()

    selection.toggle(asset('a1'))
    selection.toggle(asset('a1'))

    expect(selection.anchorKey.value).toBe('asset:a1')
  })
})

describe('selectAll / clear', () => {
  it('selects every item in the ordered list', () => {
    const selection = setup()

    selection.selectAll()

    expect(selection.selectionCount.value).toBe(items.length)
    expect(selection.selectedFolders.value.size).toBe(2)
    expect(selection.selectedAssets.value.size).toBe(3)
  })

  it('clears the selection and the anchor', () => {
    const selection = setup()

    selection.selectAll()
    selection.selectOnly(asset('a1'))
    selection.clear()

    expect(selection.hasSelection.value).toBe(false)
    expect(selection.selectionCount.value).toBe(0)
    expect(selection.anchorKey.value).toBeNull()
  })
})

describe('selectRangeTo', () => {
  it('selects forwards from the anchor, spanning both types', () => {
    const selection = setup()

    selection.selectOnly(folder('f2'))
    selection.selectRangeTo(asset('a2'))

    expect(selectedKeys(selection)).toEqual(['folder:f2', 'asset:a1', 'asset:a2'])
  })

  it('selects backwards from the anchor', () => {
    const selection = setup()

    selection.selectOnly(asset('a2'))
    selection.selectRangeTo(folder('f2'))

    expect(selectedKeys(selection)).toEqual(['folder:f2', 'asset:a1', 'asset:a2'])
  })

  it('replaces the previous selection by default', () => {
    const selection = setup()

    selection.selectOnly(asset('a3'))
    selection.selectOnly(folder('f1'))
    selection.selectRangeTo(folder('f2'))

    expect(selectedKeys(selection)).toEqual(['folder:f1', 'folder:f2'])
  })

  it('keeps the previous selection when additive', () => {
    const selection = setup()

    selection.selectOnly(asset('a3'))
    selection.setSelected(folder('f1'), true)
    selection.selectRangeTo(folder('f2'), { additive: true })

    expect(selectedKeys(selection)).toEqual(['folder:f1', 'folder:f2', 'asset:a3'])
  })

  it('falls back to a single selection without an anchor', () => {
    const selection = setup()

    selection.selectRangeTo(asset('a2'))

    expect(selectedKeys(selection)).toEqual(['asset:a2'])
  })

  it('falls back to a single selection when the target is not in the list', () => {
    const selection = setup()

    selection.selectOnly(folder('f1'))
    selection.selectRangeTo(asset('gone'))

    expect(selection.selectionCount.value).toBe(1)
    expect(selection.selectedAssets.value.has('gone')).toBe(true)
  })
})

describe('handleItemPointer', () => {
  it('replaces the selection on a plain click', () => {
    const selection = setup()

    selection.handleItemPointer(asset('a1'))
    selection.handleItemPointer(asset('a2'))

    expect(selectedKeys(selection)).toEqual(['asset:a2'])
  })

  it('toggles on meta click', () => {
    const selection = setup()

    selection.handleItemPointer(asset('a1'))
    selection.handleItemPointer(asset('a2'), { meta: true })

    expect(selectedKeys(selection)).toEqual(['asset:a1', 'asset:a2'])

    selection.handleItemPointer(asset('a1'), { meta: true })

    expect(selectedKeys(selection)).toEqual(['asset:a2'])
  })

  it('ranges on shift click', () => {
    const selection = setup()

    selection.handleItemPointer(asset('a1'))
    selection.handleItemPointer(asset('a3'), { shift: true })

    expect(selectedKeys(selection)).toEqual(['asset:a1', 'asset:a2', 'asset:a3'])
  })

  it('ranges additively on shift+meta click', () => {
    const selection = setup()

    selection.handleItemPointer(folder('f1'))
    selection.handleItemPointer(asset('a2'), { meta: true })
    selection.handleItemPointer(asset('a3'), { shift: true, meta: true })

    expect(selectedKeys(selection)).toEqual(['folder:f1', 'asset:a2', 'asset:a3'])
  })
})

describe('reactivity', () => {
  it('tracks a reactive item list', () => {
    const list = ref<AssetSelectionEntry[]>([asset('a1')])
    const selection = useAssetSelection(list)

    selection.selectAll()
    expect(selection.selectionCount.value).toBe(1)

    list.value = [asset('a1'), asset('a2')]
    selection.selectAll()

    expect(selection.selectionCount.value).toBe(2)
  })

  it('accepts a getter', () => {
    const selection = useAssetSelection(() => [folder('f1')])

    selection.selectAll()

    expect(selection.selectedFolders.value.has('f1')).toBe(true)
  })
})

describe('selectionSignature', () => {
  it('is empty for an empty selection', () => {
    expect(setup().selectionSignature.value).toBe('|')
  })

  it('separates folders from assets', () => {
    const selection = setup()

    selection.setSelected(folder('f1'), true)
    selection.setSelected(asset('a1'), true)

    expect(selection.selectionSignature.value).toBe('f1|a1')
  })

  it('changes when the selection changes', () => {
    const selection = setup()

    selection.setSelected(asset('a1'), true)
    const before = selection.selectionSignature.value

    selection.setSelected(asset('a2'), true)

    expect(selection.selectionSignature.value).not.toBe(before)
  })

  it('does not change when an already-selected resource mutates internally', () => {
    const entry = asset('a1')
    const selection = setup()

    selection.setSelected(entry, true)
    const before = selection.selectionSignature.value

    ;(entry.data as unknown as { filename: string }).filename = 'renamed.jpg'

    expect(selection.selectionSignature.value).toBe(before)
  })
})

describe('selectedDragItems', () => {
  it('lists folders before assets as {id, type} pairs', () => {
    const selection = setup()

    selection.setSelected(asset('a1'), true)
    selection.setSelected(folder('f1'), true)

    expect(selection.selectedDragItems.value).toEqual([
      { id: 'f1', type: 'folder' },
      { id: 'a1', type: 'asset' },
    ])
  })

  it('is empty without a selection', () => {
    expect(setup().selectedDragItems.value).toEqual([])
  })
})
