import { describe, expect, it } from 'vitest'

import { computeObjectDiff } from '~/utils/object-diff'

const byPath = (changes: ReturnType<typeof computeObjectDiff>) =>
  [...changes].sort((left, right) => left.path.localeCompare(right.path))

describe('computeObjectDiff', () => {
  it('reports no changes for identical objects', () => {
    expect(computeObjectDiff({ a: 1, b: 'x' }, { a: 1, b: 'x' })).toEqual([])
  })

  it('reports added keys', () => {
    expect(computeObjectDiff({}, { a: 1 })).toEqual([{ type: 'added', path: 'a', newValue: 1 }])
  })

  it('reports removed keys', () => {
    expect(computeObjectDiff({ a: 1 }, {})).toEqual([{ type: 'removed', path: 'a', oldValue: 1 }])
  })

  it('reports changed scalars', () => {
    expect(computeObjectDiff({ a: 1 }, { a: 2 })).toEqual([
      { type: 'changed', path: 'a', oldValue: 1, newValue: 2 },
    ])
  })

  it('distinguishes an explicit undefined value from a missing key', () => {
    expect(computeObjectDiff({ a: undefined }, {})).toEqual([
      { type: 'removed', path: 'a', oldValue: undefined },
    ])
    expect(computeObjectDiff({ a: undefined }, { a: undefined })).toEqual([])
  })

  it('recurses into plain objects and emits dotted paths', () => {
    expect(computeObjectDiff({ seo: { title: 'a', desc: 'x' } }, { seo: { title: 'b', desc: 'x' } })).toEqual([
      { type: 'changed', path: 'seo.title', oldValue: 'a', newValue: 'b' },
    ])
  })

  it('recurses several levels deep', () => {
    expect(computeObjectDiff({ a: { b: { c: 1 } } }, { a: { b: { c: 2 } } })).toEqual([
      { type: 'changed', path: 'a.b.c', oldValue: 1, newValue: 2 },
    ])
  })

  it('treats arrays as opaque values rather than recursing into them', () => {
    expect(computeObjectDiff({ tags: ['a'] }, { tags: ['a', 'b'] })).toEqual([
      { type: 'changed', path: 'tags', oldValue: ['a'], newValue: ['a', 'b'] },
    ])
    expect(computeObjectDiff({ tags: ['a', 'b'] }, { tags: ['a', 'b'] })).toEqual([])
  })

  it('reports a type flip between object and array as a single change', () => {
    expect(computeObjectDiff({ a: { b: 1 } }, { a: [1] })).toEqual([
      { type: 'changed', path: 'a', oldValue: { b: 1 }, newValue: [1] },
    ])
  })

  it('reports null replacing an object as a change, not a recursion', () => {
    expect(computeObjectDiff({ a: { b: 1 } }, { a: null })).toEqual([
      { type: 'changed', path: 'a', oldValue: { b: 1 }, newValue: null },
    ])
  })

  it('is insensitive to key order within an object value', () => {
    expect(computeObjectDiff({ a: { x: 1, y: 2 } }, { a: { y: 2, x: 1 } })).toEqual([])
  })

  it('is sensitive to key order inside an array value — JSON.stringify decides', () => {
    expect(computeObjectDiff({ a: [{ x: 1, y: 2 }] }, { a: [{ y: 2, x: 1 }] })).toHaveLength(1)
  })

  it('treats null and undefined inputs as empty objects', () => {
    expect(computeObjectDiff(null, null)).toEqual([])
    expect(computeObjectDiff(undefined, { a: 1 })).toEqual([
      { type: 'added', path: 'a', newValue: 1 },
    ])
    expect(computeObjectDiff({ a: 1 }, null)).toEqual([
      { type: 'removed', path: 'a', oldValue: 1 },
    ])
  })

  it('honours the prefix argument', () => {
    expect(computeObjectDiff({ a: 1 }, { a: 2 }, 'content')).toEqual([
      { type: 'changed', path: 'content.a', oldValue: 1, newValue: 2 },
    ])
  })

  it('collects added, removed and changed keys in one pass', () => {
    expect(byPath(computeObjectDiff({ keep: 1, drop: 2, edit: 3 }, { keep: 1, edit: 4, add: 5 }))).toEqual([
      { type: 'added', path: 'add', newValue: 5 },
      { type: 'removed', path: 'drop', oldValue: 2 },
      { type: 'changed', path: 'edit', oldValue: 3, newValue: 4 },
    ])
  })
})
