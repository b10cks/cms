import { describe, expect, it } from 'vitest'

import { moveInList, sortableOptions } from '~/lib/sortable'

describe('moveInList', () => {
  it('moves a row one step either way in place', () => {
    const rows = ['a', 'b', 'c']

    expect(moveInList(rows, 0, 1)).toBe(true)
    expect(rows).toEqual(['b', 'a', 'c'])
    expect(moveInList(rows, 2, -1)).toBe(true)
    expect(rows).toEqual(['b', 'c', 'a'])
  })

  it('refuses to move past either end', () => {
    const rows = ['a', 'b']

    expect(moveInList(rows, 0, -1)).toBe(false)
    expect(moveInList(rows, 1, 1)).toBe(false)
    expect(rows).toEqual(['a', 'b'])
  })
})

describe('sortableOptions', () => {
  it('drags by the given grip with a fallback clone, and lets a list override the rest', () => {
    const options = sortableOptions({ handle: '[data-x]', draggable: '>[data-row]' }) as Record<
      string,
      unknown
    >

    expect(options).toMatchObject({
      handle: '[data-x]',
      draggable: '>[data-row]',
      forceFallback: true,
      fallbackOnBody: true,
    })
  })
})
