import { describe, expect, it } from 'vitest'

import { toDeliveredContent } from '~/lib/previewDelivery'

const page = () => ({
  id: 'entry-1',
  block: 'page',
  body: [
    { id: 'hero-1', block: 'hero', hidden: true },
    {
      id: 'list-1',
      block: 'list',
      items: [
        { id: 'item-a', block: 'item' },
        { id: 'item-b', block: 'item', hidden: true },
      ],
    },
  ],
  tags: ['a', 'b'],
})

describe('toDeliveredContent', () => {
  describe('hidden blocks', () => {
    it('drops hidden blocks at any depth when the space filters them', () => {
      const input = page()

      expect(toDeliveredContent(input, { filterHiddenBlocks: true })).toEqual({
        id: 'entry-1',
        block: 'page',
        body: [{ id: 'list-1', block: 'list', items: [{ id: 'item-a', block: 'item' }] }],
        tags: ['a', 'b'],
      })
      expect(input).toEqual(page())
    })

    it('keeps hidden blocks for the preview to dim when the space delivers them', () => {
      expect(toDeliveredContent(page(), { filterHiddenBlocks: false })).toEqual(page())
    })
  })
})
