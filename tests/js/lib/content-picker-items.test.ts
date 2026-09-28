import { describe, expect, it } from 'vitest'

import { contentPickerItems } from '~/lib/content-picker-items'

const item = (id: string, position: number, pid: string | null = null, type = 'nestable') =>
  ({ id, name: id, position, pid, type }) as FlatContentMenuItem

describe('contentPickerItems', () => {
  it('keeps position order, depth, and single roots after other roots', () => {
    const menu = {
      first: item('first', 1),
      second: item('second', 2),
      single: item('single', 0, null, 'single'),
      childB: item('childB', 2, 'first'),
      childA: item('childA', 1, 'first'),
      grandchild: item('grandchild', 0, 'childA'),
      orphan: item('orphan', 0, 'missing'),
    }

    expect(contentPickerItems(menu).map(({ id, level }) => [id, level])).toEqual([
      ['first', 0],
      ['childA', 1],
      ['grandchild', 2],
      ['childB', 1],
      ['second', 0],
      ['single', 0],
    ])
  })

  it('shares a menu version and recomputes after replacement', () => {
    const first = { root: item('root', 0) }
    const second = { ...first, child: item('child', 0, 'root') }

    expect(contentPickerItems(first)).toBe(contentPickerItems(first))
    expect(contentPickerItems(second).map(({ id }) => id)).toEqual(['root', 'child'])
  })
})
