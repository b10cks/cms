import { afterEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { useAiMentions } from '~/composables/useAiMentions'
import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

// The two queries behind the mention list are seeded per test; anything left
// unseeded must simply stay pending rather than reaching the network, so a query
// key that drifts shows up as missing data instead of a hanging request.
vi.mock('~/api', () => ({
  api: {
    forSpace: () => ({
      blocks: { index: () => new Promise(() => {}) },
      contentMenu: { get: () => new Promise(() => {}) },
    }),
  },
}))

const SPACE = 'space-1'

const menuItem = (overrides: Partial<FlatContentMenuItem>): FlatContentMenuItem =>
  ({
    id: 'c1',
    name: 'Home',
    slug: 'home',
    block_id: 'b1',
    position: 0,
    type: 'nestable',
    color: null,
    pid: null,
    children: false,
    settings: {},
    i18n: [],
    pat: null,
    uat: '2026-07-01T00:00:00Z',
    ...overrides,
  }) as FlatContentMenuItem

const menu = (items: FlatContentMenuItem[]) =>
  Object.fromEntries(items.map((item) => [item.id, item]))

const block = (overrides: Record<string, unknown>) => ({
  id: 'block-id',
  name: 'Hero',
  slug: 'hero',
  color: null,
  ...overrides,
})

const blocksKey = queryKeys.blocks(SPACE).list({ per_page: 1000 })
const menuKey = queryKeys.contentMenu(SPACE).all()

type Seed = { contents?: FlatContentMenuItem[]; blocks?: Array<Record<string, unknown>> }

type MentionQuery = ReturnType<ReturnType<typeof useAiMentions>['useMentionItemsQuery']>

let harness: Harness<MentionQuery> | undefined

const setup = (seed: Seed, search: MaybeRef<string> = '') => {
  const entries: Array<[readonly unknown[], unknown]> = []
  if (seed.contents) entries.push([menuKey, menu(seed.contents)])
  if (seed.blocks) entries.push([blocksKey, { data: seed.blocks }])

  harness = withSetup(() => useAiMentions(SPACE).useMentionItemsQuery(search), { seed: entries })

  return harness.result
}

const items = (seed: Seed, search: MaybeRef<string> = '') => setup(seed, search).items.value

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('content mentions', () => {
  it('offers every root content item', () => {
    const result = items({
      contents: [menuItem({ id: 'c1', name: 'Home' }), menuItem({ id: 'c2', name: 'About' })],
      blocks: [],
    })

    expect(result.map((entry) => entry.label).sort()).toEqual(['About', 'Home'])
    expect(result.every((entry) => entry.type === 'content')).toBe(true)
  })

  it('offers a nested child of a folder', () => {
    const result = items({
      contents: [
        menuItem({ id: 'c1', name: 'Blog', children: true }),
        menuItem({ id: 'c2', name: 'First post', pid: 'c1' }),
      ],
      blocks: [],
    })

    expect(result.map((entry) => entry.label)).toEqual(['Blog', 'First post'])
  })

  it('lists a content item only once', () => {
    const result = items({
      contents: [
        menuItem({ id: 'c1', name: 'Blog', children: true }),
        menuItem({ id: 'c2', name: 'First post', pid: 'c1' }),
        menuItem({ id: 'c3', name: 'Second post', pid: 'c1' }),
      ],
      blocks: [],
    })

    expect(result.map((entry) => entry.id)).toEqual(['c1', 'c2', 'c3'])
  })

  it('offers a child whose parent is not flagged as having children', () => {
    // Traversal follows the real `pid` links, so a folder with a stale flag no
    // longer hides its descendants from the mention list.
    const result = items({
      contents: [
        menuItem({ id: 'c1', name: 'Blog', children: false }),
        menuItem({ id: 'c2', name: 'First post', pid: 'c1' }),
      ],
      blocks: [],
    })

    expect(result.map((entry) => entry.label)).toEqual(['Blog', 'First post'])
  })

  it('uses the content id, not the slug, as the mention id', () => {
    expect(items({ contents: [menuItem({ id: 'c1', slug: 'home' })], blocks: [] })[0].id).toBe('c1')
  })

  it('labels an unnamed content item', () => {
    const result = items({
      contents: [menuItem({ id: 'c1', name: null as unknown as string })],
      blocks: [],
    })

    expect(result[0].label).toBe('Untitled')
  })

  it('carries the colour through and defaults the icon', () => {
    const [withIcon, withoutIcon] = items({
      contents: [
        menuItem({ id: 'c1', icon: 'lucide:home', color: '#f00' }),
        menuItem({ id: 'c2', icon: undefined, color: null }),
      ],
      blocks: [],
    })

    expect(withIcon).toMatchObject({ icon: 'lucide:home', color: '#f00' })
    expect(withoutIcon).toMatchObject({ icon: 'lucide:file', color: null })
  })

  it('offers nothing from the tree while the menu has not loaded', () => {
    expect(items({ blocks: [block({ slug: 'hero' })] })).toEqual([
      expect.objectContaining({ type: 'block' }),
    ])
  })
})

describe('block mentions', () => {
  it('offers every block after the content items', () => {
    const result = items({
      contents: [menuItem({ id: 'c1', name: 'Home' })],
      blocks: [block({ slug: 'hero', name: 'Hero' }), block({ slug: 'teaser', name: 'Teaser' })],
    })

    expect(result.map((entry) => entry.type)).toEqual(['content', 'block', 'block'])
  })

  it('uses the block slug as the mention id', () => {
    const [mention] = items({ blocks: [block({ id: 'ulid-1', slug: 'hero' })] })

    expect(mention.id).toBe('hero')
  })

  it('falls back to the slug when the block has no name', () => {
    const [mention] = items({ blocks: [block({ slug: 'hero', name: null })] })

    expect(mention.label).toBe('hero')
  })

  it('carries the colour through and defaults the icon', () => {
    const [withIcon, withoutIcon] = items({
      blocks: [
        block({ slug: 'hero', icon: 'lucide:image', color: '#0f0' }),
        block({ slug: 'teaser' }),
      ],
    })

    expect(withIcon).toMatchObject({ icon: 'lucide:image', color: '#0f0' })
    expect(withoutIcon).toMatchObject({ icon: 'lucide:box', color: null })
  })

  it('offers nothing from the block list while it has not loaded', () => {
    expect(items({ contents: [menuItem({ id: 'c1' })] })).toEqual([
      expect.objectContaining({ type: 'content' }),
    ])
  })
})

describe('search', () => {
  const seed: Seed = {
    contents: [
      menuItem({ id: 'c1', name: 'Home page' }),
      menuItem({ id: 'c2', name: 'About us' }),
    ],
    blocks: [block({ slug: 'hero', name: 'Hero banner' }), block({ slug: 'teaser', name: 'Card' })],
  }

  it('matches a content label case-insensitively on a substring', () => {
    expect(items(seed, 'home').map((entry) => entry.label)).toEqual(['Home page'])
  })

  it('matches regardless of the case of the query', () => {
    expect(items(seed, 'ABOUT').map((entry) => entry.label)).toEqual(['About us'])
  })

  it('matches a block by its label', () => {
    expect(items(seed, 'banner').map((entry) => entry.id)).toEqual(['hero'])
  })

  it('matches a block by its slug even when the label does not', () => {
    expect(items(seed, 'teaser').map((entry) => entry.label)).toEqual(['Card'])
  })

  it('returns everything for an empty query', () => {
    expect(items(seed, '')).toHaveLength(4)
  })

  it('treats a whitespace-only query as no query', () => {
    // A lone space used to match every multi-word label and drop `Card`.
    expect(items(seed, '  ')).toHaveLength(4)
  })

  it('ignores whitespace around a real query', () => {
    expect(items(seed, '  home  ').map((entry) => entry.label)).toEqual(['Home page'])
  })

  it('returns nothing when nothing matches', () => {
    expect(items(seed, 'zzz')).toEqual([])
  })

  it('does not match a mention by its id', () => {
    expect(items(seed, 'c1')).toEqual([])
  })

  it('re-filters when a reactive query changes', () => {
    const search = ref('home')
    const result = setup(seed, search)

    expect(result.items.value.map((entry) => entry.label)).toEqual([
      'Home page',
    ])

    search.value = 'card'

    expect(result.items.value.map((entry) => entry.label)).toEqual([
      'Card',
    ])
  })
})

describe('the result cap', () => {
  const many = (count: number, prefix: string) =>
    Array.from({ length: count }, (_, index) =>
      menuItem({ id: `${prefix}${index}`, name: `${prefix} ${index}` })
    )

  it('returns at most 50 mentions', () => {
    expect(items({ contents: many(60, 'c'), blocks: [] })).toHaveLength(50)
  })

  it('still offers the blocks when the content tree overflows the cap', () => {
    // The cap is shared out per source, so a large space can mention a block
    // without having to narrow the tree by searching first.
    const result = items({ contents: many(60, 'c'), blocks: [block({ slug: 'hero' })] })

    expect(result).toHaveLength(50)
    expect(result.filter((entry) => entry.type === 'block')).toEqual([
      expect.objectContaining({ id: 'hero' }),
    ])
  })

  it('gives each source half the cap when both overflow', () => {
    const blocks = Array.from({ length: 60 }, (_, index) =>
      block({ slug: `b${index}`, name: `Block ${index}` })
    )
    const result = items({ contents: many(60, 'c'), blocks })

    expect(result.filter((entry) => entry.type === 'content')).toHaveLength(25)
    expect(result.filter((entry) => entry.type === 'block')).toHaveLength(25)
  })

  it('lets one source use the whole cap when the other is empty', () => {
    const blocks = Array.from({ length: 60 }, (_, index) => block({ slug: `b${index}` }))

    expect(items({ contents: [], blocks })).toHaveLength(50)
  })

  it('applies the cap after filtering, so a search reaches the blocks again', () => {
    const result = items({ contents: many(60, 'c'), blocks: [block({ slug: 'hero' })] }, 'hero')

    expect(result).toEqual([expect.objectContaining({ id: 'hero' })])
  })
})

describe('isLoading', () => {
  it('is false once both queries have data', () => {
    expect(setup({ contents: [menuItem({ id: 'c1' })], blocks: [] }).isLoading.value).toBe(false)
  })

  it('stays true while the content menu is missing', () => {
    expect(setup({ blocks: [] }).isLoading.value).toBe(true)
  })

  it('stays true while the block list is missing', () => {
    expect(setup({ contents: [] }).isLoading.value).toBe(true)
  })

  it('is false for an empty but loaded space', () => {
    expect(setup({ contents: [], blocks: [] }).isLoading.value).toBe(false)
  })
})
