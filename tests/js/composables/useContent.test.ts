import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { ContentResource } from '~/types/contents'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const contents = {
  index: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  publish: vi.fn(),
  schedule: vi.fn(),
  unpublish: vi.fn(),
  duplicate: vi.fn(),
  delete: vi.fn(),
  bulkCreate: vi.fn(),
  move: vi.fn(),
  treeOperations: vi.fn(),
  serialPreview: vi.fn(),
  exportTranslations: vi.fn(),
  importTranslations: vi.fn(),
}

const success = vi.fn()
const failure = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ contents }) } }))
vi.mock('vue-sonner', () => ({ toast: { success, error: failure } }))

const { useContent } = await import('~/composables/useContent')

const SPACE = 'space-1'

const content = (overrides: Partial<ContentResource> = {}) =>
  ({
    id: 'c1',
    name: 'Home',
    i18n_canonical_id: null,
    language_versions: [],
    ...overrides,
  }) as unknown as ContentResource

let harness: Harness<ReturnType<typeof mountContent>> | undefined

// Every mutation is instantiated in one setup: useMutation may only run inside
// a component instance, and sharing the client keeps the spies on one object.
const mountContent = () => {
  const composable = useContent(SPACE)

  return {
    ...composable,
    create: composable.useCreateContentMutation(),
    update: composable.useUpdateContentMutation(),
    publish: composable.usePublishContentMutation(),
    schedule: composable.useScheduleContentMutation(),
    unpublish: composable.useUnpublishContentMutation(),
    duplicate: composable.useDuplicateContentMutation(),
    remove: composable.useDeleteContentMutation(),
    bulkCreate: composable.useBulkCreateContentMutation(),
    move: composable.useMoveContentMutation(),
    tree: composable.useTreeOperationsMutation(),
    exportTranslations: composable.useExportContentTranslationsMutation(),
    importTranslations: composable.useImportContentTranslationsMutation(),
  }
}

const setup = () => {
  harness = withSetup(mountContent)
  return harness
}

const mutations = () => setup().result

const spyInvalidate = () => vi.spyOn((harness as Harness<unknown>).queryClient, 'invalidateQueries')

const invalidatedKeys = (spy: ReturnType<typeof spyInvalidate>) =>
  spy.mock.calls.map(([filters]) => (typeof filters === 'function' ? filters() : filters)?.queryKey)

beforeEach(() => {
  for (const fn of Object.values(contents)) fn.mockReset()
  success.mockReset()
  failure.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useContentsQuery', () => {
  it('caches the whole response envelope under the list key', async () => {
    const response = { data: [content()], meta: { total: 1 } }
    contents.index.mockResolvedValue(response)

    const { queryClient } = withSetup(() => useContent(SPACE).useContentsQuery())

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.contents(SPACE).list({}))).toEqual(response)
    )
    expect(contents.index).toHaveBeenCalledWith({})
    queryClient.clear()
  })

  it('forwards the caller params and keys by them', async () => {
    contents.index.mockResolvedValue({ data: [] })
    const params = { block_id: 'b1' }

    const { queryClient } = withSetup(() => useContent(SPACE).useContentsQuery(params))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.contents(SPACE).list(params))).toEqual({ data: [] })
    )
    expect(contents.index).toHaveBeenCalledWith(params)
    queryClient.clear()
  })
})

describe('useContentQuery', () => {
  it('unwraps the data envelope under the detail key', async () => {
    contents.get.mockResolvedValue({ data: content() })

    const { queryClient } = withSetup(() => useContent(SPACE).useContentQuery('c1'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.contents(SPACE).detail('c1'))).toMatchObject({
        id: 'c1',
      })
    )
    queryClient.clear()
  })

  it('resolves a ref id, so the key still matches a literal one', async () => {
    contents.get.mockResolvedValue({ data: content({ id: 'c9' }) })

    const { queryClient } = withSetup(() => useContent(SPACE).useContentQuery(ref('c9')))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.contents(SPACE).detail('c9'))).toMatchObject({
        id: 'c9',
      })
    )
    queryClient.clear()
  })

  it('stays disabled for a null id instead of requesting an empty one', async () => {
    const { queryClient, result } = withSetup(() => useContent(SPACE).useContentQuery(null))

    expect(result.isFetching.value).toBe(false)
    expect(contents.get).not.toHaveBeenCalled()
    // The key still resolves, so an enabled sibling with a real id is separate.
    expect(queryClient.getQueryData(queryKeys.contents(SPACE).detail(''))).toBeUndefined()
    queryClient.clear()
  })
})

describe('useContentChildrenQuery', () => {
  it('caches the same envelope shape as useContentsQuery', async () => {
    const response = { data: [content({ id: 'child' })], meta: { total: 1 } }
    contents.index.mockResolvedValue(response)

    const { queryClient } = withSetup(() => useContent(SPACE).useContentChildrenQuery('parent-1'))

    // Both queries live under contents.lists(), so anything reading over that
    // prefix must find one shape, not two.
    await vi.waitFor(() =>
      expect(
        queryClient.getQueryData(queryKeys.contents(SPACE).list({ parent_id: 'parent-1' }))
      ).toEqual(response)
    )
    queryClient.clear()
  })

  it('sends parent_id at the top level, where the backend filter reads it', async () => {
    contents.index.mockResolvedValue({ data: [] })

    const { queryClient } = withSetup(() => useContent(SPACE).useContentChildrenQuery('parent-1'))

    // ContentFilter dispatches on top-level query keys — a `filter` wrapper is
    // ignored, so the request used to return the whole unfiltered list.
    await vi.waitFor(() => expect(contents.index).toHaveBeenCalledWith({ parent_id: 'parent-1' }))
    // And the key is those same params, so useContentsQuery asking for the same
    // rows shares one cache entry instead of drifting apart under two.
    await vi.waitFor(() =>
      expect(
        queryClient.getQueryData(queryKeys.contents(SPACE).list({ parent_id: 'parent-1' }))
      ).toEqual({ data: [] })
    )
    queryClient.clear()
  })

  it('asks for the root children with the literal string the filter expects', async () => {
    contents.index.mockResolvedValue({ data: [] })

    const { queryClient } = withSetup(() => useContent(SPACE).useContentChildrenQuery(null))

    // A real null is dropped from the query string; `'null'` is what
    // ContentFilter::parent_id() turns into whereNull.
    await vi.waitFor(() => expect(contents.index).toHaveBeenCalledWith({ parent_id: 'null' }))
    queryClient.clear()
  })
})

describe('useSerialPreviewQuery', () => {
  const previewKey = (params: Record<string, unknown>) => [
    ...queryKeys.contents(SPACE).all(),
    'serial-preview',
    params,
  ]

  it('normalizes every optional field to null and the block id to a string', async () => {
    contents.serialPreview.mockResolvedValue({ name: 'INV-0001' })

    const { queryClient } = withSetup(() =>
      useContent(SPACE).useSerialPreviewQuery({ block_id: 'b1' }, true)
    )

    const normalized = {
      block_id: 'b1',
      parent_id: null,
      language_iso: null,
      name: null,
      i18n_parent_id: null,
      except_content_id: null,
    }

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(previewKey(normalized))).toEqual({ name: 'INV-0001' })
    )
    expect(contents.serialPreview).toHaveBeenCalledWith(normalized)
    queryClient.clear()
  })

  it('passes through every provided field', async () => {
    contents.serialPreview.mockResolvedValue({})

    const { queryClient } = withSetup(() =>
      useContent(SPACE).useSerialPreviewQuery(
        {
          block_id: 'b1',
          parent_id: 'p1',
          language_iso: 'de',
          name: 'Draft',
          i18n_parent_id: 'i1',
          except_content_id: 'c1',
        },
        true
      )
    )

    await vi.waitFor(() =>
      expect(contents.serialPreview).toHaveBeenCalledWith({
        block_id: 'b1',
        parent_id: 'p1',
        language_iso: 'de',
        name: 'Draft',
        i18n_parent_id: 'i1',
        except_content_id: 'c1',
      })
    )
    queryClient.clear()
  })

  it('stays disabled without a block id, even when asked to run', () => {
    const { queryClient } = withSetup(() =>
      useContent(SPACE).useSerialPreviewQuery({ block_id: null }, true)
    )

    expect(contents.serialPreview).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled while the caller says so', () => {
    const { queryClient } = withSetup(() =>
      useContent(SPACE).useSerialPreviewQuery({ block_id: 'b1' }, false)
    )

    expect(contents.serialPreview).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('runs once the caller enables it', async () => {
    contents.serialPreview.mockResolvedValue({})
    const enabled = ref(false)

    const { queryClient } = withSetup(() =>
      useContent(SPACE).useSerialPreviewQuery({ block_id: 'b1' }, enabled)
    )

    enabled.value = true

    await vi.waitFor(() => expect(contents.serialPreview).toHaveBeenCalledTimes(1))
    queryClient.clear()
  })

  it('does not fold an empty block id into the key of a real one', async () => {
    contents.serialPreview.mockResolvedValue({})

    const { queryClient } = withSetup(() =>
      useContent(SPACE).useSerialPreviewQuery({ block_id: undefined }, true)
    )

    // block_id normalizes to '' but `enabled` gates on the raw value, so
    // nothing is ever cached under the empty-string key.
    expect(queryClient.getQueryData(previewKey({ block_id: '' }))).toBeUndefined()
    queryClient.clear()
  })
})

describe('useCreateContentMutation', () => {
  it('invalidates the lists, the menu and the new content family', async () => {
    contents.create.mockResolvedValue({ data: content() })
    const { create } = mutations()
    const invalidate = spyInvalidate()

    await create.mutateAsync({ name: 'Home', slug: 'home', block_id: 'b1' } as never)

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
      queryKeys.contents(SPACE).detail('c1'),
      queryKeys.contentVersions(SPACE, 'c1').lists(),
    ])
  })

  it('reports the created name', async () => {
    contents.create.mockResolvedValue({ data: content({ name: 'About' }) })

    await mutations().create.mutateAsync({} as never)

    expect(success).toHaveBeenCalledWith('Content "About" created successfully')
  })

  it('reports the failure reason', async () => {
    contents.create.mockRejectedValue(new Error('slug taken'))

    await expect(mutations().create.mutateAsync({} as never)).rejects.toThrow('slug taken')
    expect(failure).toHaveBeenCalledWith('Failed to create content: slug taken')
    expect(success).not.toHaveBeenCalled()
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    contents.create.mockRejectedValue(new Error(''))

    await expect(mutations().create.mutateAsync({} as never)).rejects.toThrow()
    expect(failure).toHaveBeenCalledWith('Failed to create content: Unknown error')
  })
})

describe('invalidateContentFamily', () => {
  it('invalidates the canonical entry and every language version', async () => {
    contents.update.mockResolvedValue({
      data: content({
        id: 'de',
        i18n_canonical_id: 'canonical',
        language_versions: [{ content_id: 'en' }, { content_id: 'fr' }] as never,
      }),
    })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'de', payload: {} })

    expect(invalidatedKeys(invalidate).slice(2)).toEqual([
      queryKeys.contents(SPACE).detail('de'),
      queryKeys.contentVersions(SPACE, 'de').lists(),
      queryKeys.contents(SPACE).detail('canonical'),
      queryKeys.contentVersions(SPACE, 'canonical').lists(),
      queryKeys.contents(SPACE).detail('en'),
      queryKeys.contentVersions(SPACE, 'en').lists(),
      queryKeys.contents(SPACE).detail('fr'),
      queryKeys.contentVersions(SPACE, 'fr').lists(),
    ])
  })

  it('invalidates each family member once, however often it repeats', async () => {
    contents.update.mockResolvedValue({
      data: content({
        id: 'de',
        i18n_canonical_id: 'de',
        language_versions: [{ content_id: 'de' }, { content_id: 'de' }] as never,
      }),
    })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'de', payload: {} })

    expect(invalidatedKeys(invalidate)).toHaveLength(4)
  })

  it('skips a language version with no content id', async () => {
    contents.update.mockResolvedValue({
      data: content({ language_versions: [{ content_id: null }] as never }),
    })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'c1', payload: {} })

    expect(invalidatedKeys(invalidate)).toHaveLength(4)
  })

  it('still succeeds when the response omits language_versions', async () => {
    // A committed write must never surface as an error toast just because the
    // response left the field out; the entry itself is still invalidated.
    contents.update.mockResolvedValue({ data: { id: 'c1', name: 'Home' } })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'c1', payload: {} })

    expect(failure).not.toHaveBeenCalled()
    expect(success).toHaveBeenCalledWith('Content "Home" updated successfully')
    expect(invalidatedKeys(invalidate)).toContainEqual(queryKeys.contents(SPACE).detail('c1'))
  })
})

describe('useUpdateContentMutation', () => {
  it('reports the updated name', async () => {
    contents.update.mockResolvedValue({ data: content({ name: 'Home' }) })

    await mutations().update.mutateAsync({ id: 'c1', payload: {} })

    expect(success).toHaveBeenCalledWith('Content "Home" updated successfully')
    expect(contents.update).toHaveBeenCalledWith('c1', {})
  })

  it('stays silent on a 409 conflict, which the editor resolves itself', async () => {
    contents.update.mockRejectedValue({ status: 409, data: { conflict: { id: 'v2' } } })

    await expect(mutations().update.mutateAsync({ id: 'c1', payload: {} })).rejects.toMatchObject({
      status: 409,
    })
    expect(failure).not.toHaveBeenCalled()
  })

  it('still reports a 409 that carries no conflict payload', async () => {
    contents.update.mockRejectedValue({ status: 409, message: 'stale' })

    await expect(mutations().update.mutateAsync({ id: 'c1', payload: {} })).rejects.toBeTruthy()
    expect(failure).toHaveBeenCalledWith('Failed to update content: stale')
  })
})

describe('publish, schedule and unpublish', () => {
  const cases = [
    ['publish', 'publish', 'Content "Home" published successfully', 'Failed to publish content: nope'],
    ['schedule', 'schedule', 'Content "Home" scheduled successfully', 'Failed to schedule content: nope'],
    [
      'unpublish',
      'unpublish',
      'Content "Home" unpublished successfully',
      'Failed to unpublish content: nope',
    ],
  ] as const

  it.each(cases)('%s forwards id and payload untouched', async (name, endpoint) => {
    contents[endpoint].mockResolvedValue({ data: content() })
    const payload = { publish_at: '2026-01-01', force: true } as never

    await mutations()[name].mutateAsync({ id: 'c1', payload })

    expect(contents[endpoint]).toHaveBeenCalledWith('c1', payload)
  })

  it.each(cases)('%s invalidates the lists, the menu and the family', async (name, endpoint) => {
    contents[endpoint].mockResolvedValue({ data: content() })
    const mutation = mutations()[name]
    const invalidate = spyInvalidate()

    await mutation.mutateAsync({ id: 'c1', payload: {} })

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
      queryKeys.contents(SPACE).detail('c1'),
      queryKeys.contentVersions(SPACE, 'c1').lists(),
    ])
  })

  it.each(cases)('%s reports success', async (name, endpoint, message) => {
    contents[endpoint].mockResolvedValue({ data: content() })

    await mutations()[name].mutateAsync({ id: 'c1', payload: {} })

    expect(success).toHaveBeenCalledWith(message)
  })

  it.each(cases)('%s reports failure', async (name, endpoint, _message, error) => {
    contents[endpoint].mockRejectedValue(new Error('nope'))

    await expect(mutations()[name].mutateAsync({ id: 'c1', payload: {} })).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith(error)
  })
})

describe('useDuplicateContentMutation', () => {
  it('invalidates the lists and the menu but not the family', async () => {
    contents.duplicate.mockResolvedValue({ data: content({ id: 'copy', name: 'Home copy' }) })
    const { duplicate } = mutations()
    const invalidate = spyInvalidate()

    await duplicate.mutateAsync({ id: 'c1' })

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    expect(success).toHaveBeenCalledWith('Content duplicated successfully as "Home copy"')
  })

  it('survives a response without language_versions, unlike the other writes', async () => {
    // Only the family-invalidating mutations dereference language_versions.
    contents.duplicate.mockResolvedValue({ data: { id: 'copy', name: 'Copy' } })

    await mutations().duplicate.mutateAsync({ id: 'c1' })

    expect(success).toHaveBeenCalledWith('Content duplicated successfully as "Copy"')
  })

  it('forwards the optional overrides', async () => {
    contents.duplicate.mockResolvedValue({ data: content() })

    await mutations().duplicate.mutateAsync({ id: 'c1', options: { name: 'X', parent_id: null } })

    expect(contents.duplicate).toHaveBeenCalledWith('c1', { name: 'X', parent_id: null })
  })

  it('reports failure', async () => {
    contents.duplicate.mockRejectedValue(new Error('nope'))

    await expect(mutations().duplicate.mutateAsync({ id: 'c1' })).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to duplicate content: nope')
  })
})

describe('useDeleteContentMutation', () => {
  it('drops the detail cache and invalidates the lists and the menu', async () => {
    contents.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync('c1')

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    expect(removeQueries).toHaveBeenCalledWith({ queryKey: queryKeys.contents(SPACE).detail('c1') })
    expect(success).toHaveBeenCalledWith('Content deleted successfully')
  })

  it('drops the deleted entry version history too', async () => {
    // The history key is a sibling of the detail key, so removing the detail
    // alone would leave a re-created id reading the dead entry's history.
    contents.delete.mockResolvedValue(undefined)
    const seeded = queryKeys.contentVersions(SPACE, 'c1').list({})

    harness = withSetup(mountContent, { seed: [[seeded, [{ id: 'v1' }]]] })

    await harness.result.remove.mutateAsync('c1')

    expect(harness.queryClient.getQueryData(seeded)).toBeUndefined()
  })

  it('reports failure', async () => {
    contents.delete.mockRejectedValue(new Error('in use'))

    await expect(mutations().remove.mutateAsync('c1')).rejects.toThrow('in use')
    expect(failure).toHaveBeenCalledWith('Failed to delete content: in use')
  })
})

describe('useBulkCreateContentMutation', () => {
  it('invalidates the lists and the menu without a success toast', async () => {
    contents.bulkCreate.mockResolvedValue({ data: [content()] })
    const { bulkCreate } = mutations()
    const invalidate = spyInvalidate()

    await bulkCreate.mutateAsync({ items: [{ name: 'A', slug: 'a', block_id: 'b1' }] })

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    // The wizard reports its own outcome, so the composable stays quiet.
    expect(success).not.toHaveBeenCalled()
  })

  it('forwards the items envelope verbatim', async () => {
    contents.bulkCreate.mockResolvedValue({ data: [] })
    const payload = { items: [{ name: 'A', slug: 'a', block_id: 'b1', temp_id: 't1' }] }

    await mutations().bulkCreate.mutateAsync(payload)

    expect(contents.bulkCreate).toHaveBeenCalledWith(payload)
  })

  it('reports failure', async () => {
    contents.bulkCreate.mockRejectedValue(new Error('nope'))

    await expect(mutations().bulkCreate.mutateAsync({ items: [] })).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to create content items: nope')
  })
})

describe('useMoveContentMutation', () => {
  it('invalidates the lists, the menu and the moved family without a toast', async () => {
    contents.move.mockResolvedValue({ data: content() })
    const { move } = mutations()
    const invalidate = spyInvalidate()

    await move.mutateAsync({ id: 'c1', payload: { parent_id: 'p1', position: 2 } })

    expect(contents.move).toHaveBeenCalledWith('c1', { parent_id: 'p1', position: 2 })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contentMenu(SPACE).all(),
      queryKeys.contents(SPACE).detail('c1'),
      queryKeys.contentVersions(SPACE, 'c1').lists(),
    ])
    expect(success).not.toHaveBeenCalled()
  })

  it('reports failure', async () => {
    contents.move.mockRejectedValue(new Error('cycle'))

    await expect(mutations().move.mutateAsync({ id: 'c1', payload: {} })).rejects.toThrow('cycle')
    expect(failure).toHaveBeenCalledWith('Failed to move content: cycle')
  })
})

describe('useTreeOperationsMutation', () => {
  it('invalidates the lists, the details and the menu', async () => {
    contents.treeOperations.mockResolvedValue({ data: [] })
    const { tree } = mutations()
    const invalidate = spyInvalidate()

    await tree.mutateAsync({ operations: [] })

    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contents(SPACE).details(),
      queryKeys.contentMenu(SPACE).all(),
    ])
  })

  it('refreshes every touched entry detail', async () => {
    // A batch reparents arbitrary entries, so the details prefix — which
    // covers the seeded entry — has to go with the lists.
    contents.treeOperations.mockResolvedValue({ data: [] })
    const seeded = queryKeys.contents(SPACE).detail('c1')

    harness = withSetup(mountContent, { seed: [[seeded, content({ id: 'c1' })]] })
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.tree.mutateAsync({
      operations: [{ type: 'move', id: 'c1', parent_id: 'p1' }] as never,
    })

    expect(invalidatedKeys(invalidate)).toContainEqual(queryKeys.contents(SPACE).details())
    expect(harness.queryClient.getQueryState(seeded)?.isInvalidated).toBe(true)
  })

  it('reuses the move error copy', async () => {
    contents.treeOperations.mockRejectedValue(new Error('nope'))

    await expect(mutations().tree.mutateAsync({ operations: [] })).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to move content: nope')
  })
})

describe('translation import and export', () => {
  it('exports without touching the cache or toasting', async () => {
    contents.exportTranslations.mockResolvedValue('csv-body')
    const { exportTranslations } = mutations()
    const invalidate = spyInvalidate()

    const result = await exportTranslations.mutateAsync({ as: 'csv', languages: ['de'] })

    expect(result).toBe('csv-body')
    expect(contents.exportTranslations).toHaveBeenCalledWith({ as: 'csv', languages: ['de'] })
    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
  })

  it('reports an export failure', async () => {
    contents.exportTranslations.mockRejectedValue(new Error('nope'))

    await expect(mutations().exportTranslations.mutateAsync({ as: 'csv' })).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to export content translations: nope')
  })

  it('splits the import variables into a file and an options object', async () => {
    contents.importTranslations.mockResolvedValue({ imported: 3 })
    const file = new File(['a'], 'a.csv')
    const { importTranslations } = mutations()
    const invalidate = spyInvalidate()

    await importTranslations.mutateAsync({ file, mode: 'draft', createMissing: true })

    expect(contents.importTranslations).toHaveBeenCalledWith(file, {
      mode: 'draft',
      createMissing: true,
    })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contents(SPACE).lists(),
      queryKeys.contents(SPACE).details(),
      queryKeys.contentMenu(SPACE).all(),
    ])
    expect(success).toHaveBeenCalledWith('Content translations imported successfully')
  })

  it('refreshes every imported entry detail', async () => {
    // An import rewrites entry content, so an already-open entry must refetch
    // instead of serving pre-import values.
    contents.importTranslations.mockResolvedValue({})
    const seeded = queryKeys.contents(SPACE).detail('c1')

    harness = withSetup(mountContent, { seed: [[seeded, content()]] })
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.importTranslations.mutateAsync({
      file: new File(['a'], 'a.csv'),
      mode: 'draft',
      createMissing: false,
    })

    expect(invalidatedKeys(invalidate)).toContainEqual(queryKeys.contents(SPACE).details())
    expect(harness.queryClient.getQueryState(seeded)?.isInvalidated).toBe(true)
  })

  it('reports an import failure', async () => {
    contents.importTranslations.mockRejectedValue(new Error('bad csv'))

    await expect(
      mutations().importTranslations.mutateAsync({
        file: new File(['a'], 'a.csv'),
        mode: 'draft',
        createMissing: false,
      })
    ).rejects.toThrow('bad csv')
    expect(failure).toHaveBeenCalledWith('Failed to import content translations: bad csv')
  })
})
