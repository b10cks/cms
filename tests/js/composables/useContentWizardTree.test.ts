import { describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'

import { CONTENT_WIZARD_ROOT_ID, type ContentWizardDraftNode } from '~/types/content-wizard'

import { useContentWizardTree } from '~/composables/useContentWizardTree'

const block = (over: Partial<BlockResource> & { id: string }): BlockResource => ({
  slug: over.id,
  name: over.id,
  description: '',
  type: 'universal',
  schema: {},
  editor: [],
  tags: [],
  folder_id: null,
  created_at: '2026-01-01',
  updated_at: '2026-01-01',
  ...over,
})

const PAGE = block({ id: 'page', type: 'root', icon: 'file', color: '#111' })
const ARTICLE = block({ id: 'article', type: 'universal', tags: ['editorial'] })
const NOTE = block({ id: 'note', type: 'universal' })
const HOME = block({ id: 'home', type: 'single' })
const TEASER = block({ id: 'teaser', type: 'nestable' })

const BLOCKS = [PAGE, ARTICLE, NOTE, HOME, TEASER]

const item = (over: Partial<FlatContentMenuItem> & { id: string }): FlatContentMenuItem => ({
  name: over.id,
  slug: over.id,
  block_id: 'article',
  position: 0,
  type: 'universal',
  color: null,
  pid: null,
  children: false,
  settings: {},
  i18n: [],
  pat: null,
  uat: '2026-01-01',
  ...over,
})

const setup = (items: FlatContentMenuItem[] = [], blockList: BlockResource[] = BLOCKS) => {
  const api = useContentWizardTree(
    ref(blockList),
    ref(Object.fromEntries(items.map((entry) => [entry.id, entry])))
  )

  api.initializeFromSource()

  return api
}

const childIds = (api: ReturnType<typeof useContentWizardTree>, nodeId: string | null = null) =>
  api.getNode(nodeId)?.childrenIds ?? []

const ids = (nodes: ContentWizardDraftNode[]) => nodes.map((node) => node.id)

describe('initializeFromSource', () => {
  it('builds a tree holding just the virtual root when there is no menu data', () => {
    const api = useContentWizardTree(ref(BLOCKS), ref(undefined))

    api.initializeFromSource()

    expect(Object.keys(api.tree.value.nodes)).toEqual([CONTENT_WIZARD_ROOT_ID])
    expect(api.getNode(CONTENT_WIZARD_ROOT_ID)?.isRootVirtual).toBe(true)
  })

  it('hydrates each menu item as a saved node with an original snapshot', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])
    const node = api.getNode('a')

    expect(node?.backendId).toBe('a')
    expect(node?.title).toBe('About')
    expect(node?.original).toEqual({
      parentId: null,
      title: 'About',
      slug: 'about',
      blockId: 'article',
      blockType: 'universal',
      position: 0,
    })
    expect(node?.changes).toEqual({ created: false, updated: false, moved: false, deleted: false })
  })

  it('links children to their parent and to the virtual root', () => {
    const api = setup([
      item({ id: 'parent' }),
      item({ id: 'child', pid: 'parent' }),
      item({ id: 'other', position: 1 }),
    ])

    expect(childIds(api)).toEqual(['parent', 'other'])
    expect(childIds(api, 'parent')).toEqual(['child'])
    expect(api.getNode('child')?.depth).toBe(2)
  })

  it('orders siblings by position, then name, then id', () => {
    const api = setup([
      item({ id: 'c', name: 'b', position: 1 }),
      item({ id: 'a', name: 'b', position: 0 }),
      item({ id: 'b', name: 'a', position: 1 }),
    ])

    expect(childIds(api)).toEqual(['a', 'b', 'c'])
  })

  it('pushes root-level single entries behind everything else', () => {
    const api = setup([
      item({ id: 'home', block_id: 'home', type: 'single', position: 0 }),
      item({ id: 'blog', position: 1 }),
    ])

    expect(childIds(api)).toEqual(['blog', 'home'])
  })

  it('does not reorder singles that are not at the root level', () => {
    const api = setup([
      item({ id: 'parent' }),
      item({ id: 'single', pid: 'parent', type: 'single', position: 0 }),
      item({ id: 'plain', pid: 'parent', position: 1 }),
    ])

    expect(childIds(api, 'parent')).toEqual(['single', 'plain'])
  })

  it('prefers the block record over the menu item for name, icon, colour and type', () => {
    const api = setup([
      item({ id: 'a', block_id: 'page', name: 'About', icon: 'ghost', color: '#fff', type: 'single' }),
    ])
    const node = api.getNode('a')

    expect(node?.blockName).toBe('page')
    expect(node?.blockType).toBe('root')
    expect(node?.icon).toBe('file')
    expect(node?.color).toBe('#111')
    // title still comes from the entry, not the block
    expect(node?.title).toBe('About')
  })

  it('falls back to the menu item when the block is unknown', () => {
    const api = setup([item({ id: 'a', block_id: 'gone', name: 'Ghost', icon: 'ghost', type: 'single' })])
    const node = api.getNode('a')

    expect(node?.blockName).toBe('Ghost')
    expect(node?.blockType).toBe('single')
    expect(node?.icon).toBe('ghost')
    expect(node?.canHaveChildren).toBe(false)
  })

  it('derives the slug mode from whether the stored slug matches the title', () => {
    const api = setup([
      item({ id: 'auto', name: 'My Page', slug: 'my-page' }),
      item({ id: 'manual', name: 'My Page', slug: 'custom' }),
    ])

    expect(api.getNode('auto')?.slugMode).toBe('auto')
    expect(api.getNode('manual')?.slugMode).toBe('manual')
  })

  it('replaces the previous tree entirely on a second call', () => {
    const menu = ref<Record<string, FlatContentMenuItem>>({ a: item({ id: 'a' }) })
    const api = useContentWizardTree(ref(BLOCKS), menu)

    api.initializeFromSource()
    expect(api.getNode('a')).not.toBeNull()

    menu.value = { b: item({ id: 'b' }) }
    api.initializeFromSource()

    expect(api.getNode('a')).toBeNull()
    expect(childIds(api)).toEqual(['b'])
  })

  // An entry whose pid points at an item missing from the payload (filtered by
  // permissions, paginated away) is re-homed at the root. Left under its absent
  // parent it was never visited by the tree walk — no layout, no deletedReason —
  // while it still fed validations and the operation plan.
  it('re-homes an entry whose parent is missing at the root', () => {
    const api = setup([item({ id: 'orphan', pid: 'nope' })])

    expect(childIds(api)).toEqual(['orphan'])
    expect(api.getNode('orphan')?.parentId).toBeNull()
    expect(api.getNode('orphan')?.depth).toBe(1)
    // Re-homing is how the entry loaded, not an edit the user has to save.
    expect(api.hasUnsavedChanges.value).toBe(false)
  })
})

describe('getNode', () => {
  it('returns the node for a known id', () => {
    expect(setup([item({ id: 'a' })]).getNode('a')?.id).toBe('a')
  })

  it('returns null for an unknown id', () => {
    expect(setup().getNode('nope')).toBeNull()
  })

  // An absent id means "the root" — that is what makes a root-level parentId
  // navigable. An empty string is a bad id, not an absent one.
  it('resolves an absent id to the virtual root but not the empty string', () => {
    const api = setup()

    expect(api.getNode(null)?.id).toBe(CONTENT_WIZARD_ROOT_ID)
    expect(api.getNode(undefined)?.id).toBe(CONTENT_WIZARD_ROOT_ID)
    expect(api.getNode('')).toBeNull()
  })
})

describe('canPlaceBlockUnderParent', () => {
  it('refuses nestable blocks anywhere', () => {
    const result = setup().canPlaceBlockUnderParent(TEASER, null)

    expect(result.valid).toBe(false)
    expect(result.message).toBe('Nestable blocks are not available in the content wizard.')
  })

  it('refuses a parent that does not exist', () => {
    expect(setup().canPlaceBlockUnderParent(ARTICLE, 'nope')).toEqual({
      valid: false,
      message: 'Missing parent node.',
    })
  })

  it('allows root, universal and single blocks at the root', () => {
    const api = setup()

    expect(api.canPlaceBlockUnderParent(PAGE, null).valid).toBe(true)
    expect(api.canPlaceBlockUnderParent(ARTICLE, null).valid).toBe(true)
    expect(api.canPlaceBlockUnderParent(HOME, null).valid).toBe(true)
  })

  it('allows root and universal blocks under a saved parent', () => {
    const api = setup([item({ id: 'parent' })])

    expect(api.canPlaceBlockUnderParent(PAGE, 'parent').valid).toBe(true)
    expect(api.canPlaceBlockUnderParent(ARTICLE, 'parent').valid).toBe(true)
  })

  // The allowed-child-block check runs before the root-only rule, so a single
  // block under a normal parent reports the generic message, never
  // 'Single blocks can only live at the root.'
  it('refuses a single block under a parent with the allowed-types message', () => {
    const api = setup([item({ id: 'parent' })])

    expect(api.canPlaceBlockUnderParent(HOME, 'parent')).toEqual({
      valid: false,
      message: 'This content type is not allowed under the selected parent.',
    })
  })

  it('refuses any child under a single-block parent', () => {
    const api = setup([item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(api.canPlaceBlockUnderParent(ARTICLE, 'home')).toEqual({
      valid: false,
      message: 'Single blocks cannot contain children.',
    })
  })

  it('refuses a child under a parent inside a deleted branch', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent' })])

    api.toggleDelete('parent')

    expect(api.canPlaceBlockUnderParent(ARTICLE, 'child')).toEqual({
      valid: false,
      message: 'Move the parent out of the deleted branch first.',
    })
  })

  it('honours a parent block whitelist', () => {
    const api = setup([
      item({
        id: 'parent',
        settings: { restrict_child_blocks: true, child_block_whitelist: ['article'] },
      }),
    ])

    expect(api.canPlaceBlockUnderParent(ARTICLE, 'parent').valid).toBe(true)
    expect(api.canPlaceBlockUnderParent(NOTE, 'parent').valid).toBe(false)
  })

  it('honours a parent tag whitelist', () => {
    const api = setup([
      item({
        id: 'parent',
        settings: { restrict_child_blocks: true, child_tag_whitelist: ['editorial'] },
      }),
    ])

    expect(api.canPlaceBlockUnderParent(ARTICLE, 'parent').valid).toBe(true)
    expect(api.canPlaceBlockUnderParent(NOTE, 'parent').valid).toBe(false)
  })

  it('ignores an empty whitelist rather than forbidding everything', () => {
    const api = setup([
      item({
        id: 'parent',
        settings: { restrict_child_blocks: true, child_block_whitelist: [] },
      }),
    ])

    expect(api.canPlaceBlockUnderParent(NOTE, 'parent').valid).toBe(true)
  })

  it('does not apply the root block whitelist — the virtual root has no settings', () => {
    const api = setup()

    expect(api.canPlaceBlockUnderParent(PAGE, null).valid).toBe(true)
  })

  it('refuses a second instance of a single block', () => {
    const api = setup([item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(api.canPlaceBlockUnderParent(HOME, null)).toEqual({
      valid: false,
      message: 'This single block already exists in the tree.',
    })
  })

  // The root is addressable both as null and by its id, and the two spellings
  // have to agree — naming it by id used to trip the `parentId !== null` rule and
  // reject a single block at the very place it is supposed to live.
  it('treats the root named by id the same as the root named by null', () => {
    const api = setup()

    expect(api.canPlaceBlockUnderParent(HOME, CONTENT_WIZARD_ROOT_ID).valid).toBe(true)
    expect(api.canPlaceBlockUnderParent(HOME, null).valid).toBe(true)
  })

  it('lets the node holding a single block keep it when excluded', () => {
    const api = setup([item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(api.canPlaceBlockUnderParent(HOME, null, { excludeNodeId: 'home' }).valid).toBe(true)
  })

  it('frees a single block once its only holder is deleted', () => {
    const api = setup([item({ id: 'home', block_id: 'home', type: 'single' })])

    api.toggleDelete('home')

    expect(api.canPlaceBlockUnderParent(HOME, null).valid).toBe(true)
  })

  it('refuses a single block for a node that still has active children', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent' })])

    expect(api.canPlaceBlockUnderParent(HOME, null, { excludeNodeId: 'parent' })).toEqual({
      valid: false,
      message: 'Single blocks cannot keep children.',
    })
  })

  it('allows a single block once the children are all deleted', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent' })])

    api.toggleDelete('child')

    expect(api.canPlaceBlockUnderParent(HOME, null, { excludeNodeId: 'parent' }).valid).toBe(true)
  })
})

describe('getAvailableBlocks', () => {
  it('lists everything placeable at the root, singles included', () => {
    expect(setup().getAvailableBlocks(null).map((entry) => entry.id)).toEqual([
      'page',
      'article',
      'note',
      'home',
    ])
  })

  it('drops singles and nestables under a parent', () => {
    const api = setup([item({ id: 'parent' })])

    expect(api.getAvailableBlocks('parent').map((entry) => entry.id)).toEqual([
      'page',
      'article',
      'note',
    ])
  })

  it('is empty for an unknown parent', () => {
    expect(setup().getAvailableBlocks('nope')).toEqual([])
  })
})

describe('getAssignableBlocks', () => {
  it('is empty for the virtual root', () => {
    expect(setup().getAssignableBlocks(CONTENT_WIZARD_ROOT_ID)).toEqual([])
  })

  it('is empty for an unknown node', () => {
    expect(setup().getAssignableBlocks('nope')).toEqual([])
  })

  it('lists the blocks valid at the node position', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent' })])

    expect(api.getAssignableBlocks('child').map((entry) => entry.id)).toEqual([
      'page',
      'article',
      'note',
    ])
  })

  // Keeps the current selection selectable even when it is no longer valid,
  // otherwise the block picker would silently show a different block than the node has.
  it('prepends the current block when it is not placeable any more', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent', block_id: 'teaser' })])

    expect(api.getAssignableBlocks('child').map((entry) => entry.id)).toEqual([
      'teaser',
      'page',
      'article',
      'note',
    ])
  })

  it('omits a current block that no longer exists at all', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent', block_id: 'gone' })])

    expect(api.getAssignableBlocks('child').map((entry) => entry.id)).toEqual([
      'page',
      'article',
      'note',
    ])
  })
})

describe('addNode', () => {
  it('creates a draft node at the end of the root children', () => {
    const api = setup([item({ id: 'a' })])
    const node = api.addNode(ARTICLE, { parentId: null, position: 'child' })

    expect(node.backendId).toBeNull()
    expect(node.id.startsWith('draft:')).toBe(true)
    expect(childIds(api)).toEqual(['a', node.id])
    expect(node.changes.created).toBe(true)
    expect(node.depth).toBe(1)
  })

  it('derives the title and slug from the block by default', () => {
    const node = setup().addNode(ARTICLE, { parentId: null, position: 'child' })

    expect(node.title).toBe('article')
    expect(node.slug).toBe('article')
    expect(node.slugMode).toBe('auto')
  })

  it('honours an explicit title, slug and slug mode', () => {
    const node = setup().addNode(ARTICLE, {
      parentId: null,
      position: 'child',
      title: 'My Page',
      slug: 'kept',
      slugMode: 'manual',
    })

    expect(node.slug).toBe('kept')
    expect(node.slugMode).toBe('manual')
  })

  it('uses a caller-supplied node id, so a remote peer can mirror the insert', () => {
    const api = setup()

    api.addNode(ARTICLE, { parentId: null, position: 'child', nodeId: 'shared-1' })

    expect(api.getNode('shared-1')).not.toBeNull()
  })

  it('adds under the given parent', () => {
    const api = setup([item({ id: 'parent' })])
    const node = api.addNode(ARTICLE, { parentId: 'parent', position: 'child' })

    expect(childIds(api, 'parent')).toEqual([node.id])
    expect(node.parentId).toBe('parent')
  })

  it('places a sibling next to the reference node, not under it', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'child', pid: 'parent' })])
    const node = api.addNode(ARTICLE, {
      parentId: 'parent',
      position: 'sibling',
      referenceNodeId: 'child',
    })

    expect(childIds(api, 'parent')).toEqual(['child', node.id])
  })

  // ODDITY: the reference node's parentId is merged with `?? options.parentId`,
  // so a root-level reference (parentId === null) falls through to the caller's
  // parentId instead of resolving to the root.
  it('ignores a root-level reference and uses the passed parentId instead', () => {
    const api = setup([item({ id: 'parent' }), item({ id: 'top' })])
    const node = api.addNode(ARTICLE, {
      parentId: 'parent',
      position: 'sibling',
      referenceNodeId: 'top',
    })

    expect(childIds(api, 'parent')).toEqual([node.id])
    expect(childIds(api)).toEqual(['parent', 'top'])
  })

  it('falls back to the passed parent when the reference node is gone', () => {
    const api = setup([item({ id: 'parent' })])
    const node = api.addNode(ARTICLE, {
      parentId: 'parent',
      position: 'sibling',
      referenceNodeId: 'nope',
    })

    expect(childIds(api, 'parent')).toEqual([node.id])
  })

  it('throws with the validation message for an invalid placement', () => {
    expect(() => setup().addNode(TEASER, { parentId: null, position: 'child' })).toThrow(
      'Nestable blocks are not available in the content wizard.'
    )
  })

  it('throws rather than adding a second single block', () => {
    const api = setup([item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(() => api.addNode(HOME, { parentId: null, position: 'child' })).toThrow(
      'This single block already exists in the tree.'
    )
  })

  it('deep-copies the template content so two nodes never share sub-objects', () => {
    const content = { hero: { headline: 'Hi' } }
    const node = setup().addNode(ARTICLE, { parentId: null, position: 'child', content })

    content.hero.headline = 'Changed'

    expect((node.content.hero as { headline: string }).headline).toBe('Hi')
  })

  it('copies the child whitelists rather than aliasing the caller arrays', () => {
    const whitelist = ['article']
    const node = setup().addNode(ARTICLE, {
      parentId: null,
      position: 'child',
      settings: { restrict_child_blocks: true, child_block_whitelist: whitelist },
    })

    whitelist.push('note')

    expect(node.settings.child_block_whitelist).toEqual(['article'])
  })

  // Reusing an existing node id replaces the record and re-places it once. A
  // replayed collaboration whisper carries a peer-supplied id, and appending it
  // to the parent twice laid the same node out twice.
  it('replaces a node rather than listing its id twice when the id is reused', () => {
    const api = setup()

    api.addNode(ARTICLE, { parentId: null, position: 'child', nodeId: 'dup' })
    api.addNode(NOTE, { parentId: null, position: 'child', nodeId: 'dup' })

    expect(childIds(api)).toEqual(['dup'])
    expect(api.getNode('dup')?.blockId).toBe('note')
  })

  it('re-places a reused node under its new parent', () => {
    const api = setup([item({ id: 'parent' })])

    api.addNode(ARTICLE, { parentId: null, position: 'child', nodeId: 'dup' })
    api.addNode(ARTICLE, { parentId: 'parent', position: 'child', nodeId: 'dup' })

    expect(childIds(api)).toEqual(['parent'])
    expect(childIds(api, 'parent')).toEqual(['dup'])
  })
})

describe('moveNode', () => {
  const nested = () =>
    setup([
      item({ id: 'a' }),
      item({ id: 'b' }),
      item({ id: 'a1', pid: 'a' }),
      item({ id: 'a1x', pid: 'a1' }),
    ])

  it('reparents a node and updates depth and position', () => {
    const api = nested()

    expect(api.moveNode('a1', 'b')).toEqual({ valid: true })
    expect(childIds(api, 'a')).toEqual([])
    expect(childIds(api, 'b')).toEqual(['a1'])
    expect(api.getNode('a1')?.depth).toBe(2)
    expect(api.getNode('a1x')?.depth).toBe(3)
  })

  it('inserts at the requested index', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 }), item({ id: 'c', position: 2 })])

    api.moveNode('c', null, 0)

    expect(childIds(api)).toEqual(['c', 'a', 'b'])
  })

  it('clamps an out-of-range index to the ends of the list', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 })])

    api.moveNode('a', null, 99)
    expect(childIds(api)).toEqual(['b', 'a'])

    api.moveNode('a', null, -5)
    expect(childIds(api)).toEqual(['a', 'b'])
  })

  it('appends when no index is given', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 })])

    api.moveNode('a', null)

    expect(childIds(api)).toEqual(['b', 'a'])
  })

  it('moves a node up to the root', () => {
    const api = nested()

    api.moveNode('a1', null)

    expect(childIds(api)).toEqual(['a', 'b', 'a1'])
    expect(api.getNode('a1')?.parentId).toBeNull()
  })

  it('refuses to move the virtual root', () => {
    expect(nested().moveNode(CONTENT_WIZARD_ROOT_ID, 'a')).toEqual({
      valid: false,
      message: 'The root cannot be moved.',
    })
  })

  it('refuses to move an unknown node', () => {
    expect(nested().moveNode('nope', null).valid).toBe(false)
  })

  it('refuses to move a node into itself', () => {
    expect(nested().moveNode('a', 'a')).toEqual({
      valid: false,
      message: 'A node cannot move into its own branch.',
    })
  })

  it('refuses to move a node into its direct child', () => {
    expect(nested().moveNode('a', 'a1')).toEqual({
      valid: false,
      message: 'A node cannot move into its own branch.',
    })
  })

  it('refuses to move a node into a deeper descendant', () => {
    expect(nested().moveNode('a', 'a1x')).toEqual({
      valid: false,
      message: 'A node cannot move into its own branch.',
    })
  })

  it('allows the mirror case — moving a descendant under an unrelated node', () => {
    expect(nested().moveNode('a1x', 'b').valid).toBe(true)
  })

  it('refuses an unknown target parent', () => {
    expect(nested().moveNode('a', 'nope')).toEqual({
      valid: false,
      message: 'Missing parent node.',
    })
  })

  it('refuses to move a node whose block is gone', () => {
    const api = setup([item({ id: 'a', block_id: 'gone' }), item({ id: 'b' })])

    expect(api.moveNode('a', 'b')).toEqual({
      valid: false,
      message: 'The selected block is not available.',
    })
  })

  it('refuses a target parent that cannot have children', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(api.moveNode('a', 'home')).toEqual({
      valid: false,
      message: 'Single blocks cannot contain children.',
    })
  })

  it('refuses a target parent inside a deleted branch', () => {
    const api = nested()

    api.toggleDelete('a')

    expect(api.moveNode('b', 'a1')).toEqual({
      valid: false,
      message: 'Move the parent out of the deleted branch first.',
    })
  })

  it('refuses to move a single block away from the root', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(api.moveNode('home', 'a').valid).toBe(false)
  })

  it('clears an inherited deleted reason once the node leaves the branch', () => {
    const api = nested()

    api.toggleDelete('a')
    expect(api.getNode('a1')?.deletedReason).toBe('ancestor')

    api.moveNode('a1', 'b')

    expect(api.getNode('a1')?.deletedReason).toBeUndefined()
    expect(api.getNode('a1x')?.deletedReason).toBeUndefined()
  })

  it('records the move on the node once its parent differs from the original', () => {
    const api = nested()

    api.moveNode('a1', 'b')

    expect(api.getNode('a1')?.changes.moved).toBe(true)
  })

  it('drops the move flag again when the node returns to its original parent', () => {
    const api = nested()

    api.moveNode('a1', 'b')
    api.moveNode('a1', 'a')

    expect(api.getNode('a1')?.changes.moved).toBe(false)
  })

  // `changes.moved` uses the same test as operationPlan's move filter — parent
  // *and* position — so a pure sibling reorder is visible to the leave guard
  // instead of planning operations behind its back.
  it('flags a same-parent reorder, matching the planned moves', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 })])

    api.moveNode('a', null, 1)

    expect(api.getNode('a')?.changes.moved).toBe(true)
    expect(api.getNode('b')?.changes.moved).toBe(true)
    expect(api.hasUnsavedChanges.value).toBe(true)
    expect(api.operationPlan.value.filter((operation) => operation.type === 'move')).toHaveLength(2)
  })

  it('drops the reorder flag once the node is back at its original index', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 })])

    api.moveNode('a', null, 1)
    api.moveNode('a', null, 0)

    expect(api.hasUnsavedChanges.value).toBe(false)
    expect(api.operationPlan.value).toEqual([])
  })
})

describe('updateTitle', () => {
  it('follows the title with the slug while the slug is automatic', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', 'About Us')

    expect(api.getNode('a')?.slug).toBe('about-us')
    expect(api.getNode('a')?.slugMode).toBe('auto')
  })

  it('leaves a manual slug alone', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'custom' })])

    api.updateTitle('a', 'About Us')

    expect(api.getNode('a')?.slug).toBe('custom')
    expect(api.getNode('a')?.slugMode).toBe('manual')
  })

  it('marks the node updated', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', 'Renamed')

    expect(api.getNode('a')?.changes.updated).toBe(true)
  })

  it('ignores the virtual root and unknown nodes', () => {
    const api = setup()

    api.updateTitle(CONTENT_WIZARD_ROOT_ID, 'Nope')
    api.updateTitle('missing', 'Nope')

    expect(api.getNode(CONTENT_WIZARD_ROOT_ID)?.title).toBe('Root')
  })

  it('keeps an empty title, leaving the error to validation', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', '')

    expect(api.getNode('a')?.title).toBe('')
    expect(api.getNode('a')?.slug).toBe('')
  })
})

describe('updateSlug', () => {
  it('slugifies whatever is typed', () => {
    const api = setup([item({ id: 'a', name: 'About' })])

    api.updateSlug('a', 'Hello World!')

    expect(api.getNode('a')?.slug).toBe('hello-world')
  })

  it('switches to manual when the slug stops matching the title', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateSlug('a', 'legal-notice')

    expect(api.getNode('a')?.slugMode).toBe('manual')
  })

  it('stays automatic when the typed slug still matches the title', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'custom' })])

    api.updateSlug('a', 'about')

    expect(api.getNode('a')?.slugMode).toBe('auto')
  })

  it('clears the slug and returns to automatic for blank input', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'custom' })])

    api.updateSlug('a', '   ')

    expect(api.getNode('a')?.slug).toBe('')
    expect(api.getNode('a')?.slugMode).toBe('auto')
  })

  it('reports no update when the effective slug is unchanged', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateSlug('a', 'about')

    expect(api.getNode('a')?.changes.updated).toBe(false)
  })

  it('ignores the virtual root', () => {
    const api = setup()

    api.updateSlug(CONTENT_WIZARD_ROOT_ID, 'root-slug')

    expect(api.getNode(CONTENT_WIZARD_ROOT_ID)?.slug).toBe('')
  })
})

describe('updateBlock', () => {
  it('swaps the block and takes over its metadata', () => {
    const api = setup([item({ id: 'a' })])

    expect(api.updateBlock('a', 'page')).toEqual({ valid: true })

    const node = api.getNode('a')

    expect(node?.blockId).toBe('page')
    expect(node?.blockName).toBe('page')
    expect(node?.icon).toBe('file')
    expect(node?.changes.updated).toBe(true)
  })

  it('refuses an unknown block', () => {
    expect(setup([item({ id: 'a' })]).updateBlock('a', 'gone')).toEqual({
      valid: false,
      message: 'The selected block is not available.',
    })
  })

  it('refuses to change the virtual root', () => {
    expect(setup().updateBlock(CONTENT_WIZARD_ROOT_ID, 'page').valid).toBe(false)
  })

  it('refuses a nestable block', () => {
    expect(setup([item({ id: 'a' })]).updateBlock('a', 'teaser').valid).toBe(false)
  })

  it('refuses a single block for a node that has children', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    expect(api.updateBlock('a', 'home')).toEqual({
      valid: false,
      message: 'Single blocks cannot keep children.',
    })
  })

  it('refuses a single block for a node below the root', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    expect(api.updateBlock('a1', 'home').valid).toBe(false)
  })

  it('turns a childless root-level node into a single block and forbids children', () => {
    const api = setup([item({ id: 'a' })])

    expect(api.updateBlock('a', 'home').valid).toBe(true)
    expect(api.getNode('a')?.canHaveChildren).toBe(false)
  })
})

describe('setCollapsed', () => {
  it('hides the descendants and keeps them out of the layout', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    expect(api.setCollapsed('a', true)).toEqual({ valid: true })
    expect(api.getNode('a1')?.isVisible).toBe(false)
    expect(api.getNode('a')?.isVisible).toBe(true)
  })

  it('shows them again on expand', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.setCollapsed('a', true)
    api.setCollapsed('a', false)

    expect(api.getNode('a1')?.isVisible).toBe(true)
  })

  it('keeps a nested branch hidden while an ancestor stays collapsed', () => {
    const api = setup([
      item({ id: 'a' }),
      item({ id: 'a1', pid: 'a' }),
      item({ id: 'a1x', pid: 'a1' }),
    ])

    api.setCollapsed('a', true)
    api.setCollapsed('a1', false)

    expect(api.getNode('a1x')?.isVisible).toBe(false)
  })

  it('refuses a node without children', () => {
    expect(setup([item({ id: 'a' })]).setCollapsed('a', true)).toEqual({
      valid: false,
      message: 'Only nodes with children can be collapsed.',
    })
  })

  it('refuses the virtual root', () => {
    const api = setup([item({ id: 'a' })])

    expect(api.setCollapsed(CONTENT_WIZARD_ROOT_ID, true)).toEqual({
      valid: false,
      message: 'The selected node cannot be collapsed.',
    })
  })

  it('refuses an unknown node', () => {
    expect(setup().setCollapsed('nope', true).valid).toBe(false)
  })
})

describe('toggleDelete', () => {
  it('marks a saved node deleted and cascades the reason to its branch', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.toggleDelete('a')

    expect(api.getNode('a')?.deletedReason).toBe('self')
    expect(api.getNode('a')?.changes.deleted).toBe(true)
    expect(api.getNode('a1')?.deletedReason).toBe('ancestor')
    expect(api.getNode('a1')?.changes.deleted).toBe(false)
  })

  it('restores a saved node on a second call', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.toggleDelete('a')
    api.toggleDelete('a')

    expect(api.getNode('a')?.deletedReason).toBeUndefined()
    expect(api.getNode('a1')?.deletedReason).toBeUndefined()
  })

  it('drops an unsaved node from the tree instead of flagging it', () => {
    const api = setup()
    const node = api.addNode(ARTICLE, { parentId: null, position: 'child' })

    api.toggleDelete(node.id)

    expect(api.getNode(node.id)).toBeNull()
    expect(childIds(api)).toEqual([])
  })

  it('drops the unsaved node together with its unsaved subtree', () => {
    const api = setup()
    const parent = api.addNode(ARTICLE, { parentId: null, position: 'child' })
    const child = api.addNode(ARTICLE, { parentId: parent.id, position: 'child' })

    api.toggleDelete(parent.id)

    expect(api.getNode(child.id)).toBeNull()
    expect(Object.keys(api.tree.value.nodes)).toEqual([CONTENT_WIZARD_ROOT_ID])
  })

  // Dropping the draft must not take saved content with it: a deleted record
  // produces no delete operation, so the entry would vanish from the canvas while
  // the backend still held it. Removing a draft the user just created is not a
  // request to destroy stored content, so the saved entry moves up one level.
  it('re-parents a saved descendant of an unsaved node instead of erasing it', () => {
    const api = setup([item({ id: 'saved' })])
    const draft = api.addNode(ARTICLE, { parentId: null, position: 'child' })

    api.moveNode('saved', draft.id)
    api.toggleDelete(draft.id)

    expect(api.getNode('saved')?.parentId).toBeNull()
    expect(childIds(api)).toEqual(['saved'])
    expect(api.operationPlan.value).toEqual([])
  })

  it('re-parents onto the removed draft own parent, not onto the root', () => {
    const api = setup([item({ id: 'keeper' }), item({ id: 'saved', position: 1 })])
    const draft = api.addNode(ARTICLE, { parentId: 'keeper', position: 'child' })

    api.moveNode('saved', draft.id)
    api.toggleDelete(draft.id)

    expect(childIds(api, 'keeper')).toEqual(['saved'])
    expect(api.operationPlan.value).toEqual([
      { type: 'move', nodeId: 'saved', parentId: 'keeper', depth: 2, position: 0 },
    ])
  })

  it('keeps the unsaved part of the branch out of the tree', () => {
    const api = setup([item({ id: 'saved' })])
    const draft = api.addNode(ARTICLE, { parentId: null, position: 'child' })
    const nestedDraft = api.addNode(ARTICLE, { parentId: draft.id, position: 'child' })

    api.moveNode('saved', nestedDraft.id)
    api.toggleDelete(draft.id)

    expect(api.getNode(nestedDraft.id)).toBeNull()
    expect(api.getNode('saved')?.parentId).toBeNull()
    expect(Object.keys(api.tree.value.nodes).sort()).toEqual([CONTENT_WIZARD_ROOT_ID, 'saved'])
  })

  it('ignores the virtual root and unknown nodes', () => {
    const api = setup([item({ id: 'a' })])

    api.toggleDelete(CONTENT_WIZARD_ROOT_ID)
    api.toggleDelete('nope')

    expect(api.getNode(CONTENT_WIZARD_ROOT_ID)?.deletedReason).toBeUndefined()
    expect(childIds(api)).toEqual(['a'])
  })
})

describe('setDeletedState', () => {
  it('deletes a node that is not deleted yet', () => {
    const api = setup([item({ id: 'a' })])

    expect(api.setDeletedState('a', true)).toEqual({ valid: true })
    expect(api.getNode('a')?.deletedReason).toBe('self')
  })

  it('is idempotent for an already deleted node', () => {
    const api = setup([item({ id: 'a' })])

    api.setDeletedState('a', true)
    api.setDeletedState('a', true)

    expect(api.getNode('a')?.deletedReason).toBe('self')
  })

  it('restores a node deleted in its own right', () => {
    const api = setup([item({ id: 'a' })])

    api.setDeletedState('a', true)

    expect(api.setDeletedState('a', false)).toEqual({ valid: true })
    expect(api.getNode('a')?.deletedReason).toBeUndefined()
  })

  it('refuses to restore a node that only inherited the deletion', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.setDeletedState('a', true)

    expect(api.setDeletedState('a1', false)).toEqual({
      valid: false,
      message: 'Restore the deleted ancestor or move this node out of that branch first.',
    })
  })

  it('reports success for a node that was never deleted', () => {
    expect(setup([item({ id: 'a' })]).setDeletedState('a', false)).toEqual({ valid: true })
  })

  it('refuses the virtual root and unknown nodes', () => {
    const api = setup()

    expect(api.setDeletedState(CONTENT_WIZARD_ROOT_ID, true)).toEqual({
      valid: false,
      message: 'The selected node cannot be changed.',
    })
    expect(api.setDeletedState('nope', true).valid).toBe(false)
  })
})

describe('duplicateNode', () => {
  it('copies a node under the given parent and suffixes the title', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' }), item({ id: 'b' })])
    const result = api.duplicateNode('a', 'b')

    expect(result.valid).toBe(true)

    const copy = api.getNode(result.createdNodeId ?? '')

    expect(copy?.title).toBe('About Copy')
    expect(copy?.slug).toBe('about-copy')
    expect(copy?.backendId).toBeNull()
    expect(childIds(api, 'b')).toEqual([result.createdNodeId])
  })

  it('copies the whole subtree, keeping the descendants titles', () => {
    const api = setup([
      item({ id: 'a', name: 'About' }),
      item({ id: 'a1', pid: 'a', name: 'Team' }),
      item({ id: 'a1x', pid: 'a1', name: 'Bios' }),
    ])
    const result = api.duplicateNode('a', null)
    const copyId = result.createdNodeId ?? ''
    const childId = childIds(api, copyId)[0]

    expect(api.getNode(childId)?.title).toBe('Team')
    expect(api.getNode(childIds(api, childId)[0])?.title).toBe('Bios')
  })

  it('keeps the descendants slug modes', () => {
    const api = setup([
      item({ id: 'a', name: 'About' }),
      item({ id: 'a1', pid: 'a', name: 'Team', slug: 'crew' }),
    ])
    const copyId = api.duplicateNode('a', null).createdNodeId ?? ''
    const childId = childIds(api, copyId)[0]

    expect(api.getNode(childId)?.slug).toBe('crew')
    expect(api.getNode(childId)?.slugMode).toBe('manual')
  })

  it('refuses to copy the virtual root', () => {
    expect(setup().duplicateNode(CONTENT_WIZARD_ROOT_ID, null)).toEqual({
      valid: false,
      message: 'The selected node cannot be copied.',
    })
  })

  it('refuses to copy an unknown node', () => {
    expect(setup().duplicateNode('nope', null).valid).toBe(false)
  })

  it('refuses to copy a node whose block is gone', () => {
    expect(setup([item({ id: 'a', block_id: 'gone' })]).duplicateNode('a', null)).toEqual({
      valid: false,
      message: 'The selected block is not available.',
    })
  })

  it('refuses to copy a single block — the original already claims it', () => {
    const api = setup([item({ id: 'home', block_id: 'home', type: 'single' })])

    expect(api.duplicateNode('home', null)).toEqual({
      valid: false,
      message: 'This single block already exists in the tree.',
    })
  })

  it('refuses to copy into a parent that rejects the block', () => {
    const api = setup([
      item({ id: 'a' }),
      item({
        id: 'strict',
        settings: { restrict_child_blocks: true, child_block_whitelist: ['note'] },
      }),
    ])

    expect(api.duplicateNode('a', 'strict').valid).toBe(false)
  })

  // The whole subtree is validated before anything is created: addNode throws,
  // and a descendant the target rejects used to escape as an exception —
  // canvas.vue calls duplicateNode inside a `result.valid` check with no `try`,
  // so it crashed and skipped its snapshot restore, leaving a half-built copy.
  it('returns an invalid result rather than throwing when a descendant cannot be copied', () => {
    const api = setup([
      item({ id: 'a' }),
      item({ id: 'a1', pid: 'a', block_id: 'teaser', type: 'nestable' }),
    ])

    expect(api.duplicateNode('a', null)).toEqual({
      valid: false,
      message: 'Nestable blocks are not available in the content wizard.',
    })
  })

  it('leaves the tree untouched when a descendant cannot be copied', () => {
    const api = setup([
      item({ id: 'a' }),
      item({ id: 'a1', pid: 'a', block_id: 'teaser', type: 'nestable' }),
    ])

    api.duplicateNode('a', null)

    expect(childIds(api)).toEqual(['a'])
    expect(Object.keys(api.tree.value.nodes).sort()).toEqual([CONTENT_WIZARD_ROOT_ID, 'a', 'a1'])
  })

  it('refuses the copy when a descendant block record is missing', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a', block_id: 'gone' })])

    expect(api.duplicateNode('a', null)).toEqual({
      valid: false,
      message: 'The selected block is not available.',
    })
  })

  // A copy is a live node: inheriting isDeletedSelf produced a draft the plan
  // skipped in both directions — no create because it was deleted, no delete
  // because it had no backend id — while it kept hasUnsavedChanges true forever.
  it('creates an active copy of a deleted node', () => {
    const api = setup([item({ id: 'a' })])

    api.toggleDelete('a')

    const result = api.duplicateNode('a', null)
    const copyId = result.createdNodeId ?? ''

    expect(api.getNode(copyId)?.deletedReason).toBeUndefined()
    expect(api.operationPlan.value.some((operation) => operation.nodeId === copyId)).toBe(true)
  })

  it('copies the children of a deleted node as active nodes too', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.toggleDelete('a')

    const copyId = api.duplicateNode('a', null).createdNodeId ?? ''
    const childId = childIds(api, copyId)[0]

    expect(api.getNode(childId)?.deletedReason).toBeUndefined()
  })

  it('does not share content objects with the source node', () => {
    const api = setup()
    const source = api.addNode(ARTICLE, {
      parentId: null,
      position: 'child',
      content: { hero: { headline: 'Hi' } },
    })
    const copyId = api.duplicateNode(source.id, null).createdNodeId ?? ''

    ;(source.content.hero as { headline: string }).headline = 'Changed'

    expect(((api.getNode(copyId)?.content.hero ?? {}) as { headline: string }).headline).toBe('Hi')
  })
})

describe('validations', () => {
  it('is empty for a clean tree', () => {
    expect(setup([item({ id: 'a' })]).validations.value).toEqual([])
  })

  it('requires a title', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', '  ')

    expect(api.validations.value).toEqual([
      { nodeId: 'a', field: 'title', message: 'A title is required.' },
      { nodeId: 'a', field: 'slug', message: 'A valid slug is required.' },
    ])
  })

  it('accepts a slugless node whose title still slugifies', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: '' })])

    expect(api.validations.value).toEqual([])
  })

  it('reports a slug that cannot be derived from the title', () => {
    const api = setup([item({ id: 'a', name: '!!!', slug: '' })])

    expect(api.validations.value).toEqual([
      { nodeId: 'a', field: 'slug', message: 'A valid slug is required.' },
    ])
  })

  it('flags both siblings sharing a slug', () => {
    const api = setup([
      item({ id: 'a', name: 'About', slug: 'about' }),
      item({ id: 'b', name: 'Other', slug: 'about', position: 1 }),
    ])

    expect(api.validations.value).toEqual([
      { nodeId: 'a', field: 'slug', message: 'Sibling slugs must stay unique.' },
      { nodeId: 'b', field: 'slug', message: 'Sibling slugs must stay unique.' },
    ])
  })

  it('allows the same slug under different parents', () => {
    const api = setup([
      item({ id: 'a', slug: 'shared' }),
      item({ id: 'b', slug: 'shared', pid: 'a' }),
    ])

    expect(api.validations.value).toEqual([])
  })

  it('ignores deleted nodes', () => {
    const api = setup([
      item({ id: 'a', name: 'About', slug: 'about' }),
      item({ id: 'b', name: 'Other', slug: 'about', position: 1 }),
    ])

    api.toggleDelete('b')

    expect(api.validations.value).toEqual([])
  })

  it('reports a node whose block has disappeared', () => {
    const api = setup([item({ id: 'a', block_id: 'gone' })])

    expect(api.validations.value).toEqual([
      { nodeId: 'a', field: 'block', message: 'The selected block is no longer available.' },
    ])
  })

  // ODDITY: nestable is a legal content block type server-side but the wizard
  // rejects it outright, so an existing nestable entry is permanently invalid and
  // there is no way to fix it from the canvas.
  it('permanently rejects an existing entry built on a nestable block', () => {
    const api = setup([item({ id: 'a', block_id: 'teaser', type: 'nestable' })])

    expect(api.validations.value).toEqual([
      {
        nodeId: 'a',
        field: 'placement',
        message: 'Nestable blocks are not available in the content wizard.',
      },
    ])
  })

  it('reports a saved child that violates the parent whitelist', () => {
    const api = setup([
      item({
        id: 'parent',
        settings: { restrict_child_blocks: true, child_block_whitelist: ['note'] },
      }),
      item({ id: 'child', pid: 'parent', block_id: 'article' }),
    ])

    expect(api.validations.value).toEqual([
      {
        nodeId: 'child',
        field: 'placement',
        message: 'This content type is not allowed under the selected parent.',
      },
    ])
  })

  it('flags every node holding the same single block', () => {
    const api = setup([
      item({ id: 'h1', block_id: 'home', type: 'single' }),
      item({ id: 'h2', block_id: 'home', type: 'single', position: 1 }),
    ])

    expect(api.validations.value.filter((error) => error.field === 'block')).toEqual([
      { nodeId: 'h1', field: 'block', message: 'This single block already exists in the tree.' },
      { nodeId: 'h2', field: 'block', message: 'This single block already exists in the tree.' },
    ])
  })
})

describe('validationMap and node validation state', () => {
  it('groups the errors by node', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', '')

    expect(api.validationMap.value.get('a')?.map((error) => error.field)).toEqual(['title', 'slug'])
    expect(api.validationMap.value.has('b')).toBe(false)
  })

  it('pushes the errors onto the node itself', async () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', '')
    await nextTick()

    expect(api.getNode('a')?.validationState.hasErrors).toBe(true)
    expect(api.getNode('a')?.validationState.errors).toHaveLength(2)
  })

  it('clears them again once the node is valid', async () => {
    const api = setup([item({ id: 'a', name: 'About' })])

    api.updateTitle('a', '')
    await nextTick()
    api.updateTitle('a', 'Fixed')
    await nextTick()

    expect(api.getNode('a')?.validationState.hasErrors).toBe(false)
  })

  it('never marks the virtual root invalid', async () => {
    const api = setup([item({ id: 'a', name: 'About' })])

    api.updateTitle('a', '')
    await nextTick()

    expect(api.getNode(CONTENT_WIZARD_ROOT_ID)?.validationState).toEqual({
      hasErrors: false,
      errors: [],
    })
  })
})

describe('orderedNodes', () => {
  it('walks the tree breadth-first, root first', () => {
    const api = setup([
      item({ id: 'a' }),
      item({ id: 'b', position: 1 }),
      item({ id: 'a1', pid: 'a' }),
    ])

    expect(ids(api.orderedNodes.value)).toEqual([CONTENT_WIZARD_ROOT_ID, 'a', 'b', 'a1'])
  })

  // ODDITY: the sort key is (depth, position) across the whole tree, so nodes from
  // different parents interleave by sibling index rather than staying grouped
  // under their parent. exportForAi inherits this ordering.
  it('interleaves same-depth children of different parents by index', () => {
    const api = setup([
      item({ id: 'a' }),
      item({ id: 'b', position: 1 }),
      item({ id: 'a1', pid: 'a' }),
      item({ id: 'a2', pid: 'a', position: 1 }),
      item({ id: 'b1', pid: 'b' }),
    ])

    expect(ids(api.orderedNodes.value)).toEqual([
      CONTENT_WIZARD_ROOT_ID,
      'a',
      'b',
      'a1',
      'b1',
      'a2',
    ])
  })
})

describe('bounds', () => {
  it('covers a single card for a tree holding only the root', () => {
    expect(setup().bounds.value).toEqual({
      minX: 0,
      maxX: 288,
      minY: 0,
      maxY: 54,
      width: 288,
      height: 54,
    })
  })

  it('grows by one column per depth and one row per visible node', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    expect(api.getNode('a')?.layout).toEqual({ x: 320, y: 86 })
    expect(api.getNode('a1')?.layout).toEqual({ x: 640, y: 172 })
    expect(api.bounds.value).toEqual({
      minX: 0,
      maxX: 928,
      minY: 0,
      maxY: 226,
      width: 928,
      height: 226,
    })
  })

  it('shrinks when a branch is collapsed', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.setCollapsed('a', true)

    expect(api.bounds.value.height).toBe(140)
  })
})

describe('hasUnsavedChanges', () => {
  it('is false for a freshly hydrated tree', () => {
    expect(setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })]).hasUnsavedChanges.value).toBe(
      false
    )
  })

  it('is true after an insert', () => {
    const api = setup()

    api.addNode(ARTICLE, { parentId: null, position: 'child' })

    expect(api.hasUnsavedChanges.value).toBe(true)
  })

  it('is true after a rename, a reparent or a delete', () => {
    const renamed = setup([item({ id: 'a' })])
    renamed.updateTitle('a', 'New')
    expect(renamed.hasUnsavedChanges.value).toBe(true)

    const moved = setup([item({ id: 'a' }), item({ id: 'b' })])
    moved.moveNode('b', 'a')
    expect(moved.hasUnsavedChanges.value).toBe(true)

    const deleted = setup([item({ id: 'a' })])
    deleted.toggleDelete('a')
    expect(deleted.hasUnsavedChanges.value).toBe(true)
  })

  it('goes back to false when the change is undone', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', 'New')
    api.updateTitle('a', 'About')

    expect(api.hasUnsavedChanges.value).toBe(false)
  })
})

describe('operationPlan', () => {
  it('is empty for an untouched tree', () => {
    expect(setup([item({ id: 'a' })]).operationPlan.value).toEqual([])
  })

  it('orders creates parents-first', () => {
    const api = setup()
    const parent = api.addNode(ARTICLE, { parentId: null, position: 'child' })
    const child = api.addNode(ARTICLE, { parentId: parent.id, position: 'child' })

    expect(api.operationPlan.value).toEqual([
      { type: 'create', nodeId: parent.id, parentId: null, depth: 1 },
      { type: 'create', nodeId: child.id, parentId: parent.id, depth: 2 },
    ])
  })

  it('skips a create for an unsaved node that was deleted again', () => {
    const api = setup()
    const parent = api.addNode(ARTICLE, { parentId: null, position: 'child' })

    api.updateBlock(parent.id, 'page')
    api.toggleDelete(parent.id)

    expect(api.operationPlan.value).toEqual([])
  })

  it('plans an update for a renamed node', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' })])

    api.updateTitle('a', 'Renamed')

    expect(api.operationPlan.value).toEqual([
      {
        type: 'update',
        nodeId: 'a',
        depth: 1,
        fromBlockType: 'universal',
        toBlockType: 'universal',
        requiresMoveBeforeUpdate: false,
        requiresUpdateBeforeMove: false,
      },
    ])
  })

  it('plans no update when only the derived slug is rewritten to the same value', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: '' })])

    api.updateSlug('a', 'about')

    expect(api.operationPlan.value).toEqual([])
  })

  it('asks for a move before an update when a node becomes a single block', () => {
    const api = setup([item({ id: 'a' })])

    api.updateBlock('a', 'home')

    expect(api.operationPlan.value[0]).toMatchObject({
      type: 'update',
      requiresMoveBeforeUpdate: true,
      requiresUpdateBeforeMove: false,
    })
  })

  it('asks for an update before a move when a node stops being a single block', () => {
    const api = setup([item({ id: 'a', block_id: 'home', type: 'single' })])

    api.updateBlock('a', 'article')

    expect(api.operationPlan.value[0]).toMatchObject({
      type: 'update',
      requiresMoveBeforeUpdate: false,
      requiresUpdateBeforeMove: true,
    })
  })

  it('plans a move carrying the new parent and index', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 }), item({ id: 'c', position: 2 })])

    api.moveNode('c', 'a', 0)

    expect(api.operationPlan.value).toEqual([
      { type: 'move', nodeId: 'c', parentId: 'a', depth: 2, position: 0 },
    ])
  })

  it('orders deletes deepest-first', () => {
    const api = setup([
      item({ id: 'a' }),
      item({ id: 'a1', pid: 'a' }),
      item({ id: 'a1x', pid: 'a1' }),
    ])

    api.toggleDelete('a')
    api.toggleDelete('a1')

    expect(api.operationPlan.value).toEqual([
      { type: 'delete', nodeId: 'a1', depth: 2 },
      { type: 'delete', nodeId: 'a', depth: 1 },
    ])
  })

  it('plans no delete for a node that only inherited the deletion', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.toggleDelete('a')

    expect(api.operationPlan.value).toEqual([{ type: 'delete', nodeId: 'a', depth: 1 }])
  })

  it('groups the operations creates, updates, moves, deletes', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b', position: 1 }), item({ id: 'c', position: 2 })])

    const created = api.addNode(ARTICLE, { parentId: null, position: 'child' })
    api.updateTitle('a', 'Renamed')
    api.moveNode('b', 'a')
    api.toggleDelete('c')

    expect(api.operationPlan.value.map((operation) => operation.type)).toEqual([
      'create',
      'update',
      'move',
      'delete',
    ])
    expect(api.operationPlan.value[0]).toMatchObject({ nodeId: created.id })
  })
})

describe('snapshots', () => {
  it('round-trips the tree', () => {
    const api = setup([item({ id: 'a', name: 'About' }), item({ id: 'b' })])
    const snapshot = api.createSnapshot()

    api.updateTitle('a', 'Changed')
    api.moveNode('b', 'a')
    api.restoreSnapshot(snapshot)

    expect(api.getNode('a')?.title).toBe('About')
    expect(childIds(api)).toEqual(['a', 'b'])
    expect(api.hasUnsavedChanges.value).toBe(false)
  })

  it('restores a node that had been added after the snapshot was taken', () => {
    const api = setup([item({ id: 'a' })])
    const snapshot = api.createSnapshot()
    const added = api.addNode(ARTICLE, { parentId: null, position: 'child' })

    api.restoreSnapshot(snapshot)

    expect(api.getNode(added.id)).toBeNull()
  })

  it('does not alias the live tree, so later edits leave the snapshot alone', () => {
    const api = setup([item({ id: 'a', name: 'About' })])
    const snapshot = api.createSnapshot()

    api.updateTitle('a', 'Changed')

    expect(snapshot.nodes.a.title).toBe('About')
    expect(snapshot.nodes.a.childrenIds).not.toBe(api.getNode('a')?.childrenIds)
  })

  it('is not affected by mutating the restored tree afterwards', () => {
    const api = setup([item({ id: 'a', name: 'About' })])
    const snapshot = api.createSnapshot()

    api.restoreSnapshot(snapshot)
    api.updateTitle('a', 'Changed')

    expect(snapshot.nodes.a.title).toBe('About')
  })

  it('recomputes the layout on restore', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])
    const snapshot = api.createSnapshot()

    api.toggleDelete('a1')
    api.restoreSnapshot(snapshot)

    expect(api.bounds.value.height).toBe(226)
  })
})

describe('exportForAi', () => {
  it('describes every node but the virtual root', () => {
    const api = setup([item({ id: 'a', name: 'About', slug: 'about' }), item({ id: 'a1', pid: 'a' })])

    expect(api.exportForAi()).toEqual([
      {
        id: 'a',
        backend_id: 'a',
        parent_id: null,
        name: 'About',
        slug: 'about',
        block_id: 'article',
        block_name: 'article',
        block_type: 'universal',
        position: 0,
        deleted_reason: null,
        is_deleted: false,
      },
      {
        id: 'a1',
        backend_id: 'a1',
        parent_id: 'a',
        name: 'a1',
        slug: 'a1',
        block_id: 'article',
        block_name: 'article',
        block_type: 'universal',
        position: 0,
        deleted_reason: null,
        is_deleted: false,
      },
    ])
  })

  it('resolves the effective slug from the title when none is stored', () => {
    const api = setup([item({ id: 'a', name: 'My Page', slug: '' })])

    expect(api.exportForAi()[0].slug).toBe('my-page')
  })

  it('marks deleted nodes and names the reason', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'a1', pid: 'a' })])

    api.toggleDelete('a')

    expect(api.exportForAi().map((node) => [node.id, node.deleted_reason])).toEqual([
      ['a', 'self'],
      ['a1', 'ancestor'],
    ])
  })

  it('reports a null backend id for an unsaved node', () => {
    const api = setup()

    api.addNode(ARTICLE, { parentId: null, position: 'child' })

    expect(api.exportForAi()[0].backend_id).toBeNull()
  })
})

describe('markAiAltered', () => {
  it('flags each named node', () => {
    const api = setup([item({ id: 'a' }), item({ id: 'b' })])

    api.markAiAltered(['a', 'b'])

    expect(api.getNode('a')?.isAiAltered).toBe(true)
    expect(api.getNode('b')?.isAiAltered).toBe(true)
  })

  it('ignores unknown ids', () => {
    const api = setup([item({ id: 'a' })])

    api.markAiAltered(['nope'])

    expect(api.getNode('a')?.isAiAltered).toBe(false)
  })

  // A blank id in an AI response is a bad id, not a reference to the root.
  it('ignores a blank id instead of flagging the virtual root', () => {
    const api = setup()

    api.markAiAltered([''])

    expect(api.getNode(CONTENT_WIZARD_ROOT_ID)?.isAiAltered).toBe(false)
  })

  it('accepts any iterable, a Set included', () => {
    const api = setup([item({ id: 'a' })])

    api.markAiAltered(new Set(['a']))

    expect(api.getNode('a')?.isAiAltered).toBe(true)
  })
})

describe('blockMap', () => {
  it('indexes the blocks by id', () => {
    expect(setup().blockMap.value.get('article')?.slug).toBe('article')
    expect(setup().blockMap.value.size).toBe(BLOCKS.length)
  })

  it('tracks a changing block list', () => {
    const blocks = ref([ARTICLE])
    const api = useContentWizardTree(blocks, ref({}))

    expect(api.blockMap.value.has('note')).toBe(false)

    blocks.value = [ARTICLE, NOTE]

    expect(api.blockMap.value.has('note')).toBe(true)
  })
})
