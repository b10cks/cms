import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, ref, type Ref } from 'vue'

import type {
  ContentWizardDraftNode,
  ContentWizardDraftTree,
  ContentWizardOperation,
  ContentWizardValidationError,
} from '~/types/content-wizard'
import { CONTENT_WIZARD_ROOT_ID } from '~/types/content-wizard'
import type { ContentTreeOperationPayload } from '~/types/contents'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const treeOperations = vi.fn()
const updateContent = vi.fn()
const treeOperationsPending = ref(false)
const updatePending = ref(false)

vi.mock('~/composables/useContent', async () => {
  const actual = await vi.importActual<typeof import('~/composables/useContent')>(
    '~/composables/useContent'
  )

  return {
    ...actual,
    useContent: (spaceId: string) => ({
      ...actual.useContent(spaceId),
      useTreeOperationsMutation: () => ({
        mutateAsync: treeOperations,
        isPending: treeOperationsPending,
      }),
      useUpdateContentMutation: () => ({
        mutateAsync: updateContent,
        isPending: updatePending,
      }),
    }),
  }
})

const { useContentWizardApply } = await import('~/composables/useContentWizardApply')

const SPACE = 'space-1'

type ApplyResult = ReturnType<typeof useContentWizardApply>
type TreeApi = Parameters<typeof useContentWizardApply>[1]

// Explicit, not ReturnType<typeof setup> — that is circular and TS would widen
// the composable surface to `any`.
let harness: Harness<ApplyResult> | undefined

const node = (
  id: string,
  overrides: Partial<ContentWizardDraftNode> = {}
): ContentWizardDraftNode =>
  ({
    id,
    backendId: null,
    parentId: CONTENT_WIZARD_ROOT_ID,
    childrenIds: [],
    blockId: 'block-page',
    blockType: 'nestable',
    title: id,
    slug: '',
    content: {},
    settings: {},
    depth: 1,
    position: 0,
    isRootVirtual: false,
    original: null,
    ...overrides,
  }) as unknown as ContentWizardDraftNode

const rootNode = (childrenIds: string[] = []) =>
  node(CONTENT_WIZARD_ROOT_ID, {
    parentId: null,
    childrenIds,
    isRootVirtual: true,
    depth: 0,
  })

const existing = (
  id: string,
  backendId: string,
  overrides: Partial<ContentWizardDraftNode> = {}
) =>
  node(id, {
    backendId,
    original: {
      parentId: CONTENT_WIZARD_ROOT_ID,
      title: id,
      slug: '',
      blockId: 'block-page',
      blockType: 'nestable',
      position: 0,
    },
    ...overrides,
  })

const setup = (options: {
  nodes?: ContentWizardDraftNode[]
  operations?: ContentWizardOperation[]
  validations?: ContentWizardValidationError[]
}) => {
  const nodes = options.nodes ?? [rootNode()]
  const tree = ref<ContentWizardDraftTree>({
    rootId: CONTENT_WIZARD_ROOT_ID,
    nodes: Object.fromEntries(nodes.map((entry) => [entry.id, entry])),
  })
  const operations = ref<ContentWizardOperation[]>(options.operations ?? [])
  const validations = ref<ContentWizardValidationError[]>(options.validations ?? [])

  const treeApi = {
    tree,
    operationPlan: computed(() => operations.value),
    validations: computed(() => validations.value),
    // Mirrors the real getNode: an absent id resolves to the virtual root.
    getNode: (nodeId: string | null | undefined) =>
      (nodeId === null || nodeId === undefined
        ? tree.value.nodes[CONTENT_WIZARD_ROOT_ID]
        : tree.value.nodes[nodeId]) ?? null,
  } as unknown as TreeApi

  harness = withSetup(() => useContentWizardApply(SPACE, treeApi))

  return harness
}

const createOp = (nodeId: string, depth = 1): ContentWizardOperation => ({
  type: 'create',
  nodeId,
  parentId: null,
  depth,
})

const deleteOp = (nodeId: string, depth = 1): ContentWizardOperation => ({
  type: 'delete',
  nodeId,
  depth,
})

const sentOperations = (): ContentTreeOperationPayload[] =>
  ((treeOperations.mock.calls[0]?.[0] ?? {}) as { operations: ContentTreeOperationPayload[] })
    .operations

beforeEach(() => {
  treeOperations.mockReset().mockResolvedValue({})
  updateContent.mockReset().mockResolvedValue({})
  treeOperationsPending.value = false
  updatePending.value = false
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('validation gate', () => {
  it('refuses to apply and reports the first validation message', async () => {
    const { result } = setup({
      operations: [createOp('a')],
      validations: [
        { nodeId: 'a', field: 'title', message: 'Title is required.' },
        { nodeId: 'a', field: 'slug', message: 'Slug is required.' },
      ],
    })

    const outcome = await result.apply()

    expect(outcome).toEqual({
      success: false,
      operations: [createOp('a')],
      error: 'Title is required.',
    })
    expect(result.applyError.value).toBe('Title is required.')
    expect(treeOperations).not.toHaveBeenCalled()
    expect(updateContent).not.toHaveBeenCalled()
  })

  it('falls back to a generic message when the error carries none', async () => {
    const { result } = setup({
      validations: [{ nodeId: 'a', field: 'general', message: '' }],
    })

    expect((await result.apply()).error).toBe('Validation failed.')
  })

  it('clears a previous error on the next successful apply', async () => {
    const { result } = setup({ validations: [{ nodeId: 'a', field: 'title', message: 'Nope.' }] })

    await result.apply()
    expect(result.applyError.value).toBe('Nope.')

    setup({})
    const second = harness?.result
    await second?.apply()

    expect(second?.applyError.value).toBeNull()
  })
})

describe('no-op apply', () => {
  it('succeeds without touching the API when the plan is empty', async () => {
    const { result } = setup({})

    expect(await result.apply()).toEqual({ success: true, operations: [] })
    expect(treeOperations).not.toHaveBeenCalled()
    expect(updateContent).not.toHaveBeenCalled()
  })
})

describe('create operations', () => {
  it('sends a create keyed by the draft id as temp_id', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        node('a', { title: 'About Us', blockId: 'block-article', settings: { disablePreview: true } }),
      ],
      operations: [createOp('a')],
    })

    await result.apply()

    expect(sentOperations()).toEqual([
      {
        type: 'create',
        temp_id: 'a',
        name: 'About Us',
        slug: 'about-us',
        block_id: 'block-article',
        parent_id: null,
        settings: { disablePreview: true },
      },
    ])
  })

  it('omits content entirely for a blank node so the server applies its defaults', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a')],
      operations: [createOp('a')],
    })

    await result.apply()

    expect(sentOperations()[0]).not.toHaveProperty('content')
  })

  it('sends template content when the node has any', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a', { content: { headline: 'Hi' } })],
      operations: [createOp('a')],
    })

    await result.apply()

    expect(sentOperations()[0]).toMatchObject({ content: { headline: 'Hi' } })
  })

  it('keeps a hand-typed slug instead of deriving one from the title', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a', { title: 'About Us', slug: 'about' })],
      operations: [createOp('a')],
    })

    await result.apply()

    expect(sentOperations()[0]).toMatchObject({ slug: 'about' })
  })

  it('creates parents before children regardless of plan order', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        node('a', { childrenIds: ['b'] }),
        node('b', { parentId: 'a', depth: 2 }),
      ],
      operations: [createOp('b', 2), createOp('a', 1)],
    })

    await result.apply()

    expect(sentOperations().map((operation) => (operation as { temp_id: string }).temp_id)).toEqual(
      ['a', 'b']
    )
  })

  it('references a not-yet-created parent by its temp id', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        node('a', { childrenIds: ['b'] }),
        node('b', { parentId: 'a', depth: 2 }),
      ],
      operations: [createOp('a'), createOp('b', 2)],
    })

    await result.apply()

    expect(sentOperations()[1]).toMatchObject({ parent_id: 'a' })
  })

  it('references an existing parent by its backend id', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['p']),
        existing('p', 'backend-p', { childrenIds: ['b'] }),
        node('b', { parentId: 'p', depth: 2 }),
      ],
      operations: [createOp('b', 2)],
    })

    await result.apply()

    expect(sentOperations()[0]).toMatchObject({ parent_id: 'backend-p' })
  })

  it('treats the virtual root as no parent at all', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a', { parentId: CONTENT_WIZARD_ROOT_ID })],
      operations: [createOp('a')],
    })

    await result.apply()

    expect(sentOperations()[0]).toMatchObject({ parent_id: null })
  })

  it('skips a create whose node has vanished from the tree', async () => {
    const { result } = setup({ nodes: [rootNode()], operations: [createOp('ghost')] })

    expect(await result.apply()).toMatchObject({ success: true })
    expect(treeOperations).not.toHaveBeenCalled()
  })
})

describe('moves and block changes on existing nodes', () => {
  const moved = (overrides: Partial<ContentWizardDraftNode> = {}) =>
    existing('a', 'backend-a', {
      parentId: 'p',
      original: {
        parentId: CONTENT_WIZARD_ROOT_ID,
        title: 'a',
        slug: '',
        blockId: 'block-page',
        blockType: 'nestable',
        position: 0,
      },
      ...overrides,
    })

  it('sends nothing for a node that has not changed', async () => {
    const { result } = setup({ nodes: [rootNode(['a']), existing('a', 'backend-a')] })

    await result.apply()

    expect(treeOperations).not.toHaveBeenCalled()
  })

  it('moves a reparented node under its new parent', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['p']),
        existing('p', 'backend-p', { childrenIds: ['a'] }),
        moved({ depth: 2 }),
      ],
    })

    await result.apply()

    expect(sentOperations()).toEqual([
      { type: 'move', ids: ['backend-a'], parent_id: 'backend-p', after_id: null },
    ])
  })

  it('moves a node whose position changed within the same parent', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { position: 2 }),
      ],
    })

    await result.apply()

    expect(sentOperations()).toEqual([
      { type: 'move', ids: ['backend-a'], parent_id: null, after_id: null },
    ])
  })

  it('anchors the move after the previous active sibling', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['first', 'a']),
        existing('first', 'backend-first'),
        existing('a', 'backend-a', { position: 1 }),
      ],
    })

    await result.apply()

    expect(sentOperations()[0]).toMatchObject({ after_id: 'backend-first' })
  })

  it('skips deleted siblings when choosing the anchor', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['first', 'gone', 'a']),
        existing('first', 'backend-first'),
        existing('gone', 'backend-gone', { deletedReason: 'self' }),
        existing('a', 'backend-a', { position: 2 }),
      ],
    })

    await result.apply()

    expect(sentOperations()[0]).toMatchObject({ after_id: 'backend-first' })
  })

  it('changes the block without moving when only the block changed', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { blockId: 'block-other' })],
    })

    await result.apply()

    expect(sentOperations()).toEqual([
      { type: 'update_block', id: 'backend-a', block_id: 'block-other' },
    ])
  })

  it('changes the block before moving in the ordinary case', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['p']),
        existing('p', 'backend-p', { childrenIds: ['a'] }),
        moved({ blockId: 'block-other', depth: 2 }),
      ],
    })

    await result.apply()

    expect(sentOperations().map((operation) => operation.type)).toEqual(['update_block', 'move'])
  })

  it('moves first when the node is becoming a single, which cannot be nested', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        moved({
          parentId: CONTENT_WIZARD_ROOT_ID,
          position: 3,
          blockId: 'block-settings',
          blockType: 'single',
        }),
      ],
    })

    await result.apply()

    expect(sentOperations().map((operation) => operation.type)).toEqual(['move', 'update_block'])
  })

  it('processes shallower nodes before deeper ones', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { childrenIds: ['b'], blockId: 'block-other' }),
        existing('b', 'backend-b', {
          parentId: 'a',
          depth: 3,
          blockId: 'block-other',
          original: {
            parentId: 'a',
            title: 'b',
            slug: '',
            blockId: 'block-page',
            blockType: 'nestable',
            position: 0,
          },
        }),
      ],
    })

    await result.apply()

    expect(sentOperations()).toEqual([
      { type: 'update_block', id: 'backend-a', block_id: 'block-other' },
      { type: 'update_block', id: 'backend-b', block_id: 'block-other' },
    ])
  })

  it('ignores a node marked deleted, even if it also moved', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), moved({ deletedReason: 'self' })],
    })

    await result.apply()

    expect(treeOperations).not.toHaveBeenCalled()
  })
})

describe('delete operations', () => {
  it('deletes each node by backend id, deepest first', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { childrenIds: ['b'], deletedReason: 'self' }),
        existing('b', 'backend-b', { parentId: 'a', depth: 2, deletedReason: 'ancestor' }),
      ],
      operations: [deleteOp('a', 1), deleteOp('b', 2)],
    })

    await result.apply()

    expect(sentOperations()).toEqual([
      { type: 'delete', ids: ['backend-b'] },
      { type: 'delete', ids: ['backend-a'] },
    ])
  })

  it('skips a delete for a node that was never persisted', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a', { deletedReason: 'self' })],
      operations: [deleteOp('a')],
    })

    await result.apply()

    expect(treeOperations).not.toHaveBeenCalled()
  })

  it('sends creates, then moves, then deletes in a single batch', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['new', 'a', 'old']),
        node('new'),
        existing('a', 'backend-a', { position: 5 }),
        existing('old', 'backend-old', { deletedReason: 'self' }),
      ],
      operations: [createOp('new'), deleteOp('old')],
    })

    await result.apply()

    expect(treeOperations).toHaveBeenCalledTimes(1)
    expect(sentOperations().map((operation) => operation.type)).toEqual([
      'create',
      'move',
      'delete',
    ])
  })
})

describe('title and slug updates', () => {
  it('updates a renamed node after the structural batch', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { title: 'Renamed', position: 1 }),
      ],
    })

    await result.apply()

    expect(updateContent).toHaveBeenCalledWith({
      id: 'backend-a',
      payload: { name: 'Renamed', slug: 'renamed' },
    })
    expect(treeOperations.mock.invocationCallOrder[0]).toBeLessThan(
      updateContent.mock.invocationCallOrder[0]
    )
  })

  it('updates a node whose slug was overridden without a rename', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { slug: 'custom' })],
    })

    await result.apply()

    expect(updateContent).toHaveBeenCalledWith({
      id: 'backend-a',
      payload: { name: 'a', slug: 'custom' },
    })
  })

  it('does not update when the slug only changed in a way slugify normalizes away', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', {
          title: 'Hello World',
          slug: 'hello-world',
          original: {
            parentId: CONTENT_WIZARD_ROOT_ID,
            title: 'Hello World',
            slug: '',
            blockId: 'block-page',
            blockType: 'nestable',
            position: 0,
          },
        }),
      ],
    })

    await result.apply()

    expect(updateContent).not.toHaveBeenCalled()
  })

  it('does not update an unchanged node', async () => {
    const { result } = setup({ nodes: [rootNode(['a']), existing('a', 'backend-a')] })

    await result.apply()

    expect(updateContent).not.toHaveBeenCalled()
  })

  it('does not update a deleted node', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { title: 'Renamed', deletedReason: 'self' }),
      ],
      operations: [deleteOp('a')],
    })

    await result.apply()

    expect(updateContent).not.toHaveBeenCalled()
  })

  // Pinned actual behaviour: newly created nodes carry their name and slug in
  // the create payload, so no follow-up update is issued for them.
  it('does not issue an update for a freshly created node', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a', { title: 'New' })],
      operations: [createOp('a')],
    })

    await result.apply()

    expect(updateContent).not.toHaveBeenCalled()
  })

  it('updates each changed node one at a time, shallowest first', async () => {
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { title: 'A2', childrenIds: ['b'] }),
        existing('b', 'backend-b', { title: 'B2', parentId: 'a', depth: 2 }),
      ],
    })

    await result.apply()

    expect(updateContent.mock.calls.map(([payload]) => payload.id)).toEqual([
      'backend-a',
      'backend-b',
    ])
  })
})

describe('failure handling', () => {
  it('reports the structural failure and skips the field updates', async () => {
    treeOperations.mockRejectedValue(new Error('Move rejected.'))
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { title: 'Renamed', position: 1 })],
      operations: [],
    })

    const outcome = await result.apply()

    expect(outcome).toMatchObject({ success: false, error: 'Move rejected.' })
    expect(result.applyError.value).toBe('Move rejected.')
    expect(updateContent).not.toHaveBeenCalled()
  })

  it('refetches content after a failure so the canvas is not left lying', async () => {
    treeOperations.mockRejectedValue(new Error('boom'))
    const { result, queryClient } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { position: 1 })],
    })
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.apply()

    expect(invalidate).toHaveBeenCalledTimes(3)
  })

  it('reports a failed field update, leaving the structural changes applied', async () => {
    updateContent.mockRejectedValue(new Error('Slug taken.'))
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { title: 'Renamed' })],
    })

    expect(await result.apply()).toMatchObject({ success: false, error: 'Slug taken.' })
  })

  it('stops at the first failing update rather than continuing the loop', async () => {
    updateContent.mockRejectedValue(new Error('nope'))
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { title: 'A2' }),
        existing('b', 'backend-b', { title: 'B2', depth: 2 }),
      ],
    })

    await result.apply()

    expect(updateContent).toHaveBeenCalledTimes(1)
  })

  it('falls back to a generic message for a non-Error rejection', async () => {
    treeOperations.mockRejectedValue('string failure')
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { position: 1 })],
    })

    expect(await result.apply()).toMatchObject({ error: 'Apply failed.' })
  })

  it('returns the plan alongside the failure', async () => {
    treeOperations.mockRejectedValue(new Error('boom'))
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { position: 1 })],
      operations: [createOp('a')],
    })

    expect((await result.apply()).operations).toEqual([createOp('a')])
  })
})

describe('repeated apply', () => {
  // The plan comes from the tree, which apply never resets, so without a guard a
  // second call re-sends the identical batch — a double click was enough.
  it('does not re-send an unchanged plan', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a')],
      operations: [createOp('a')],
    })

    await result.apply()
    const second = await result.apply()

    expect(treeOperations).toHaveBeenCalledTimes(1)
    expect(second).toEqual({ success: true, operations: [createOp('a')] })
  })

  it('shares one run between two overlapping calls', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), node('a')],
      operations: [createOp('a')],
    })

    const [first, second] = await Promise.all([result.apply(), result.apply()])

    expect(treeOperations).toHaveBeenCalledTimes(1)
    expect(first).toEqual(second)
  })

  it('sends again once the node fields have changed', async () => {
    const nodes = [rootNode(['a']), node('a')]
    const { result } = setup({ nodes, operations: [createOp('a')] })

    await result.apply()
    nodes[1].title = 'Renamed'
    await result.apply()

    expect(treeOperations).toHaveBeenCalledTimes(2)
  })

  it('retries after a failure rather than treating the plan as applied', async () => {
    treeOperations.mockRejectedValueOnce(new Error('boom'))
    const { result } = setup({
      nodes: [rootNode(['a']), node('a')],
      operations: [createOp('a')],
    })

    expect(await result.apply()).toMatchObject({ success: false })
    expect(await result.apply()).toMatchObject({ success: true })
    expect(treeOperations).toHaveBeenCalledTimes(2)
  })

  // The mutations' own onSuccess covers contents and contentMenu but not
  // blocks(...).lists(), which the failure path does invalidate — with field
  // updates only, block lists stayed stale.
  it('invalidates content queries on success too', async () => {
    const { result, queryClient } = setup({
      nodes: [rootNode(['a']), node('a')],
      operations: [createOp('a')],
    })
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.apply()

    const invalidatedKeys = invalidate.mock.calls.map(
      ([options]) => (options as { queryKey: readonly unknown[] }).queryKey
    )

    expect(invalidatedKeys).toEqual([
      queryKeys.contentMenu(SPACE).all(),
      queryKeys.contents(SPACE).lists(),
      queryKeys.blocks(SPACE).lists(),
    ])
  })
})

describe('applyProgress', () => {
  // The field-update pass is sequential and cannot be rolled back, so a failure
  // on the Nth leaves 1..N-1 applied along with the whole structural batch. The
  // caller has no other way to learn how far it got.
  it('reports how many field updates went through before a failure', async () => {
    updateContent.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Slug taken.'))
    const { result } = setup({
      nodes: [
        rootNode(['a']),
        existing('a', 'backend-a', { title: 'A2' }),
        existing('b', 'backend-b', { title: 'B2', depth: 2 }),
      ],
    })

    await result.apply()

    expect(result.applyProgress.value).toEqual({ completed: 1, total: 2 })
  })

  it('reports every update as completed on success', async () => {
    const { result } = setup({
      nodes: [rootNode(['a']), existing('a', 'backend-a', { title: 'A2' })],
    })

    await result.apply()

    expect(result.applyProgress.value).toEqual({ completed: 1, total: 1 })
  })

  it('is null until the field-update pass starts', () => {
    const { result } = setup({})

    expect(result.applyProgress.value).toBeNull()
  })
})

describe('invalidateContentQueries', () => {
  it('invalidates the content menu, content lists and block lists', async () => {
    const { result, queryClient } = setup({})
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.invalidateContentQueries()

    const invalidatedKeys = invalidate.mock.calls.map(
      ([options]) => (options as { queryKey: readonly unknown[] }).queryKey
    )

    expect(invalidatedKeys).toEqual([
      queryKeys.contentMenu(SPACE).all(),
      queryKeys.contents(SPACE).lists(),
      queryKeys.blocks(SPACE).lists(),
    ])
  })
})

describe('isApplying', () => {
  it('is true while either mutation is pending', () => {
    const { result } = setup({})

    expect(result.isApplying.value).toBe(false)

    treeOperationsPending.value = true
    expect(result.isApplying.value).toBe(true)

    treeOperationsPending.value = false
    updatePending.value = true
    expect(result.isApplying.value).toBe(true)
  })
})

describe('reactive space id', () => {
  it('keys the invalidations off the current space', async () => {
    const spaceId: Ref<string> = ref('space-a')
    const treeApi = {
      tree: ref<ContentWizardDraftTree>({ rootId: CONTENT_WIZARD_ROOT_ID, nodes: {} }),
      operationPlan: computed(() => [] as ContentWizardOperation[]),
      validations: computed(() => [] as ContentWizardValidationError[]),
      getNode: () => null,
    } as unknown as TreeApi

    harness = withSetup(() => useContentWizardApply(spaceId, treeApi))
    harness.queryClient.setQueryData(['spaces', 'space-a', 'content-menu', 'x'], [])
    harness.queryClient.setQueryData(['spaces', 'space-b', 'content-menu', 'x'], [])

    spaceId.value = 'space-b'
    await harness.result.invalidateContentQueries()

    // The key holds the ref itself; vue-query unwraps it when matching, so only
    // the current space's cache entry is marked stale.
    expect(
      harness.queryClient.getQueryState(['spaces', 'space-b', 'content-menu', 'x'])?.isInvalidated
    ).toBe(true)
    expect(
      harness.queryClient.getQueryState(['spaces', 'space-a', 'content-menu', 'x'])?.isInvalidated
    ).toBe(false)
  })
})
