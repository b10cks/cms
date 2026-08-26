import { describe, expect, it } from 'vitest'

import {
  getEligibleChildContentBlocks,
  getRootCreateContentBlocks,
  resolveAllowedChildContentBlocks,
  resolveCreateContentBlocks,
  resolvePreferredCreateContentBlock,
} from '~/lib/content-children'

const block = (
  id: string,
  slug: string,
  type: BlockResource['type'],
  tags: string[] = []
): BlockResource =>
  ({
    id,
    slug,
    type,
    tags,
    name: slug,
    description: '',
    schema: {},
    editor: [],
    folder_id: null,
    created_at: '',
    updated_at: '',
  }) as BlockResource

const page = block('id-page', 'page', 'root')
const universal = block('id-universal', 'universal', 'universal', ['news'])
const nestable = block('id-nestable', 'hero', 'nestable')
const single = block('id-single', 'settings', 'single')
const blocks = [page, universal, nestable, single]

describe('getEligibleChildContentBlocks', () => {
  it('keeps only root and universal blocks', () => {
    expect(getEligibleChildContentBlocks(blocks).map((entry) => entry.slug)).toEqual([
      'page',
      'universal',
    ])
  })

  it('tolerates null and undefined', () => {
    expect(getEligibleChildContentBlocks(null)).toEqual([])
    expect(getEligibleChildContentBlocks(undefined)).toEqual([])
  })
})

describe('getRootCreateContentBlocks', () => {
  it('also allows single blocks at the root', () => {
    expect(getRootCreateContentBlocks(blocks).map((entry) => entry.slug)).toEqual([
      'page',
      'universal',
      'settings',
    ])
  })

  it('drops single blocks that already have an entry', () => {
    expect(
      getRootCreateContentBlocks(blocks, new Set(['id-single'])).map((entry) => entry.slug)
    ).toEqual(['page', 'universal'])
  })
})

describe('resolveAllowedChildContentBlocks', () => {
  it('returns every eligible block when restriction is off', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, { restrict_child_blocks: false }).map(
        (entry) => entry.slug
      )
    ).toEqual(['page', 'universal'])
  })

  it('ignores an enabled restriction with empty whitelists', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, {
        restrict_child_blocks: true,
        child_block_whitelist: [],
        child_tag_whitelist: [],
      }).map((entry) => entry.slug)
    ).toEqual(['page', 'universal'])
  })

  it('filters by the block slug whitelist', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, {
        restrict_child_blocks: true,
        child_block_whitelist: ['page'],
      }).map((entry) => entry.slug)
    ).toEqual(['page'])
  })

  it('filters by the tag whitelist', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, {
        restrict_child_blocks: true,
        child_tag_whitelist: ['news'],
      }).map((entry) => entry.slug)
    ).toEqual(['universal'])
  })

  it('unions the two whitelists', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, {
        restrict_child_blocks: true,
        child_block_whitelist: ['page'],
        child_tag_whitelist: ['news'],
      }).map((entry) => entry.slug)
    ).toEqual(['page', 'universal'])
  })

  it('drops falsy whitelist entries rather than treating them as a match', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, {
        restrict_child_blocks: true,
        child_block_whitelist: ['', 'page'] as string[],
      }).map((entry) => entry.slug)
    ).toEqual(['page'])
  })

  it('never surfaces a nestable block, whitelisted or not', () => {
    expect(
      resolveAllowedChildContentBlocks(blocks, {
        restrict_child_blocks: true,
        child_block_whitelist: ['hero'],
      })
    ).toEqual([])
  })
})

describe('resolveCreateContentBlocks', () => {
  it('applies parent restrictions for children', () => {
    expect(
      resolveCreateContentBlocks({
        blocks,
        isChild: true,
        parentSettings: { restrict_child_blocks: true, child_block_whitelist: ['page'] },
      }).map((entry) => entry.slug)
    ).toEqual(['page'])
  })

  it('ignores parent restrictions at the root', () => {
    expect(
      resolveCreateContentBlocks({
        blocks,
        isChild: false,
        parentSettings: { restrict_child_blocks: true, child_block_whitelist: ['page'] },
      }).map((entry) => entry.slug)
    ).toEqual(['page', 'universal', 'settings'])
  })
})

describe('resolvePreferredCreateContentBlock', () => {
  it('prefers the parent default when it is available', () => {
    expect(
      resolvePreferredCreateContentBlock({
        availableBlocks: [page, universal],
        parentSettings: { default_child_block: 'id-universal' },
        spaceDefaultBlockId: 'id-page',
      })
    ).toBe('id-universal')
  })

  it('falls back to the space default when the parent default is unavailable', () => {
    expect(
      resolvePreferredCreateContentBlock({
        availableBlocks: [page, universal],
        parentSettings: { default_child_block: 'id-nestable' },
        spaceDefaultBlockId: 'id-page',
      })
    ).toBe('id-page')
  })

  it('auto-selects the only option', () => {
    expect(resolvePreferredCreateContentBlock({ availableBlocks: [universal] })).toBe('id-universal')
  })

  it('returns an empty string when the choice is ambiguous', () => {
    expect(resolvePreferredCreateContentBlock({ availableBlocks: [page, universal] })).toBe('')
    expect(resolvePreferredCreateContentBlock({ availableBlocks: [] })).toBe('')
  })
})
