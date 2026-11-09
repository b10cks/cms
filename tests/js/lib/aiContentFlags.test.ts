import { describe, expect, it } from 'vitest'

import { flagFieldLink, flagFieldTarget, flagTotals } from '~/lib/aiContentFlags'

describe('aiContentFlags', () => {
  it('adds up the figures of the entries that have any', () => {
    const flags = { a: { conflicts: 1 }, b: { conflicts: 2 }, c: { conflicts: 5 } }

    expect(flagTotals(flags, ['a', 'b', 'd'])).toEqual({ conflicts: 3, entries: 2 })
    expect(flagTotals(undefined, ['a'])).toEqual({ conflicts: 0, entries: 0 })
  })

  it('points a stored path at the block and field to open', () => {
    expect(flagFieldTarget('title', 'root')).toEqual({ itemId: 'root', path: ['title'] })
    expect(flagFieldTarget('body.01block.title', 'root')).toEqual({ itemId: '01block', path: ['title'] })
    expect(flagFieldTarget('body.01a.items.01b.text', 'root')).toEqual({ itemId: '01b', path: ['text'] })
    expect(flagFieldLink('s', 'c', 'body.01block.title')).toBe('/s/content/c?field=body.01block.title')
    expect(flagFieldLink('s', 'c', null)).toBe('/s/content/c')
  })
})
