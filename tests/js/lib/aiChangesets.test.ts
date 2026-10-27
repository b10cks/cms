import { describe, expect, it } from 'vitest'

import type { AiChangesetItem } from '~/lib/aiChangesets'
import { groupChangesetItems } from '~/lib/aiChangesets'

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
