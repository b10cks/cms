import { describe, expect, it } from 'vitest'

import type { ContentTreeItem } from '~/composables/useContentTree'
import {
  findHiddenBlockIds,
  isBlockAllowed,
  planBlockAction,
  planBlockMove,
  planFieldUpdate,
  resolveFieldTarget,
  type BlockListRules,
  type BlockListRulesLookup,
} from '~/lib/blockTreeEdits'

/**
 * page
 * ├─ body: hero-1, list-1 (items: item-a, item-b, item-c), teaser-1
 * └─ aside: teaser-2
 */
const tree = (): ContentTreeItem => ({
  id: 'entry-1',
  block: 'page',
  title: 'Home',
  body: [
    { id: 'hero-1', block: 'hero', headline: 'Hi' },
    {
      id: 'list-1',
      block: 'list',
      items: [
        { id: 'item-a', block: 'item', title: 'A' },
        { id: 'item-b', block: 'item', title: 'B' },
        { id: 'item-c', block: 'item', title: 'C' },
      ],
    },
    { id: 'teaser-1', block: 'teaser' },
  ],
  aside: [{ id: 'teaser-2', block: 'teaser' }],
})

const open: BlockListRules = { min: null, max: null, accepts: () => true }
const rules =
  (byField: Record<string, Partial<BlockListRules>> = {}): BlockListRulesLookup =>
  (_parent, field) => ({ ...open, ...byField[field] })

let counter = 0
const newId = () => `new-${++counter}`

const ids = (items: unknown) => (items as ContentTreeItem[]).map((item) => item.id)

describe('resolveFieldTarget', () => {
  it('opens the innermost block the path passes through', () => {
    expect(resolveFieldTarget(tree(), 'list-1', ['items', 2, 'title'])).toEqual({
      itemId: 'item-c',
      field: 'title',
      fieldPath: ['body', 1, 'items', 2, 'title'],
    })
  })

  it('addresses root fields from the root', () => {
    expect(resolveFieldTarget(tree(), 'entry-1', ['title'])).toEqual({
      itemId: 'entry-1',
      field: 'title',
      fieldPath: ['title'],
    })
  })

  it('returns null for an unknown block', () => {
    expect(resolveFieldTarget(tree(), 'nope', ['title'])).toBeNull()
  })
})

describe('planFieldUpdate', () => {
  it('edits a field of a nested block reached through the path', () => {
    const root = tree()
    const itemB = ((root.body as ContentTreeItem[])[1].items as ContentTreeItem[])[1]

    const edit = planFieldUpdate(root, 'list-1', ['items', 1, 'title'], 'Bee')

    expect(edit).toEqual({
      block: itemB,
      field: 'title',
      previous: 'B',
      next: 'Bee',
      fieldPath: ['body', 1, 'items', 1, 'title'],
    })
  })

  it('replaces a value inside a field as an edit of the whole field', () => {
    const root = tree()
    root.title = { de: 'Start', en: 'Home' }

    expect(planFieldUpdate(root, 'entry-1', ['title', 'en'], 'Welcome')).toMatchObject({
      block: root,
      field: 'title',
      next: { de: 'Start', en: 'Welcome' },
      fieldPath: ['title'],
    })
  })

  it('skips values that are already there and paths without a field', () => {
    expect(planFieldUpdate(tree(), 'hero-1', ['headline'], 'Hi')).toBeNull()
    expect(planFieldUpdate(tree(), 'nope', ['headline'], 'Hey')).toBeNull()
    expect(planFieldUpdate(tree(), 'list-1', [0], 'Hey')).toBeNull()
  })
})

describe('planBlockAction', () => {
  it('reorders on move-down and does nothing past the start', () => {
    const plan = planBlockAction(tree(), 'item-a', 'move-down', rules(), newId)

    expect(plan?.kind === 'edit' && plan.edits[0]).toMatchObject({
      field: 'items',
      operation: { type: 'reorder', order: ['item-b', 'item-a', 'item-c'] },
    })
    expect(planBlockAction(tree(), 'item-a', 'move-up', rules(), newId)).toBeNull()
  })

  it('duplicates with fresh ids for the block and its nested blocks, and selects the copy', () => {
    const plan = planBlockAction(tree(), 'list-1', 'duplicate', rules(), newId)
    if (plan?.kind !== 'edit') throw new Error('expected an edit')

    const [edit] = plan.edits
    const copy = edit.next[2]
    expect(ids(edit.next)).toEqual(['hero-1', 'list-1', copy.id, 'teaser-1'])
    expect(copy.id).not.toBe('list-1')
    expect(ids(copy.items)).not.toContain('item-a')
    expect(plan.select).toBe(copy.id)
    expect(edit.operation).toEqual({ type: 'add', index: 2, items: [copy] })
  })

  it('removes on delete', () => {
    const plan = planBlockAction(tree(), 'hero-1', 'delete', rules(), newId)

    expect(plan?.kind === 'edit' && plan.edits[0].operation).toEqual({
      type: 'remove',
      itemIds: ['hero-1'],
    })
  })

  it('opens the picker after the block on insert-after', () => {
    const plan = planBlockAction(tree(), 'item-b', 'insert-after', rules(), newId)

    expect(plan?.kind === 'pick' && plan.slot).toMatchObject({ field: 'items', index: 2 })
  })

  it('hides and shows with the visibility op, and does nothing when already in that state', () => {
    const root = tree()
    const hide = planBlockAction(root, 'item-b', 'hide', rules(), newId)
    if (hide?.kind !== 'edit') throw new Error('expected an edit')

    const [edit] = hide.edits
    expect(edit.operation).toEqual({ type: 'visibility', itemId: 'item-b', hidden: true })
    expect(edit.next[1]).toEqual({ id: 'item-b', block: 'item', title: 'B', hidden: true })
    expect(planBlockAction(root, 'item-b', 'show', rules(), newId)).toBeNull()

    edit.parent[edit.field] = edit.next
    expect(planBlockAction(root, 'item-b', 'hide', rules(), newId)).toBeNull()
    const show = planBlockAction(root, 'item-b', 'show', rules(), newId)
    expect(show?.kind === 'edit' && show.edits[0].operation).toEqual({
      type: 'visibility',
      itemId: 'item-b',
      hidden: false,
    })
  })

  it.each([
    ['duplicate', { max: 3 }],
    ['insert-before', { max: 3 }],
    ['delete', { min: 3 }],
  ] as const)('rejects %s beyond the field limits', (action, limits) => {
    expect(planBlockAction(tree(), 'item-a', action, rules({ items: limits }), newId)).toEqual({
      kind: 'rejected',
    })
  })
})

describe('findHiddenBlockIds', () => {
  it('lists hidden blocks at any depth in document order', () => {
    const root = tree()
    const [hero, list] = root.body as ContentTreeItem[]
    const [, itemB] = list.items as ContentTreeItem[]
    list.hidden = true
    itemB.hidden = true
    hero.hidden = false

    expect(findHiddenBlockIds(root)).toEqual(['list-1', 'item-b'])
    expect(findHiddenBlockIds(tree())).toEqual([])
  })
})

describe('planBlockMove', () => {
  it('reorders within a list', () => {
    const plan = planBlockMove(tree(), 'item-c', 'item-a', 'before', rules())

    expect(plan?.kind === 'edit' && ids(plan.edits[0].next)).toEqual(['item-c', 'item-a', 'item-b'])
  })

  it('does nothing when the order would not change', () => {
    expect(planBlockMove(tree(), 'item-b', 'item-a', 'after', rules())).toBeNull()
  })

  it('moves across lists as a remove and an add', () => {
    const plan = planBlockMove(tree(), 'teaser-2', 'hero-1', 'after', rules())
    if (plan?.kind !== 'edit') throw new Error('expected an edit')

    expect(plan.edits.map(({ field, next }) => [field, ids(next)])).toEqual([
      ['aside', []],
      ['body', ['hero-1', 'teaser-2', 'list-1', 'teaser-1']],
    ])
    expect(plan.edits.map(({ operation }) => operation.type)).toEqual(['remove', 'add'])
  })

  it.each([
    ['a block the target field does not allow', rules({ items: { accepts: () => false } })],
    ['into a full list', rules({ items: { max: 3 } })],
    ['out of a list at its minimum', rules({ body: { min: 3 } })],
  ])('rejects moving %s', (_label, lookup) => {
    expect(planBlockMove(tree(), 'teaser-1', 'item-a', 'before', lookup)).toEqual({
      kind: 'rejected',
    })
  })

  it('rejects moving a block into its own subtree', () => {
    expect(planBlockMove(tree(), 'list-1', 'item-a', 'before', rules())).toEqual({
      kind: 'rejected',
    })
  })
})

describe('isBlockAllowed', () => {
  const block = (slug: string, type = 'nestable', tags: string[] = []) =>
    ({ slug, type, tags }) as Pick<BlockResource, 'slug' | 'type' | 'tags'>

  it('allows any nestable block without allow lists', () => {
    expect(isBlockAllowed({ block_whitelist: [], tag_whitelist: [] }, block('hero'))).toBe(true)
    expect(isBlockAllowed({ block_whitelist: [], tag_whitelist: [] }, block('page', 'root'))).toBe(
      false
    )
  })

  it('narrows to listed blocks or tags', () => {
    const field = { block_whitelist: ['hero'], tag_whitelist: ['cards'] }

    expect(isBlockAllowed(field, block('hero'))).toBe(true)
    expect(isBlockAllowed(field, block('teaser', 'nestable', ['cards']))).toBe(true)
    expect(isBlockAllowed(field, block('teaser'))).toBe(false)
  })
})
