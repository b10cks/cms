import { describe, expect, it } from 'vitest'

import type { AiChangesetItem } from '~/lib/aiChangesets'
import {
  blockListChanges,
  createdBlockFields,
  entryLink,
  groupChangesetItems,
  attentionFirst,
  changesSummary,
  entryHistoryLink,
  needsIndividualLook,
  overrideErrors,
  translationUnitValue,
} from '~/lib/aiChangesets'

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

describe('translationUnitValue', () => {
  it('reads units inside block lists, table cells and new entries', () => {
    const blocks = item({
      path: 'body',
      field_type: 'blocks',
      before: [{ id: 'b1', block: 'hero', heading: 'Hallo' }],
      after: [{ id: 'b1', block: 'hero', heading: 'Hallo Welt' }],
    })
    const table = item({ path: 'prices', field_type: 'table', before: null, after: { rows: [{ id: 'r1', cells: { label: 'Preis' } }] } })
    const created = item({ operation: 'create', path: null, before: null, after: { name: 'Start', content: { title: 'Willkommen' } } })

    expect(translationUnitValue(blocks, 'body.b1.heading', 'before')).toBe('Hallo')
    expect(translationUnitValue(blocks, 'body.b1.heading', 'after')).toBe('Hallo Welt')
    expect(translationUnitValue(table, 'prices.rows.r1.label', 'after')).toBe('Preis')
    expect(translationUnitValue(created, 'title', 'after')).toBe('Willkommen')
    expect(translationUnitValue(created, 'title', 'before')).toBeUndefined()
  })
})

describe('review helpers', () => {
  it('leaves a glossary hint in a bulk accept and a violation of a proposal out of it', () => {
    const asset = { target_type: 'asset' as const }
    expect(needsIndividualLook(item({ ...asset, glossary_findings: [{ id: 'h', type: 'missing_term', term: 'a', expected: 'b' }] }))).toBe(false)
    expect(needsIndividualLook(item({ ...asset, glossary_findings: [{ id: 'v', type: 'translated_do_not_translate', term: 'a', expected: 'a' }] }))).toBe(true)
    // Content has a draft, so a finding holds nothing back.
    expect(needsIndividualLook(item({ target_type: 'content', glossary_findings: [{ id: 'v', type: 'translated_do_not_translate', term: 'a', expected: 'a' }] }))).toBe(false)
  })

  it('sums up what happened to content and puts what needs a person first', () => {
    const flagged = { id: 'v', type: 'forbidden_variant' as const, term: 'a', found: 'b' }
    const items = [
      item({ id: '1', target_type: 'content', target_id: 'a', status: 'accepted' }),
      item({ id: '2', target_type: 'content', target_id: 'a', status: 'accepted', glossary_findings: [flagged] }),
      item({ id: '3', target_type: 'content', target_id: 'b', status: 'conflict' }),
      item({ id: '4', target_type: 'asset', target_id: 'c', status: 'pending', glossary_findings: [flagged] }),
    ]

    expect(changesSummary(items)).toEqual({ changed: 1, conflicts: 1, flagged: 1 })
    expect(attentionFirst(groupChangesetItems(items)).map((entry) => entry.targetId)).toEqual(['b', 'a', 'c'])
    expect(entryHistoryLink('space-1', { targetType: 'content', targetId: 'a' })).toBe('/space-1/content/a/versions')
    expect(entryHistoryLink('space-1', { targetType: 'asset', targetId: 'c' })).toBeNull()
  })

  it('reads missing overrides from a 422 and nothing else from it', () => {
    const error = { data: { errors: { 'overrides.item-1.abc': ['Needs a reason.'], 'overrides.item-1.0.reason': ['Required.'], items: ['Invalid.'] } } }

    expect(overrideErrors(error)).toEqual({ 'item-1': [{ id: 'abc', message: 'Needs a reason.' }] })
    expect(overrideErrors(new Error('Server error'))).toEqual({})
  })
})
