import { describe, expect, it } from 'vitest'

import type { ContentTreeItem } from '~/composables/useContentTree'
import { locateComment, planFieldReveal, planRowReveal } from '~/lib/commentLocation'

const block = (id: string, slug: string, fields: Record<string, unknown> = {}) =>
  ({ id, block: slug, ...fields }) as ContentTreeItem

const root = block('root', 'page', {
  title: 'Home',
  body: [
    block('a', 'hero'),
    block('b', 'hero', {
      items: [
        block('c', 'card', {
          items: [
            block('d', 'card', {
              items: [block('e', 'card', { items: [block('f', 'card')] })],
            }),
          ],
        }),
      ],
    }),
  ],
})

const blocks = {
  page: { name: 'Page', schema: { title: { name: 'Title' }, body: { name: 'Body' } } },
  hero: { name: 'Hero', schema: { header: { name: 'Header' }, items: { name: 'Items' } } },
}

describe('locateComment', () => {
  const locate = (item_id: string | undefined, field: string | undefined) =>
    locateComment({ item_id, field }, root, blocks)

  it('names a block field by block, position and field', () => {
    expect(locate('b', 'header')).toEqual({
      kind: 'place',
      label: 'Hero 2, Header',
      itemId: 'b',
      field: 'header',
    })
  })

  it('names a field of the entry by the field alone', () => {
    expect(locate('root', 'title')).toMatchObject({ kind: 'place', label: 'Title', itemId: 'root' })
  })

  it('reads as the entry without a field', () => {
    expect(locate(undefined, undefined)).toEqual({ kind: 'entry' })
    expect(locate('root', undefined)).toEqual({ kind: 'entry' })
  })

  it('says so when the block or the field is gone', () => {
    expect(locate('gone', 'header')).toEqual({ kind: 'missing' })
    expect(locate('b', 'removed')).toEqual({ kind: 'missing' })
  })

  it('does not call a field gone when its block type is not loaded', () => {
    expect(locate('c', 'title')).toMatchObject({ kind: 'place', label: 'card 1, title' })
  })
})

describe('planFieldReveal', () => {
  it('stays in the column for the entry and the first two levels, expanding them', () => {
    expect(planFieldReveal(root, 'root', 'title')).toMatchObject({ openItem: null, expand: [] })
    expect(planFieldReveal(root, 'a', 'header')).toMatchObject({ openItem: null, expand: ['a'] })
    expect(planFieldReveal(root, 'c', 'x')).toMatchObject({ openItem: null, expand: ['b', 'c'] })
  })

  it('opens the third level through the hash route, where depth starts again', () => {
    expect(planFieldReveal(root, 'd', 'x')).toMatchObject({ openItem: 'd', expand: [] })
    expect(planFieldReveal(root, 'e', 'x')).toMatchObject({ openItem: 'd', expand: ['e'] })
    expect(planFieldReveal(root, 'f', 'x')).toMatchObject({ openItem: 'd', expand: ['e', 'f'] })
  })

  it('names the field to show at each level and the path of the target', () => {
    expect(planFieldReveal(root, 'c', 'x')).toMatchObject({
      pages: { root: 'body', b: 'items', c: 'x' },
      fieldPath: 'content.body.1.items.0.x',
    })
  })

  it('finds nothing for a block that is gone', () => {
    expect(planFieldReveal(root, 'gone', 'x')).toBeNull()
  })
})

describe('planRowReveal', () => {
  it('goes to the list holding the row and leaves the row closed', () => {
    expect(planRowReveal(root, 'c')).toMatchObject({
      row: 'c',
      itemId: 'b',
      field: 'items',
      openItem: null,
      expand: ['b'],
    })
  })

  it('stays in the entry for a row of its own list', () => {
    expect(planRowReveal(root, 'a')).toMatchObject({ row: 'a', itemId: 'root', expand: [] })
  })
})
