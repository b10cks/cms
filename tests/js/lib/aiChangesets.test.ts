import { describe, expect, it } from 'vitest'

import type { AiChangesetItem } from '~/lib/aiChangesets'
import { blockListChanges, createdBlockFields, entryLink, groupChangesetItems } from '~/lib/aiChangesets'

const item = (overrides: Partial<AiChangesetItem>): AiChangesetItem => ({
  id: 'item',
  operation: 'update',
  target_type: 'content',
  target_id: 'entry-1',
  target_label: 'Home',
  base_version_id: 'v1',
  path: 'title',
  field_type: 'text',
  before: 'Hello',
  after: 'Hello there',
  final: null,
  current: null,
  status: 'pending',
  glossary_findings: [],
  decided_at: null,
  ...overrides,
})

describe('groupChangesetItems', () => {
  it('groups items per entry in arrival order and keeps each new entry on its own', () => {
    const entries = groupChangesetItems([
      item({ id: 'a', path: 'title' }),
      item({ id: 'b', target_id: 'entry-2', target_label: 'About' }),
      item({ id: 'c', path: 'summary' }),
      item({ id: 'd', operation: 'create', target_id: null, target_label: 'New page', path: null }),
      item({ id: 'e', operation: 'create', target_id: null, target_label: 'Other page', path: null }),
    ])

    expect(entries.map((entry) => entry.label)).toEqual(['Home', 'About', 'New page', 'Other page'])
    expect(entries[0].items.map((entry) => entry.id)).toEqual(['a', 'c'])
  })
})

describe('blockListChanges', () => {
  it('lists added, changed and removed blocks by id and skips unchanged ones', () => {
    const before = [
      { id: 'a', block: 'hero', heading: 'One' },
      { id: 'b', block: 'hero', heading: 'Two' },
      { id: 'c', block: 'quote', text: 'Gone' },
    ]
    const after = [
      { id: 'a', block: 'hero', heading: 'One' },
      { id: 'n', block: 'hero', heading: 'New' },
      { id: 'b', block: 'hero', heading: 'Two, better' },
    ]

    const changes = blockListChanges(before, after)

    expect(changes.map((change) => [change.id, change.type])).toEqual([
      ['n', 'added'],
      ['b', 'changed'],
      ['c', 'removed'],
    ])
    expect(changes[1].children).toEqual([
      { type: 'changed', path: 'heading', oldValue: 'Two', newValue: 'Two, better' },
    ])
  })
})

describe('block proposals', () => {
  it('links blocks to the Block library and lists the fields of a new block', () => {
    expect(entryLink('space-1', { targetType: 'block', targetId: 'block-1' })).toBe('/space-1/blocks/block-1')
    expect(entryLink('space-1', { targetType: 'block', targetId: null })).toBeNull()

    const created = item({
      operation: 'create',
      target_type: 'block',
      target_id: null,
      path: null,
      before: null,
      after: { name: 'Teaser', schema: { heading: { type: 'text', name: 'Heading' }, image: { type: 'asset' } } },
    })
    expect(createdBlockFields(created)).toEqual([
      { key: 'heading', name: 'Heading', type: 'text' },
      { key: 'image', name: 'image', type: 'asset' },
    ])
  })
})
