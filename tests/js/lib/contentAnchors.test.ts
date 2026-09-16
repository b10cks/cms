import { describe, expect, it } from 'vitest'

import { collectBlockItems } from '~/lib/contentAnchors'

const summarize = (content: unknown) =>
  collectBlockItems(content).map(({ id, depth }) => ({ id, depth }))

describe('collectBlockItems', () => {
  it('lists nested block items in document order with their depth', () => {
    const content = {
      block: 'page',
      id: 'root',
      body: [
        { id: 'hero', block: 'hero', title: 'Welcome' },
        {
          id: 'columns',
          block: 'columns',
          items: [{ id: 'left', block: 'text' }, { id: 'right', block: 'text' }],
        },
      ],
      footer: [{ id: 'cta', block: 'cta' }],
    }

    expect(summarize(content)).toEqual([
      { id: 'hero', depth: 0 },
      { id: 'columns', depth: 0 },
      { id: 'left', depth: 1 },
      { id: 'right', depth: 1 },
      { id: 'cta', depth: 0 },
    ])
  })

  it('skips hidden items together with their children', () => {
    const content = {
      body: [
        { id: 'hidden', block: 'section', hidden: true, items: [{ id: 'inner', block: 'text' }] },
        { id: 'visible', block: 'section' },
      ],
    }

    expect(summarize(content)).toEqual([{ id: 'visible', depth: 0 }])
  })

  it('ignores records that are not block items', () => {
    const content = {
      image: { id: 'asset-1', filename: 'a.jpg' },
      link: { type: 'internal', content: 'other-page' },
      table: { rows: [{ id: 'row-1', cells: [] }] },
      body: [{ id: 'hero', block: 'hero', image: { id: 'asset-2' } }],
    }

    expect(summarize(content)).toEqual([{ id: 'hero', depth: 0 }])
  })

  it('returns nothing for an empty or missing content', () => {
    expect(collectBlockItems({})).toEqual([])
    expect(collectBlockItems([])).toEqual([])
    expect(collectBlockItems(null)).toEqual([])
  })
})
