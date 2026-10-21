import { describe, expect, it } from 'vitest'

import { indexTreeRows, resolveStickyRows } from '~/lib/sticky-tree'

// A
//   B
//     C
//     D
//   E
// F
const rows = [
  { id: 'A', level: 1 },
  { id: 'B', level: 2 },
  { id: 'C', level: 3 },
  { id: 'D', level: 3 },
  { id: 'E', level: 2 },
  { id: 'F', level: 1 },
]
const index = indexTreeRows(rows)

const resolve = (scrollTop: number, max = 5) =>
  resolveStickyRows(rows.length, index, {
    scrollTop,
    slotHeight: 10,
    max,
    rowTop: (row) => row * 10,
    rowBottom: (row) => row * 10 + 10,
  })

describe('indexTreeRows', () => {
  it('maps each row to its parent and last descendant', () => {
    expect([...index.parent]).toEqual([-1, 0, 1, 1, 0, -1])
    expect([...index.end]).toEqual([4, 3, 2, 3, 4, 5])
  })
})

describe('resolveStickyRows', () => {
  it('pins nothing while the tree is scrolled to the top', () => {
    expect(resolve(0)).toEqual([])
  })

  it('pins the ancestors of the rows under the sticky area, root first', () => {
    expect(resolve(15)).toEqual([
      { index: 0, top: 0 },
      { index: 1, top: 10 },
    ])
  })

  it('pushes an ancestor up as the end of its subtree scrolls past', () => {
    expect(resolve(42)).toEqual([{ index: 0, top: -2 }])
  })

  it('keeps the outermost ancestors when capped', () => {
    expect(resolve(15, 1)).toEqual([{ index: 0, top: 0 }])
  })
})
