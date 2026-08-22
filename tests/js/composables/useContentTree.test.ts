import { describe, expect, it } from 'vitest'
import { ref } from 'vue'

import type { ContentBlock } from '~/types/contents'

import { useContentTree, type ContentTreeItem } from '~/composables/useContentTree'

const root = () =>
  ({
    id: 'root',
    block: 'page',
    body: [
      { id: 'hero', block: 'hero', headline: 'Hi' },
      {
        id: 'section',
        block: 'section',
        items: [
          { id: 'card-1', block: 'card' },
          { id: 'card-2', block: 'card', nested: { id: 'deep', block: 'note' } },
        ],
      },
    ],
    aside: { id: 'promo', block: 'promo' },
  }) as unknown as ContentTreeItem

const tree = (content: ContentTreeItem = root()) =>
  useContentTree(content, {} as unknown as ContentBlock)

describe('findItemById', () => {
  it('finds a direct array child and reports its slot', () => {
    const result = tree().findItemById('hero')

    expect(result?.item?.id).toBe('hero')
    expect(result?.parent?.id).toBe('root')
    expect(result?.parentKey).toBe('body')
    expect(result?.index).toBe(0)
  })

  it('reports the array index of a later sibling', () => {
    expect(tree().findItemById('section')?.index).toBe(1)
  })

  it('finds an item nested two arrays deep', () => {
    const result = tree().findItemById('card-2')

    expect(result?.parent?.id).toBe('section')
    expect(result?.parentKey).toBe('items')
    expect(result?.index).toBe(1)
  })

  it('finds an item held in an object slot, which has no index', () => {
    const result = tree().findItemById('promo')

    expect(result?.parent?.id).toBe('root')
    expect(result?.parentKey).toBe('aside')
    expect(result?.index).toBeNull()
  })

  it('finds an object slot nested inside an array item', () => {
    const result = tree().findItemById('deep')

    expect(result?.parent?.id).toBe('card-2')
    expect(result?.parentKey).toBe('nested')
  })

  // `index` belongs to the frame that claims the slot. It used to be filled
  // independently, so an ancestor array's position (1 — card-2's index in
  // section.items) described an object slot one level down, and
  // {parent, parentKey, index} was not a valid splice target.
  it('reports no index for an object-slot result', () => {
    expect(tree().findItemById('deep')?.index).toBeNull()
  })

  it('returns the full path from the root down to the item', () => {
    expect(tree().findItemById('deep')?.path.map((item) => item.id)).toEqual([
      'root',
      'section',
      'card-2',
      'deep',
    ])
  })

  it('returns null for an unknown id', () => {
    expect(tree().findItemById('nope')).toBeNull()
  })

  it('returns null for the root itself — the root is not a findable child', () => {
    expect(tree().findItemById('root')).toBeNull()
  })

  it('returns null when the content is missing', () => {
    expect(useContentTree(null as unknown as ContentTreeItem, {} as ContentBlock).findItemById('x')).toBeNull()
  })

  it('ignores array entries that are not tree items', () => {
    const content = {
      id: 'root',
      block: 'page',
      body: ['plain', null, 42, { noId: true }, { id: 'real', block: 'card' }],
    } as unknown as ContentTreeItem

    expect(tree(content).findItemById('real')?.index).toBe(4)
  })

  it('ignores an id that is not a string', () => {
    const content = {
      id: 'root',
      block: 'page',
      body: [{ id: 7, block: 'card' }],
    } as unknown as ContentTreeItem

    expect(tree(content).findItemById('7')).toBeNull()
  })

  it('skips primitive and null properties while walking', () => {
    const content = {
      id: 'root',
      block: 'page',
      title: 'Home',
      count: 3,
      empty: null,
      body: [{ id: 'card', block: 'card' }],
    } as unknown as ContentTreeItem

    expect(tree(content).findItemById('card')?.item?.id).toBe('card')
  })

  it('reads through a ref', () => {
    const content = ref(root())

    expect(useContentTree(content, {} as ContentBlock).findItemById('hero')?.item?.id).toBe('hero')
  })

  it('sees a swapped-in tree on the next call', () => {
    const content = ref(root())
    const { findItemById } = useContentTree(content, {} as ContentBlock)

    expect(findItemById('hero')).not.toBeNull()

    content.value = { id: 'root', block: 'page', body: [] } as unknown as ContentTreeItem

    expect(findItemById('hero')).toBeNull()
  })

  it('returns the first match when an id appears twice', () => {
    const content = {
      id: 'root',
      block: 'page',
      first: { id: 'dup', block: 'a' },
      second: { id: 'dup', block: 'b' },
    } as unknown as ContentTreeItem

    expect(tree(content).findItemById('dup')?.parentKey).toBe('first')
  })
})

describe('buildBreadcrumbs', () => {
  it('lists the ancestors, excluding the item itself', () => {
    expect(tree().buildBreadcrumbs('deep')).toEqual([
      { id: 'root', label: 'page' },
      { id: 'section', label: 'section' },
      { id: 'card-2', label: 'card' },
    ])
  })

  it('labels each crumb by block slug, not id', () => {
    expect(tree().buildBreadcrumbs('hero')).toEqual([{ id: 'root', label: 'page' }])
  })

  it('is empty for an unknown id', () => {
    expect(tree().buildBreadcrumbs('nope')).toEqual([])
  })

  it('is empty for the root', () => {
    expect(tree().buildBreadcrumbs('root')).toEqual([])
  })
})

describe('updateItem', () => {
  it('writes the update into the live tree and reports success', () => {
    const content = root()
    const { updateItem } = useContentTree(content, {} as ContentBlock)

    expect(
      updateItem('hero', { id: 'hero', block: 'hero', headline: 'Updated' } as ContentTreeItem)
    ).toBe(true)
    expect((content.body as ContentTreeItem[])[0].headline).toBe('Updated')
  })

  it('replaces in place, keeping the node identity for its parent slot', () => {
    const content = root()
    const original = (content.body as ContentTreeItem[])[0]

    useContentTree(content, {} as ContentBlock).updateItem('hero', {
      id: 'hero',
      block: 'hero',
      subline: 'New',
    } as ContentTreeItem)

    expect((content.body as ContentTreeItem[])[0]).toBe(original)
    expect(original).toEqual({ id: 'hero', block: 'hero', subline: 'New' })
  })

  // Removals are the point of replace semantics: the editor's out-of-schema
  // cleanup deletes keys and routes the full item through here. A merge
  // (Object.assign alone) cannot drop a key, so focused items kept them.
  it('drops keys that are absent from the update', () => {
    const content = root()
    ;(content.body as ContentTreeItem[])[0].legacy = 'stale'

    useContentTree(content, {} as ContentBlock).updateItem('hero', {
      id: 'hero',
      block: 'hero',
      headline: 'Hi',
    } as ContentTreeItem)

    expect((content.body as ContentTreeItem[])[0]).not.toHaveProperty('legacy')
  })

  it('updates a deeply nested item in place', () => {
    const content = root()

    useContentTree(content, {} as ContentBlock).updateItem('deep', {
      id: 'deep',
      block: 'note',
      text: 'Note',
    } as ContentTreeItem)

    const section = (content.body as ContentTreeItem[])[1]

    expect(((section.items as ContentTreeItem[])[1].nested as ContentTreeItem).text).toBe('Note')
  })

  it('reports failure and changes nothing for an unknown id', () => {
    const content = root()

    expect(
      useContentTree(content, {} as ContentBlock).updateItem('nope', {} as ContentTreeItem)
    ).toBe(false)
    expect(content).toEqual(root())
  })

  it('reports failure for the root', () => {
    expect(tree().updateItem('root', {} as ContentTreeItem)).toBe(false)
  })
})
