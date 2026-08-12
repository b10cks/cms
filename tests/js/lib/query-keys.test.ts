import { describe, expect, it } from 'vitest'
import { ref } from 'vue'

import {
  entityKeys,
  listKeys,
  nestedEntityKeys,
  spaceEntityKeys,
  spaceListKeys,
} from '~/lib/query-keys'

const SPACE = 'space-1'

/**
 * `invalidateQueries` matches by prefix, so the coarse levels must literally
 * lead the fine ones. Everything else in the app depends on that.
 */
const isPrefixOf = (prefix: readonly unknown[], key: readonly unknown[]) =>
  prefix.length <= key.length && prefix.every((part, index) => Object.is(part, key[index]))

describe('listKeys', () => {
  const keys = listKeys(() => ['root'] as const)

  it('builds all/lists/list off the given root', () => {
    expect(keys.all()).toEqual(['root'])
    expect(keys.lists()).toEqual(['root', 'list'])
    expect(keys.list({ page: 2 })).toEqual(['root', 'list', { page: 2 }])
  })

  it('defaults the filters slot to an empty object rather than dropping it', () => {
    // A key one element shorter would land in a different cache entry than the
    // same call made with explicit filters.
    expect(keys.list()).toEqual(['root', 'list', {}])
  })

  it('keeps each level a prefix of the next', () => {
    expect(isPrefixOf(keys.all(), keys.lists())).toBe(true)
    expect(isPrefixOf(keys.lists(), keys.list({ q: 'a' }))).toBe(true)
  })

  it('re-reads the root on every call, so a reactive root is not frozen in', () => {
    const spaceId = ref('a')
    const reactive = listKeys(() => ['spaces', spaceId.value] as const)

    expect(reactive.lists()).toEqual(['spaces', 'a', 'list'])
    spaceId.value = 'b'
    expect(reactive.lists()).toEqual(['spaces', 'b', 'list'])
  })
})

describe('entityKeys', () => {
  const keys = entityKeys(() => ['spaces'] as const)

  it('adds the detail branch alongside the list branch', () => {
    expect(keys.all()).toEqual(['spaces'])
    expect(keys.details()).toEqual(['spaces', 'detail'])
    expect(keys.detail('x')).toEqual(['spaces', 'detail', 'x'])
  })

  it('keeps details a prefix of detail, and all a prefix of both', () => {
    expect(isPrefixOf(keys.details(), keys.detail('x'))).toBe(true)
    expect(isPrefixOf(keys.all(), keys.detail('x'))).toBe(true)
  })

  it('stores a ref id unwrapped-by-reference so vue-query can track it', () => {
    const id = ref('x')
    expect(keys.detail(id)[2]).toBe(id)
  })

  it('does not share state between the list and detail branches', () => {
    expect(isPrefixOf(keys.lists(), keys.detail('x'))).toBe(false)
  })
})

describe('spaceEntityKeys', () => {
  const keys = spaceEntityKeys('asset-tags')(SPACE)

  it('roots the entity under ["spaces", spaceId, segment]', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'asset-tags'])
    expect(keys.lists()).toEqual(['spaces', SPACE, 'asset-tags', 'list'])
    expect(keys.list({ q: 'a' })).toEqual(['spaces', SPACE, 'asset-tags', 'list', { q: 'a' }])
    expect(keys.details()).toEqual(['spaces', SPACE, 'asset-tags', 'detail'])
    expect(keys.detail('t1')).toEqual(['spaces', SPACE, 'asset-tags', 'detail', 't1'])
  })

  it('is a factory: one segment binding serves many spaces', () => {
    const forSegment = spaceEntityKeys('redirects')
    expect(forSegment('a').all()).toEqual(['spaces', 'a', 'redirects'])
    expect(forSegment('b').all()).toEqual(['spaces', 'b', 'redirects'])
  })

  it('keeps the space id by reference when given a ref', () => {
    const spaceId = ref(SPACE)
    expect(spaceEntityKeys('assets')(spaceId).all()[1]).toBe(spaceId)
  })
})

describe('spaceListKeys', () => {
  it('omits the detail branch entirely', () => {
    const keys = spaceListKeys('members')(SPACE)

    expect(keys.all()).toEqual(['spaces', SPACE, 'members'])
    expect(keys.list()).toEqual(['spaces', SPACE, 'members', 'list', {}])
    expect('detail' in keys).toBe(false)
    expect('details' in keys).toBe(false)
  })
})

describe('nestedEntityKeys', () => {
  const keys = nestedEntityKeys('blocks', 'templates')(SPACE, 'block-1')

  it('threads the parent id between the two segments', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'blocks', 'block-1', 'templates'])
    expect(keys.detail('t1')).toEqual([
      'spaces',
      SPACE,
      'blocks',
      'block-1',
      'templates',
      'detail',
      't1',
    ])
  })

  it('sits under the parent record, so invalidating the parent cascades', () => {
    const parentDetail = ['spaces', SPACE, 'blocks', 'block-1']
    expect(isPrefixOf(parentDetail, keys.lists())).toBe(true)
  })

  it('separates siblings under the same parent', () => {
    const versions = nestedEntityKeys('blocks', 'versions')(SPACE, 'block-1')
    expect(isPrefixOf(keys.all(), versions.all())).toBe(false)
    expect(isPrefixOf(versions.all(), keys.all())).toBe(false)
  })
})
