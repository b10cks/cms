import { describe, expect, it } from 'vitest'

import { flagCountsLine, flagFieldLink, flagFieldTarget, flagTotals } from '~/lib/aiContentFlags'

describe('aiContentFlags', () => {
  it('adds up the figures of the entries that have any', () => {
    const flags = { a: { conflicts: 1, flagged: 0 }, b: { conflicts: 0, flagged: 2 }, c: { conflicts: 5, flagged: 5 } }

    expect(flagTotals(flags, ['a', 'b', 'd'])).toEqual({ conflicts: 1, flagged: 2, entries: 2 })
    expect(flagTotals(undefined, ['a'])).toEqual({ conflicts: 0, flagged: 0, entries: 0 })
  })

  it('names only what is there, in words', () => {
    const t = (key: string, { count }: Record<string, unknown>) => `${count} ${key.split('.').pop()}`

    expect(flagCountsLine(t, { conflicts: 1, flagged: 2 })).toBe('1 conflicts, 2 flagged')
    expect(flagCountsLine(t, { conflicts: 0, flagged: 1 })).toBe('1 flagged')
  })

  it('points a stored path at the block and field to open', () => {
    expect(flagFieldTarget('title', 'root')).toEqual({ itemId: 'root', path: ['title'] })
    expect(flagFieldTarget('body.01block.title', 'root')).toEqual({ itemId: '01block', path: ['title'] })
    expect(flagFieldTarget('body.01a.items.01b.text', 'root')).toEqual({ itemId: '01b', path: ['text'] })
    expect(flagFieldLink('s', 'c', 'body.01block.title')).toBe('/s/content/c?field=body.01block.title')
    expect(flagFieldLink('s', 'c', null)).toBe('/s/content/c')
  })
})
