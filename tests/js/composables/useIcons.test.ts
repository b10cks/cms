import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const tags = vi.fn()
const upload = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const importData = vi.fn()

const forSpace = vi.fn(() => ({
  icons: { index, get, tags, upload, update, delete: destroy, importData },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useIcons } = await import('~/composables/useIcons')

const SPACE = 'space-1'
const keys = queryKeys.icons(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const svgFile = (name = 'star.svg') =>
  new File(['<svg viewBox="0 0 24 24"></svg>'], name, { type: 'image/svg+xml' })

const mounted: Array<() => void> = []

/** Factories call useQuery/useMutation, so they must be built inside setup(). */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const inSpace = (spaceId: MaybeRef<string> = SPACE) => useIcons(spaceId)

beforeEach(() => {
  for (const fn of [index, get, tags, upload, update, destroy, importData, success, error]) {
    fn.mockReset()
  }
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
  tags.mockResolvedValue({ data: [] })
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
})

describe('useIconsQuery', () => {
  it('sorts by key ascending by default', async () => {
    mount(() => inSpace().useIconsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+key' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('lets caller params override the default sort', async () => {
    mount(() => inSpace().useIconsQuery({ sort: '-created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('forwards a tag filter alongside the sort', async () => {
    mount(() => inSpace().useIconsQuery({ tags: ['ui', 'brand'] }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+key', tags: ['ui', 'brand'] })
  })

  it('keeps the whole paginated envelope, not just data', async () => {
    index.mockResolvedValue({ data: [{ id: 'i1' }], meta: { total: 1 } })

    const query = mount(() => inSpace().useIconsQuery()).result
    await flush()

    expect(query.data.value).toEqual({ data: [{ id: 'i1' }], meta: { total: 1 } })
  })

  it('caches under the filter-scoped list key', async () => {
    const harness = mount(() => inSpace().useIconsQuery({ q: 'star' }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ q: 'star' }))).toBeDefined()
  })

  it('keeps the previous page visible while the next one loads', async () => {
    const params = ref({ page: 1 })
    index.mockResolvedValue({ data: [{ id: 'i1' }] })
    const harness = mount(() => inSpace().useIconsQuery(params))
    await flush()

    let release = () => {}
    index.mockImplementation(() => new Promise((resolve) => (release = () => resolve({ data: [] }))))
    params.value = { page: 2 }
    await nextTick()

    expect(harness.result.data.value).toEqual({ data: [{ id: 'i1' }] })
    expect(harness.result.isPlaceholderData.value).toBe(true)

    release()
    await flush()
  })

  it('stays idle for an empty space id rather than requesting /spaces//icons', async () => {
    mount(() => inSpace('').useIconsQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()
  })
})

describe('useIconQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: { id: 'i1', key: 'star' } })

    const query = mount(() => inSpace().useIconQuery('i1')).result
    await flush()

    expect(get).toHaveBeenCalledWith('i1')
    expect(query.data.value).toEqual({ id: 'i1', key: 'star' })
  })

  it('fetches even for an empty id — there is no enabled guard', async () => {
    get.mockResolvedValue({ data: null })

    mount(() => inSpace().useIconQuery(''))
    await flush()

    expect(get).toHaveBeenCalledWith('')
  })
})

describe('useIconTagsQuery', () => {
  it('unwraps the tag list', async () => {
    tags.mockResolvedValue({ data: ['ui', 'brand'] })

    const query = mount(() => inSpace().useIconTagsQuery()).result
    await flush()

    expect(query.data.value).toEqual(['ui', 'brand'])
  })

  it('caches under a tags key that list invalidation does not reach', async () => {
    const harness = mount(() => inSpace().useIconTagsQuery())
    await flush()

    expect(harness.queryClient.getQueryData(keys.tags())).toBeDefined()
    expect(keys.tags().slice(0, keys.lists().length)).not.toEqual([...keys.lists()])
  })
})

describe('uploadIcon', () => {
  it('returns the created icon and refreshes both the lists and the tags', async () => {
    upload.mockResolvedValue({ data: { id: 'i1', key: 'star' } })
    const harness = mount(() => inSpace())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.uploadIcon({ file: svgFile(), key: 'star' })

    expect(result).toEqual({ id: 'i1', key: 'star' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.tags() })
    expect(invalidate).toHaveBeenCalledTimes(2)
  })

  it('passes the progress callback down to the transport', async () => {
    upload.mockImplementation(async (_payload, onProgress?: (value: number) => void) => {
      onProgress?.(50)
      onProgress?.(100)
      return { data: { id: 'i1' } }
    })
    const progress: number[] = []

    await mount(() => inSpace()).result.uploadIcon({ file: svgFile() }, (value) =>
      progress.push(value)
    )

    expect(progress).toEqual([50, 100])
  })

  it('shows no toast on success — the caller owns the feedback', async () => {
    upload.mockResolvedValue({ data: { id: 'i1' } })

    await mount(() => inSpace()).result.uploadIcon({ file: svgFile() })

    expect(success).not.toHaveBeenCalled()
  })

  it('propagates a failure without a toast and without invalidating', async () => {
    upload.mockRejectedValue(new Error('not an svg'))
    const harness = mount(() => inSpace())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await expect(harness.result.uploadIcon({ file: svgFile() })).rejects.toThrow('not an svg')

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).not.toHaveBeenCalled()
  })
})

describe('useUpdateIconMutation', () => {
  it('refreshes lists, tags and that icon detail', async () => {
    update.mockResolvedValue({ data: { id: 'i1', name: 'Star' } })
    const harness = mount(() => inSpace().useUpdateIconMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'i1', payload: { name: 'Star' } })

    expect(update).toHaveBeenCalledWith('i1', { name: 'Star' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.tags() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('i1') })
    expect(success).toHaveBeenCalledWith('Icon "Star" updated')
  })

  it('keys the detail invalidation off the response, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'Star' } })
    const harness = mount(() => inSpace().useUpdateIconMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync({ id: 'i1', payload: {} })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: keys.detail('i1') })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('key taken'))
    const harness = mount(() => inSpace().useUpdateIconMutation())

    await expect(harness.result.mutateAsync({ id: 'i1', payload: {} })).rejects.toThrow('key taken')
    expect(error).toHaveBeenCalledWith('Failed to update icon: key taken')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    update.mockRejectedValue(new Error(''))
    const harness = mount(() => inSpace().useUpdateIconMutation())

    await harness.result.mutateAsync({ id: 'i1', payload: {} }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update icon: Unknown error')
  })
})

describe('useDeleteIconMutation', () => {
  it('refreshes lists and tags and drops the detail cache', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteIconMutation(), [
      [keys.detail('i1'), { id: 'i1' }],
    ])
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('i1')

    expect(destroy).toHaveBeenCalledWith('i1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.tags() })
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.detail('i1') })
    expect(harness.queryClient.getQueryData(keys.detail('i1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Icon deleted')
  })

  it('leaves content lists alone, so an icon field still points at the deleted key', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteIconMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('i1')

    expect(invalidate).toHaveBeenCalledTimes(2)
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('in use'))
    const harness = mount(() => inSpace().useDeleteIconMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('i1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete icon: in use')
  })
})

describe('useImportIconsMutation', () => {
  it('forwards the file and the import mode', async () => {
    importData.mockResolvedValue({ created: 2, updated: 0 })
    const file = svgFile('icons.json')
    const harness = mount(() => inSpace().useImportIconsMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync({ file, mode: 'replacement' })

    expect(importData).toHaveBeenCalledWith(file, 'replacement')
    expect(result).toEqual({ created: 2, updated: 0 })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.tags() })
    expect(success).toHaveBeenCalledWith('Icons imported successfully')
  })

  it('reports a partial import as a plain success, leaving the counts to the caller', async () => {
    importData.mockResolvedValue({ created: 0, updated: 0, errors: ['bad key'] })
    const harness = mount(() => inSpace().useImportIconsMutation())

    await harness.result.mutateAsync({ file: svgFile('icons.json'), mode: 'addition' })

    expect(success).toHaveBeenCalledWith('Icons imported successfully')
    expect(error).not.toHaveBeenCalled()
  })

  it('reports the failure reason', async () => {
    importData.mockRejectedValue(new Error('malformed json'))
    const harness = mount(() => inSpace().useImportIconsMutation())

    await harness.result
      .mutateAsync({ file: svgFile('icons.json'), mode: 'addition' })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to import icons: malformed json')
  })
})

describe('query key shape', () => {
  it('scopes every key to the space', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'icons'])
    expect(queryKeys.icons('a').lists()).not.toEqual(queryKeys.icons('b').lists())
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ q: 'x' })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })

  it('invalidates only the current space', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace('space-2').useDeleteIconMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('i1')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.icons('space-2').lists() })
  })
})
