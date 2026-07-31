import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import type { ContentSettings } from '~/types/contents'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const { useContentMenu } = await import('~/composables/useContentMenu')

type MenuData = Record<string, FlatContentMenuItem>

const item = (
  id: string,
  overrides: Partial<FlatContentMenuItem> = {}
): FlatContentMenuItem =>
  ({
    id,
    name: id,
    slug: id,
    block_id: 'block-page',
    position: 0,
    type: 'nestable',
    color: null,
    pid: null,
    children: false,
    settings: {},
    i18n: [],
    pat: null,
    uat: '2026-07-01T00:00:00.000Z',
    ...overrides,
  }) as FlatContentMenuItem

const menu = (...items: FlatContentMenuItem[]): MenuData =>
  Object.fromEntries(items.map((entry) => [entry.id, entry]))

interface FakeChannel {
  name: string
  listeners: Map<string, (payload: unknown) => void>
}

const createFakeEcho = () => {
  const left: string[] = []
  const channels: FakeChannel[] = []

  const echo = {
    channel: (name: string) => {
      const channel: FakeChannel = { name, listeners: new Map() }
      channels.push(channel)

      const chainable = {
        listen: (event: string, callback: (payload: unknown) => void) => {
          channel.listeners.set(event, callback)
          return chainable
        },
      }

      return chainable
    },
    leave: (name: string) => {
      left.push(name)
    },
  }

  return { echo, left, channels }
}

type FakeEcho = ReturnType<typeof createFakeEcho>

let fake: FakeEcho
let harness: Harness<ReturnType<typeof useContentMenu>> | undefined

/** Every test gets a fresh space id: the subscriber ref-count is module state. */
let spaceCounter = 0
const nextSpace = () => `space-${++spaceCounter}`

const setup = (spaceId: MaybeRef<string>, seed?: [readonly unknown[], unknown][]) => {
  harness = withSetup(() => useContentMenu(spaceId), { seed })
  return harness
}

const menuOf = (harnessInstance: Harness<unknown>, spaceId: string) =>
  harnessInstance.queryClient.getQueryData(queryKeys.contentMenu(spaceId).all()) as MenuData

beforeEach(() => {
  fake = createFakeEcho()
  window.Echo = fake.echo as unknown as typeof window.Echo
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  Reflect.deleteProperty(window, 'Echo')
})

describe('findItemById', () => {
  const data = menu(item('a'), item('b'))

  it('returns the item stored under the id', () => {
    expect(setup(nextSpace()).result.findItemById(data, 'a')?.id).toBe('a')
  })

  it('returns null for an unknown id', () => {
    expect(setup(nextSpace()).result.findItemById(data, 'ghost')).toBeNull()
  })

  it('returns null without menu data', () => {
    expect(setup(nextSpace()).result.findItemById(undefined, 'a')).toBeNull()
  })

  it('unwraps a ref id', () => {
    expect(setup(nextSpace()).result.findItemById(data, ref('b'))?.id).toBe('b')
  })
})

describe('getRootItems', () => {
  it('returns only items without a parent', () => {
    const data = menu(item('a'), item('child', { pid: 'a' }))

    expect(setup(nextSpace()).result.getRootItems(data).map((entry) => entry.id)).toEqual(['a'])
  })

  it('pushes single-type content behind everything else', () => {
    const data = menu(
      item('settings', { type: 'single', position: 0 }),
      item('home', { position: 1 })
    )

    expect(setup(nextSpace()).result.getRootItems(data).map((entry) => entry.id)).toEqual([
      'home',
      'settings',
    ])
  })

  it('orders roots by position, then name, then id', () => {
    const data = menu(
      item('z', { position: 2 }),
      item('b', { position: 1, name: 'B' }),
      item('a', { position: 1, name: 'A' })
    )

    expect(setup(nextSpace()).result.getRootItems(data).map((entry) => entry.id)).toEqual([
      'a',
      'b',
      'z',
    ])
  })

  it('returns nothing without menu data', () => {
    expect(setup(nextSpace()).result.getRootItems(undefined)).toEqual([])
  })
})

describe('getChildren', () => {
  const data = menu(
    item('parent'),
    item('c2', { pid: 'parent', position: 2 }),
    item('c1', { pid: 'parent', position: 1 })
  )

  it('returns the children of the named parent in position order', () => {
    expect(setup(nextSpace()).result.getChildren(data, 'parent').map((entry) => entry.id)).toEqual([
      'c1',
      'c2',
    ])
  })

  it('returns the root bucket for a null parent', () => {
    expect(setup(nextSpace()).result.getChildren(data, null).map((entry) => entry.id)).toEqual([
      'parent',
    ])
  })

  it('moves singles to the end of the root bucket, exactly like getRootItems', () => {
    const roots = menu(item('settings', { type: 'single', position: 0 }), item('home', { position: 1 }))
    const { result } = setup(nextSpace())

    // Both answers to "what is at the root" have to agree.
    expect(result.getChildren(roots, null).map((entry) => entry.id)).toEqual(['home', 'settings'])
    expect(result.getRootItems(roots).map((entry) => entry.id)).toEqual(['home', 'settings'])
  })

  it('returns nothing for a parent with no children', () => {
    expect(setup(nextSpace()).result.getChildren(data, 'c1')).toEqual([])
  })

  it('returns nothing without menu data', () => {
    expect(setup(nextSpace()).result.getChildren(undefined, 'parent')).toEqual([])
  })

  it('unwraps a ref parent id', () => {
    expect(setup(nextSpace()).result.getChildren(data, ref('parent'))).toHaveLength(2)
  })

  it('hands out a copy, so a caller sorting in place cannot corrupt the cache', () => {
    const { result } = setup(nextSpace())
    const first = result.getChildren(data, 'parent')

    first.reverse()

    expect(first).not.toBe(result.getChildren(data, 'parent'))
    expect(result.getChildren(data, 'parent').map((entry) => entry.id)).toEqual(['c1', 'c2'])
  })

  it('rebuilds the index for a replaced menu-data object', () => {
    const { result } = setup(nextSpace())
    // The index is memoized on the menu-data identity, which TanStack Query
    // replaces wholesale — a changed sort override must take effect.
    const replaced = {
      ...data,
      parent: item('parent', { settings: { child_sort_by: 'name', child_sort_direction: 'desc' } }),
    }

    expect(result.getChildren(data, 'parent').map((entry) => entry.id)).toEqual(['c1', 'c2'])
    expect(result.getChildren(replaced, 'parent').map((entry) => entry.id)).toEqual(['c2', 'c1'])
  })
})

describe('child sorting', () => {
  const childrenOf = (
    settings: Partial<ContentSettings>,
    ...children: FlatContentMenuItem[]
  ): string[] => {
    const data = menu(item('parent', { settings }), ...children)

    return setup(nextSpace()).result.getChildren(data, 'parent').map((entry) => entry.id)
  }

  const child = (id: string, overrides: Partial<FlatContentMenuItem> = {}) =>
    item(id, { pid: 'parent', ...overrides })

  it('sorts manually by position when no override is set', () => {
    expect(
      childrenOf({}, child('b', { position: 2, name: 'A' }), child('a', { position: 1, name: 'Z' }))
    ).toEqual(['a', 'b'])
  })

  it('treats inherit and manual like no override', () => {
    const items = [child('b', { position: 2 }), child('a', { position: 1 })]

    expect(childrenOf({ child_sort_by: 'inherit' }, ...items)).toEqual(['a', 'b'])
    expect(childrenOf({ child_sort_by: 'manual' }, ...items)).toEqual(['a', 'b'])
  })

  it('sorts by name ascending', () => {
    expect(
      childrenOf(
        { child_sort_by: 'name' },
        child('b', { name: 'Beta', position: 1 }),
        child('a', { name: 'Alpha', position: 2 })
      )
    ).toEqual(['a', 'b'])
  })

  it('sorts by name descending', () => {
    expect(
      childrenOf(
        { child_sort_by: 'name', child_sort_direction: 'desc' },
        child('a', { name: 'Alpha' }),
        child('b', { name: 'Beta' })
      )
    ).toEqual(['b', 'a'])
  })

  it('sorts by a date field', () => {
    expect(
      childrenOf(
        { child_sort_by: 'published_at' },
        child('newer', { pat: '2026-07-02T00:00:00.000Z' }),
        child('older', { pat: '2026-07-01T00:00:00.000Z' })
      )
    ).toEqual(['older', 'newer'])
  })

  it('reverses a date field on desc', () => {
    expect(
      childrenOf(
        { child_sort_by: 'created_at', child_sort_direction: 'desc' },
        child('older', { cat: '2026-07-01T00:00:00.000Z' }),
        child('newer', { cat: '2026-07-02T00:00:00.000Z' })
      )
    ).toEqual(['newer', 'older'])
  })

  it('puts entries without a date last, even when sorting descending', () => {
    expect(
      childrenOf(
        { child_sort_by: 'published_at', child_sort_direction: 'desc' },
        child('unpublished', { pat: null }),
        child('published', { pat: '2026-07-01T00:00:00.000Z' })
      )
    ).toEqual(['published', 'unpublished'])
  })

  it('compares content field values numerically when both are numbers', () => {
    expect(
      childrenOf(
        { child_sort_by: 'content.rank' },
        child('ten', { sv: 10 }),
        child('two', { sv: 2 })
      )
    ).toEqual(['two', 'ten'])
  })

  it('compares numeric strings numerically rather than lexically', () => {
    expect(
      childrenOf(
        { child_sort_by: 'content.rank' },
        child('ten', { sv: '10' }),
        child('two', { sv: '2' })
      )
    ).toEqual(['two', 'ten'])
  })

  it('falls back to a string comparison for non-numeric values', () => {
    expect(
      childrenOf(
        { child_sort_by: 'content.label' },
        child('beta', { sv: 'beta' }),
        child('alpha', { sv: 'alpha' })
      )
    ).toEqual(['alpha', 'beta'])
  })

  it('sorts entries without a content value last', () => {
    expect(
      childrenOf(
        { child_sort_by: 'content.rank' },
        child('missing', { sv: null }),
        child('present', { sv: 1 })
      )
    ).toEqual(['present', 'missing'])
  })

  it('treats an empty string as a string, not as zero', () => {
    expect(
      childrenOf(
        { child_sort_by: 'content.rank' },
        child('empty', { sv: '' }),
        child('one', { sv: '1' })
      )
    ).toEqual(['empty', 'one'])
  })

  it('breaks a tie on the sort field by position', () => {
    expect(
      childrenOf(
        { child_sort_by: 'content.rank' },
        child('second', { sv: 1, position: 2 }),
        child('first', { sv: 1, position: 1 })
      )
    ).toEqual(['first', 'second'])
  })

  it('ignores a sort override on a different parent', () => {
    const data = menu(
      item('parent', { settings: { child_sort_by: 'name' } }),
      item('other'),
      item('b', { pid: 'other', name: 'Beta', position: 1 }),
      item('a', { pid: 'other', name: 'Alpha', position: 2 })
    )

    expect(setup(nextSpace()).result.getChildren(data, 'other').map((entry) => entry.id)).toEqual([
      'b',
      'a',
    ])
  })
})

describe('buildBreadcrumbs', () => {
  const data = menu(
    item('root'),
    item('branch', { pid: 'root' }),
    item('leaf', { pid: 'branch', name: 'Leaf' })
  )

  it('walks ancestors root-first', () => {
    expect(setup(nextSpace()).result.buildBreadcrumbs(data, 'leaf')).toEqual([
      { id: 'root', name: 'root' },
      { id: 'branch', name: 'branch' },
      { id: 'leaf', name: 'Leaf' },
    ])
  })

  it('returns just the item itself for a root', () => {
    expect(setup(nextSpace()).result.buildBreadcrumbs(data, 'root')).toEqual([
      { id: 'root', name: 'root' },
    ])
  })

  it('returns nothing for an unknown id', () => {
    expect(setup(nextSpace()).result.buildBreadcrumbs(data, 'ghost')).toEqual([])
  })

  it('returns nothing without menu data', () => {
    expect(setup(nextSpace()).result.buildBreadcrumbs(undefined, 'leaf')).toEqual([])
  })

  it('stops at a parent that is missing from the menu', () => {
    const orphaned = menu(item('leaf', { pid: 'gone' }))

    expect(setup(nextSpace()).result.buildBreadcrumbs(orphaned, 'leaf')).toEqual([
      { id: 'leaf', name: 'leaf' },
    ])
  })

  it('unwraps a ref content id', () => {
    expect(setup(nextSpace()).result.buildBreadcrumbs(data, ref('branch'))).toHaveLength(2)
  })

  it('terminates on a two-node parent cycle', () => {
    const cyclic = menu(item('a', { pid: 'b' }), item('b', { pid: 'a' }))

    // A bad import or a hand-edited parent_id used to hang the tab here, not
    // throw: the walk had neither a visited set nor a depth bound.
    expect(setup(nextSpace()).result.buildBreadcrumbs(cyclic, 'a')).toEqual([
      { id: 'b', name: 'b' },
      { id: 'a', name: 'a' },
    ])
  })

  it('terminates on a self-parenting item', () => {
    const selfParent = menu(item('a', { pid: 'a' }))

    expect(setup(nextSpace()).result.buildBreadcrumbs(selfParent, 'a')).toEqual([
      { id: 'a', name: 'a' },
    ])
  })
})

describe('shared Echo channel', () => {
  it('joins the space content channel once on mount', () => {
    const space = nextSpace()
    setup(space)

    expect(fake.channels.map((channel) => channel.name)).toEqual([`spaces.${space}.content`])
  })

  it('joins only once for two subscribers of the same space', () => {
    const space = nextSpace()
    const first = withSetup(() => useContentMenu(space))
    const second = withSetup(() => useContentMenu(space))

    expect(fake.channels).toHaveLength(1)

    first.unmount()
    second.unmount()
  })

  it('keeps the channel alive while another subscriber remains', () => {
    const space = nextSpace()
    const first = withSetup(() => useContentMenu(space))
    const second = withSetup(() => useContentMenu(space))

    first.unmount()
    expect(fake.left).toEqual([])

    second.unmount()
    expect(fake.left).toEqual([`spaces.${space}.content`])
  })

  it('rejoins after the last subscriber has left', () => {
    const space = nextSpace()
    withSetup(() => useContentMenu(space)).unmount()
    withSetup(() => useContentMenu(space)).unmount()

    expect(fake.channels).toHaveLength(2)
    expect(fake.left).toHaveLength(2)
  })

  it('leaves the OLD space channel when the space id changes', async () => {
    const spaceId = ref(nextSpace())
    const previous = spaceId.value
    setup(spaceId)

    const next = nextSpace()
    spaceId.value = next
    await nextTick()

    expect(fake.left).toEqual([`spaces.${previous}.content`])
    expect(fake.channels.map((channel) => channel.name)).toEqual([
      `spaces.${previous}.content`,
      `spaces.${next}.content`,
    ])
  })

  it('ignores a space id change while unmounted', async () => {
    const spaceId = ref(nextSpace())
    setup(spaceId)
    harness?.unmount()
    harness = undefined

    spaceId.value = nextSpace()
    await nextTick()

    expect(fake.channels).toHaveLength(1)
  })

  it('does not subscribe without a space id', () => {
    setup('')

    expect(fake.channels).toEqual([])
  })

  it('survives a missing Echo', () => {
    Reflect.deleteProperty(window, 'Echo')

    expect(() => setup(nextSpace())).not.toThrow()
  })

  it('survives an Echo that throws while subscribing', () => {
    window.Echo = {
      channel: () => {
        throw new Error('no socket')
      },
      leave: () => {},
    } as unknown as typeof window.Echo

    const instance = withSetup(() => useContentMenu(nextSpace()))

    expect(() => instance.unmount()).not.toThrow()
  })
})

describe('content:updated broadcasts', () => {
  const broadcast = (payload: Record<string, unknown>) =>
    fake.channels[0].listeners.get('.content:updated')?.(payload)

  it('drops the broadcast item straight into the cached menu', () => {
    const space = nextSpace()
    const key = queryKeys.contentMenu(space).all()
    const instance = setup(space, [[key, menu(item('a', { name: 'Old' }))]])

    broadcast({ ...item('a', { name: 'New' }), i18n_parent_id: null })

    expect(menuOf(instance, space).a.name).toBe('New')
  })

  it('adds an item the cache has never seen', () => {
    const space = nextSpace()
    const instance = setup(space, [[queryKeys.contentMenu(space).all(), menu(item('a'))]])

    broadcast({ ...item('b'), i18n_parent_id: null })

    expect(Object.keys(menuOf(instance, space)).sort()).toEqual(['a', 'b'])
  })

  it('writes into an empty cache when no menu has been fetched yet', () => {
    const space = nextSpace()
    const instance = setup(space)

    broadcast({ ...item('a'), i18n_parent_id: null })

    expect(Object.keys(menuOf(instance, space))).toEqual(['a'])
  })

  it('carries the previous sort value forward when the broadcast omits it', () => {
    const space = nextSpace()
    const instance = setup(space, [
      [queryKeys.contentMenu(space).all(), menu(item('a', { sv: 42 }))],
    ])

    broadcast({ ...item('a', { name: 'New' }), sv: undefined, i18n_parent_id: null })

    expect(menuOf(instance, space).a.sv).toBe(42)
  })

  it('takes the broadcast sort value when one is given', () => {
    const space = nextSpace()
    const instance = setup(space, [
      [queryKeys.contentMenu(space).all(), menu(item('a', { sv: 42 }))],
    ])

    broadcast({ ...item('a'), sv: 7, i18n_parent_id: null })

    expect(menuOf(instance, space).a.sv).toBe(7)
  })

  it('ignores a translation whose canonical parent is not cached', () => {
    const space = nextSpace()
    const instance = setup(space, [[queryKeys.contentMenu(space).all(), menu(item('a'))]])

    broadcast({ ...item('translation'), i18n_parent_id: 'missing' })

    expect(Object.keys(menuOf(instance, space))).toEqual(['a'])
  })

  const translation = (id: string, overrides: Partial<ContentMenuTranslation> = {}) =>
    ({
      id,
      name: id,
      language_iso: 'de',
      published_at: null,
      ...overrides,
    }) as ContentMenuTranslation

  it('applies a translation update to its canonical parent', () => {
    const space = nextSpace()
    const parent = item('a', { name: 'Canonical', i18n: [translation('translation')] })
    const instance = setup(space, [[queryKeys.contentMenu(space).all(), menu(parent)]])

    broadcast({
      ...item('translation', { name: 'Übersetzt', pat: '2026-07-29T00:00:00.000Z' }),
      i18n_parent_id: 'a',
    })

    // A fresh parent identity is what re-renders the tree (it also busts the
    // children index cache) — the previous no-op write relied on that alone.
    expect(menuOf(instance, space).a).not.toBe(parent)
    // A translation is not a node of its own: it lives in the parent's i18n list.
    expect(menuOf(instance, space).a.i18n).toEqual([
      { id: 'translation', name: 'Übersetzt', language_iso: 'de', published_at: '2026-07-29T00:00:00.000Z' },
    ])
    expect(menuOf(instance, space).translation).toBeUndefined()
  })

  it('leaves the canonical name and the other languages alone', () => {
    const space = nextSpace()
    const parent = item('a', {
      name: 'Canonical',
      i18n: [translation('de-translation'), translation('fr-translation', { language_iso: 'fr' })],
    })
    const instance = setup(space, [[queryKeys.contentMenu(space).all(), menu(parent)]])

    broadcast({ ...item('de-translation', { name: 'Übersetzt' }), i18n_parent_id: 'a' })

    expect(menuOf(instance, space).a.name).toBe('Canonical')
    expect(menuOf(instance, space).a.i18n.map((entry) => entry.name)).toEqual([
      'Übersetzt',
      'fr-translation',
    ])
  })

  it('leaves the parent alone for a translation it has never seen', () => {
    const space = nextSpace()
    const parent = item('a', { name: 'Canonical' })
    const instance = setup(space, [[queryKeys.contentMenu(space).all(), menu(parent)]])

    broadcast({ ...item('new-translation', { name: 'Neu' }), i18n_parent_id: 'a' })

    // The broadcast carries no language, so an unknown translation cannot be
    // inserted — a refetch has to bring it in.
    expect(menuOf(instance, space).a.i18n).toEqual([])
    expect(menuOf(instance, space)['new-translation']).toBeUndefined()
  })
})

describe('useContentMenuQuery', () => {
  it('serves the seeded cache entry under the content-menu key', () => {
    const space = nextSpace()
    const data = menu(item('a'))
    const instance = withSetup(
      () => {
        const composable = useContentMenu(space)
        return composable.useContentMenuQuery()
      },
      { seed: [[queryKeys.contentMenu(space).all(), data]] }
    )
    harness = undefined

    expect(instance.result.data.value).toEqual(data)

    instance.unmount()
  })

  it('never fetches while the caller keeps it disabled', () => {
    const space = nextSpace()
    const instance = withSetup(() => useContentMenu(space).useContentMenuQuery(false))
    harness = undefined

    expect(instance.result.fetchStatus.value).toBe('idle')
    expect(instance.result.data.value).toBeUndefined()

    instance.unmount()
  })

  it('stays disabled without a space id', () => {
    const instance = withSetup(() => useContentMenu('').useContentMenuQuery())
    harness = undefined

    expect(instance.result.fetchStatus.value).toBe('idle')

    instance.unmount()
  })
})
