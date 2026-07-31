import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const blocks = {
  index: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
}

const success = vi.fn()
const failure = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ blocks }) } }))
vi.mock('vue-sonner', () => ({ toast: { success, error: failure } }))

const { useBlocks } = await import('~/composables/useBlocks')

const SPACE = 'space-1'

const block = (id: string, slug = id) => ({ id, slug, name: slug }) as unknown as BlockResource

const envelope = (data: BlockResource[]) =>
  ({ data }) as unknown as ApiResponse<BlockResource[] | undefined>

let harness: Harness<ReturnType<typeof mountBlocks>> | undefined

const mountBlocks = () => {
  const composable = useBlocks(SPACE)

  return {
    ...composable,
    create: composable.useCreateBlockMutation(),
    update: composable.useUpdateBlockMutation(),
    remove: composable.useDeleteBlockMutation(),
  }
}

const setup = () => {
  harness = withSetup(mountBlocks)
  return harness
}

const mutations = () => setup().result

const spyInvalidate = () => vi.spyOn((harness as Harness<unknown>).queryClient, 'invalidateQueries')

const invalidatedKeys = (spy: ReturnType<typeof spyInvalidate>) =>
  spy.mock.calls.map(([filters]) => (typeof filters === 'function' ? filters() : filters)?.queryKey)

beforeEach(() => {
  for (const fn of Object.values(blocks)) fn.mockReset()
  success.mockReset()
  failure.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useBlocksQuery', () => {
  it('sends a default slug sort that is not part of the key', async () => {
    blocks.index.mockResolvedValue({ data: [block('b1')] })

    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlocksQuery())

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.blocks(SPACE).list({}))).toEqual({
        data: [block('b1')],
      })
    )
    expect(blocks.index).toHaveBeenCalledWith({ sort: '+slug' })
    queryClient.clear()
  })

  it('lets the caller override the sort and keys by the raw params', async () => {
    blocks.index.mockResolvedValue({ data: [] })
    const params = { sort: '-name', filter: { folder_id: 'f1' } }

    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlocksQuery(params))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.blocks(SPACE).list(params))).toBeDefined()
    )
    expect(blocks.index).toHaveBeenCalledWith(params)
    queryClient.clear()
  })

  it('stays disabled while the caller says so', () => {
    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlocksQuery({}, false))

    expect(blocks.index).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled without a space id', () => {
    const { queryClient } = withSetup(() => useBlocks('').useBlocksQuery())

    expect(blocks.index).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('re-reads the params when a ref changes', async () => {
    blocks.index.mockResolvedValue({ data: [] })
    const params = ref({ page: 1 })

    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlocksQuery(params))

    await vi.waitFor(() => expect(blocks.index).toHaveBeenCalledWith({ sort: '+slug', page: 1 }))
    params.value = { page: 2 }

    await vi.waitFor(() => expect(blocks.index).toHaveBeenCalledWith({ sort: '+slug', page: 2 }))
    queryClient.clear()
  })
})

describe('useBlockQuery', () => {
  it('unwraps the envelope under the detail key', async () => {
    blocks.get.mockResolvedValue({ data: block('b1') })

    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlockQuery('b1'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.blocks(SPACE).detail('b1'))).toMatchObject({
        id: 'b1',
      })
    )
    queryClient.clear()
  })

  it('stays disabled for an empty id', () => {
    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlockQuery(''))

    expect(blocks.get).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled while the caller says so', () => {
    const { queryClient } = withSetup(() => useBlocks(SPACE).useBlockQuery('b1', false))

    expect(blocks.get).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useBlockBySlugQuery', () => {
  it('picks the matching block out of the cached list', async () => {
    const { queryClient, result } = withSetup(() => useBlocks(SPACE).useBlockBySlugQuery('hero'), {
      seed: [[queryKeys.blocks(SPACE).list({}), { data: [block('b1', 'page'), block('b2', 'hero')] }]],
    })

    await vi.waitFor(() => expect(result.block.value).toMatchObject({ id: 'b2' }))
    // It reuses the plain list key, so no second request is made.
    expect(blocks.index).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('returns null for an unknown slug', async () => {
    const { queryClient, result } = withSetup(() => useBlocks(SPACE).useBlockBySlugQuery('ghost'), {
      seed: [[queryKeys.blocks(SPACE).list({}), { data: [block('b1', 'page')] }]],
    })

    expect(result.block.value).toBeNull()
    queryClient.clear()
  })

  it('returns null while the list is still loading', () => {
    blocks.index.mockReturnValue(new Promise(() => {}))

    const { queryClient, result } = withSetup(() => useBlocks(SPACE).useBlockBySlugQuery('hero'))

    expect(result.block.value).toBeNull()
    expect(result.isLoading.value).toBe(true)
    queryClient.clear()
  })

  it('follows a changing slug ref without refetching', async () => {
    const { queryClient, result } = withSetup(
      () => {
        const slug = ref('page')
        return { ...useBlocks(SPACE).useBlockBySlugQuery(slug), slug }
      },
      {
        seed: [
          [queryKeys.blocks(SPACE).list({}), { data: [block('b1', 'page'), block('b2', 'hero')] }],
        ],
      }
    )

    expect(result.block.value).toMatchObject({ id: 'b1' })
    result.slug.value = 'hero'

    await vi.waitFor(() => expect(result.block.value).toMatchObject({ id: 'b2' }))
    queryClient.clear()
  })
})

describe('getBlockBySlug and getBlockById', () => {
  it('finds a block by slug and by id', () => {
    const { getBlockBySlug, getBlockById } = mutations()
    const list = envelope([block('b1', 'page'), block('b2', 'hero')])

    expect(getBlockBySlug(list, 'hero')).toMatchObject({ id: 'b2' })
    expect(getBlockById(list, 'b1')).toMatchObject({ slug: 'page' })
  })

  it('returns null for every empty answer, however it arose', () => {
    const { getBlockBySlug } = mutations()

    // One "not found" value, so a caller checking `=== null` covers all three.
    expect(getBlockBySlug(envelope([]), 'hero')).toBeNull()
    expect(getBlockBySlug({} as never, 'hero')).toBeNull()
    expect(getBlockBySlug(undefined as never, 'hero')).toBeNull()
  })

  it('compares strictly, so a numeric id does not match a string one', () => {
    const { getBlockById } = mutations()

    // With `==` this matched, which also made id `0` match `''`.
    expect(getBlockById(envelope([block('1')]), 1 as unknown as string)).toBeNull()
  })

  it('unwraps a ref list and a ref slug', () => {
    const { getBlockBySlug } = mutations()

    expect(getBlockBySlug(ref(envelope([block('b1', 'page')])) as never, ref('page'))).toMatchObject(
      { id: 'b1' }
    )
  })
})

describe('useCreateBlockMutation', () => {
  it('invalidates the block lists and the content menu', async () => {
    blocks.create.mockResolvedValue({ data: block('b1', 'hero') })
    const { create } = mutations()
    const invalidate = spyInvalidate()

    await create.mutateAsync({ slug: 'hero' } as never)

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.blocks(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    expect(success).toHaveBeenCalledWith('Block "hero" created successfully')
  })

  it('reports failure', async () => {
    blocks.create.mockRejectedValue({ message: 'slug taken' })

    await expect(mutations().create.mutateAsync({} as never)).rejects.toBeTruthy()
    expect(failure).toHaveBeenCalledWith('Failed to create block: slug taken')
  })

  it('falls back to "Unknown error" for an empty message', async () => {
    blocks.create.mockRejectedValue({ message: '' })

    await expect(mutations().create.mutateAsync({} as never)).rejects.toBeTruthy()
    expect(failure).toHaveBeenCalledWith('Failed to create block: Unknown error')
  })
})

describe('useUpdateBlockMutation', () => {
  it('invalidates the lists, the detail, the versions, the templates and the menu', async () => {
    blocks.update.mockResolvedValue({ data: block('b1', 'hero') })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'b1', payload: { name: 'Hero' } as never })

    expect(blocks.update).toHaveBeenCalledWith('b1', { name: 'Hero' })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.blocks(SPACE).lists(),
      queryKeys.blocks(SPACE).detail('b1'),
      queryKeys.blockVersions(SPACE, 'b1').lists(),
      queryKeys.blockTemplates(SPACE, 'b1').lists(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    expect(success).toHaveBeenCalledWith('Block "hero" updated successfully')
  })

  it('refreshes the block templates, which a schema change can invalidate', async () => {
    blocks.update.mockResolvedValue({ data: block('b1') })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'b1', payload: {} as never })

    expect(invalidatedKeys(invalidate)).toContainEqual(
      queryKeys.blockTemplates(SPACE, 'b1').lists()
    )
  })

  it('reports failure', async () => {
    blocks.update.mockRejectedValue({ message: 'invalid schema' })

    await expect(
      mutations().update.mutateAsync({ id: 'b1', payload: {} as never })
    ).rejects.toBeTruthy()
    expect(failure).toHaveBeenCalledWith('Failed to update block: invalid schema')
  })
})

describe('useDeleteBlockMutation', () => {
  it('drops the detail cache and invalidates the lists and the menu', async () => {
    blocks.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync('b1')

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.blocks(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    expect(removeQueries).toHaveBeenCalledWith({ queryKey: queryKeys.blocks(SPACE).detail('b1') })
    expect(success).toHaveBeenCalledWith('Block deleted successfully')
  })

  it('drops the deleted block versions and templates too', async () => {
    // Both live under blocks(...).all() but outside detail(id), so each needs
    // its own removal.
    blocks.delete.mockResolvedValue(undefined)
    const versions = queryKeys.blockVersions(SPACE, 'b1').list({})
    const templates = queryKeys.blockTemplates(SPACE, 'b1').list({})

    harness = withSetup(mountBlocks, {
      seed: [
        [versions, [{ id: 'v1' }]],
        [templates, [{ id: 't1' }]],
      ],
    })

    await harness.result.remove.mutateAsync('b1')

    expect(harness.queryClient.getQueryData(versions)).toBeUndefined()
    expect(harness.queryClient.getQueryData(templates)).toBeUndefined()
  })

  it('reports failure', async () => {
    blocks.delete.mockRejectedValue({ message: 'in use' })

    await expect(mutations().remove.mutateAsync('b1')).rejects.toBeTruthy()
    expect(failure).toHaveBeenCalledWith('Failed to delete block: in use')
  })
})
