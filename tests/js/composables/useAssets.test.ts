import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const assets = {
  index: vi.fn(),
  get: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
  replaceFile: vi.fn(),
  uploadPoster: vi.fn(),
  removePoster: vi.fn(),
  export: vi.fn(),
  importData: vi.fn(),
  getLinkedContents: vi.fn(),
}
const assetCollections = { getAssets: vi.fn() }
const ensureCsrfCookie = vi.fn(async () => true)

const success = vi.fn()
const failure = vi.fn()

vi.mock('~/api', () => ({
  api: {
    client: { ensureCsrfCookie },
    forSpace: () => ({ assets, assetCollections }),
  },
}))
vi.mock('vue-sonner', () => ({ toast: { success, error: failure } }))

const { useAssets } = await import('~/composables/useAssets')

const SPACE = 'space-1'

const asset = (id = 'a1') => ({ id, filename: `${id}.png` }) as unknown as AssetResource

let harness: Harness<ReturnType<typeof mountAssets>> | undefined

const mountAssets = () => {
  const composable = useAssets(SPACE)

  return {
    ...composable,
    update: composable.useUpdateAssetMutation(),
    remove: composable.useDeleteAssetMutation(),
    replace: composable.useReplaceAssetFileMutation(),
    poster: composable.useUploadAssetPosterMutation(),
    removePoster: composable.useRemoveAssetPosterMutation(),
    exportAssets: composable.useExportAssetsMutation(),
    importAssets: composable.useImportAssetsMutation(),
  }
}

const setup = () => {
  harness = withSetup(mountAssets)
  return harness
}

const mutations = () => setup().result

const spyInvalidate = () => vi.spyOn((harness as Harness<unknown>).queryClient, 'invalidateQueries')

const invalidatedKeys = (spy: ReturnType<typeof spyInvalidate>) =>
  spy.mock.calls.map(([filters]) => (typeof filters === 'function' ? filters() : filters)?.queryKey)

/**
 * A minimal XMLHttpRequest stand-in: jsdom's own implementation would try to
 * open a real connection. `flush` plays the server's answer back.
 */
class FakeXhr {
  static last: FakeXhr | undefined
  public status = 200
  public statusText = 'OK'
  public responseText = '{}'
  public withCredentials = false
  public sent: FormData | undefined
  public opened: [string, string] | undefined
  public headers: Record<string, string> = {}
  public upload = { addEventListener: vi.fn() }
  private listeners: Record<string, Array<() => void>> = {}

  constructor() {
    FakeXhr.last = this
  }

  addEventListener(event: string, handler: () => void) {
    ;(this.listeners[event] ??= []).push(handler)
  }

  open(method: string, url: string) {
    this.opened = [method, url]
  }

  setRequestHeader(key: string, value: string) {
    this.headers[key] = value
  }

  send(body: FormData) {
    this.sent = body
  }

  flush(event: string) {
    for (const handler of this.listeners[event] ?? []) handler()
  }
}

/**
 * uploadAsset awaits the CSRF cookie before it constructs the request, so the
 * fake is only reachable a microtask later.
 */
const nextXhr = async () => {
  await vi.waitFor(() => expect(FakeXhr.last).toBeDefined())
  return FakeXhr.last as FakeXhr
}

const uploadWith = async (
  payload: Parameters<ReturnType<typeof useAssets>['uploadAsset']>[0],
  respond: (xhr: FakeXhr) => void,
  options: { force?: boolean } = {},
  onProgress?: (progress: number) => void
) => {
  const promise = mutations().uploadAsset(payload, onProgress, options)
  const xhr = await nextXhr()

  respond(xhr)
  xhr.flush('load')

  return { outcome: await promise, xhr }
}

const fields = (form: FormData | undefined) =>
  Object.fromEntries(((form ?? new FormData()) as FormData).entries())

beforeEach(() => {
  for (const fn of Object.values(assets)) fn.mockReset()
  assetCollections.getAssets.mockReset()
  ensureCsrfCookie.mockClear()
  success.mockReset()
  failure.mockReset()
  FakeXhr.last = undefined
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
  document.cookie = 'XSRF-TOKEN=tok%3D1'
  // uploadAsset invalidates and toasts behind a 300ms debounce. On the real
  // clock that timer outlives its test and fires during the next one, so the
  // whole file runs on a fake clock that is dropped in afterEach.
  vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
  vi.useRealTimers()
  harness?.unmount()
  harness = undefined
  vi.unstubAllGlobals()
})

describe('useAssetsQuery', () => {
  it('sends a default ascending sort that is not part of the key', async () => {
    assets.index.mockResolvedValue({ data: [asset()] })

    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetsQuery())

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.assets(SPACE).list({}))).toEqual({
        data: [asset()],
      })
    )
    // The sort reaches the API but never the key, so `{}` and
    // `{ sort: '+created_at' }` are two cache entries for one request.
    expect(assets.index).toHaveBeenCalledWith({ sort: '+created_at' })
    queryClient.clear()
  })

  it('lets the caller override the sort', async () => {
    assets.index.mockResolvedValue({ data: [] })

    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetsQuery({ sort: '-filename' }))

    await vi.waitFor(() => expect(assets.index).toHaveBeenCalledWith({ sort: '-filename' }))
    queryClient.clear()
  })

  it('routes a collection filter to the collection endpoint, stripping the filter', async () => {
    assetCollections.getAssets.mockResolvedValue({ data: [asset()] })

    const { queryClient } = withSetup(() =>
      useAssets(SPACE).useAssetsQuery({ collection: 'col-1', page: 2 })
    )

    await vi.waitFor(() =>
      expect(assetCollections.getAssets).toHaveBeenCalledWith('col-1', { page: 2 })
    )
    // Served by the collection endpoint, so cached in the collection namespace:
    // that is what a collection mutation invalidates, and an asset-list
    // invalidation leaves it alone.
    await vi.waitFor(() =>
      expect(
        queryClient.getQueryData(queryKeys.assetCollections(SPACE).assetsList('col-1', { page: 2 }))
      ).toEqual({ data: [asset()] })
    )
    expect(
      queryClient.getQueryData(queryKeys.assets(SPACE).list({ collection: 'col-1', page: 2 }))
    ).toBeUndefined()
    expect(assets.index).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useAssetQuery', () => {
  it('accepts an un-enveloped show response', async () => {
    assets.get.mockResolvedValue({ id: 'a1', filename: 'a1.png' })

    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetQuery('a1'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.assets(SPACE).detail('a1'))).toMatchObject({
        id: 'a1',
      })
    )
    queryClient.clear()
  })

  it('falls back to the data envelope when there is no id at the top level', async () => {
    assets.get.mockResolvedValue({ data: { id: 'a1' } })

    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetQuery('a1'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.assets(SPACE).detail('a1'))).toEqual({ id: 'a1' })
    )
    queryClient.clear()
  })

  it('stays disabled for an empty id', () => {
    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetQuery(''))

    expect(assets.get).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled while the caller says so', () => {
    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetQuery('a1', false))

    expect(assets.get).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useAssetLinkedContentsQuery', () => {
  it('keys per page and defaults to ten per page', async () => {
    assets.getLinkedContents.mockResolvedValue({ data: [], meta: { total: 0 } })

    const { queryClient } = withSetup(() =>
      useAssets(SPACE).useAssetLinkedContentsQuery('a1', 2)
    )

    await vi.waitFor(() =>
      expect(
        queryClient.getQueryData(queryKeys.assets(SPACE).linkedContentsPage('a1', 2))
      ).toBeDefined()
    )
    expect(assets.getLinkedContents).toHaveBeenCalledWith('a1', { page: 2, per_page: 10 })
    queryClient.clear()
  })

  it('honours a custom page size', async () => {
    assets.getLinkedContents.mockResolvedValue({ data: [] })

    const { queryClient } = withSetup(() =>
      useAssets(SPACE).useAssetLinkedContentsQuery('a1', 1, true, 50)
    )

    await vi.waitFor(() =>
      expect(assets.getLinkedContents).toHaveBeenCalledWith('a1', { page: 1, per_page: 50 })
    )
    queryClient.clear()
  })

  it('stays disabled without an id', () => {
    const { queryClient } = withSetup(() => useAssets(SPACE).useAssetLinkedContentsQuery(null, 1))

    expect(assets.getLinkedContents).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useUpdateAssetMutation', () => {
  it('invalidates the lists and the updated asset detail', async () => {
    assets.update.mockResolvedValue({ data: asset() })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'a1', payload: { folder_id: 'f1' } })

    expect(assets.update).toHaveBeenCalledWith('a1', { folder_id: 'f1' })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.assets(SPACE).lists(),
      queryKeys.assets(SPACE).detail('a1'),
    ])
    expect(success).toHaveBeenCalledWith('Asset updated successfully')
  })

  it('keys the invalidation off the response id, not the requested one', async () => {
    // A rename can return a different id than the caller sent; the cache
    // follows the response, so the requested key would stay stale.
    assets.update.mockResolvedValue({ data: asset('server-id') })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'a1', payload: {} })

    expect(invalidatedKeys(invalidate)).toContainEqual(queryKeys.assets(SPACE).detail('server-id'))
    expect(invalidatedKeys(invalidate)).not.toContainEqual(queryKeys.assets(SPACE).detail('a1'))
  })

  it('reports failure', async () => {
    assets.update.mockRejectedValue(new Error('too large'))

    await expect(mutations().update.mutateAsync({ id: 'a1', payload: {} })).rejects.toThrow(
      'too large'
    )
    expect(failure).toHaveBeenCalledWith('Failed to update asset: too large')
  })
})

describe('useDeleteAssetMutation', () => {
  it('drops the detail and the linked-contents pages', async () => {
    assets.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync({ id: 'a1' })

    expect(assets.delete).toHaveBeenCalledWith('a1', { force: false })
    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.assets(SPACE).lists()])
    // One call: linkedContents is a subtree of the detail key, so removing the
    // detail already takes the pages with it.
    expect(removeQueries).toHaveBeenCalledTimes(1)
    expect(removeQueries).toHaveBeenCalledWith({
      queryKey: queryKeys.assets(SPACE).detail('a1'),
    })
    expect(success).toHaveBeenCalledWith('Asset deleted successfully')
  })

  it('really evicts the seeded caches rather than only invalidating them', async () => {
    assets.delete.mockResolvedValue(undefined)
    const detail = queryKeys.assets(SPACE).detail('a1')
    const linked = queryKeys.assets(SPACE).linkedContentsPage('a1', 1)

    harness = withSetup(mountAssets, {
      seed: [
        [detail, asset()],
        [linked, { data: [] }],
      ],
    })

    await harness.result.remove.mutateAsync({ id: 'a1' })

    expect(harness.queryClient.getQueryData(detail)).toBeUndefined()
    expect(harness.queryClient.getQueryData(linked)).toBeUndefined()
  })

  it('passes the force flag through', async () => {
    assets.delete.mockResolvedValue(undefined)

    await mutations().remove.mutateAsync({ id: 'a1', force: true })

    expect(assets.delete).toHaveBeenCalledWith('a1', { force: true })
  })

  it('stays silent on an in-use conflict, which the caller turns into a dialog', async () => {
    const conflict = Object.assign(new Error('in use'), {
      status: 409,
      data: { code: 'asset_in_use' },
    })
    assets.delete.mockRejectedValue(conflict)

    await expect(mutations().remove.mutateAsync({ id: 'a1' })).rejects.toThrow('in use')
    expect(failure).not.toHaveBeenCalled()
  })

  it('reports a 409 with any other code', async () => {
    assets.delete.mockRejectedValue(
      Object.assign(new Error('nope'), { status: 409, data: { code: 'other' } })
    )

    await expect(mutations().remove.mutateAsync({ id: 'a1' })).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to delete asset: nope')
  })
})

describe('useReplaceAssetFileMutation', () => {
  it('invalidates the lists and the replaced asset', async () => {
    assets.replaceFile.mockResolvedValue(asset())
    const { replace } = mutations()
    const invalidate = spyInvalidate()
    const file = new File(['x'], 'x.png')
    const onProgress = vi.fn()

    await replace.mutateAsync({ id: 'a1', file, onProgress })

    expect(assets.replaceFile).toHaveBeenCalledWith('a1', file, onProgress)
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.assets(SPACE).lists(),
      queryKeys.assets(SPACE).detail('a1'),
    ])
    expect(success).toHaveBeenCalledWith('Asset file replaced successfully')
  })

  it('reports a failure when the API resolves with nothing', async () => {
    // An empty response is not a success worth announcing, and the UI must not
    // be left reporting neither outcome.
    assets.replaceFile.mockResolvedValue(null)
    const { replace } = mutations()
    const invalidate = spyInvalidate()

    await replace.mutateAsync({ id: 'a1', file: new File(['x'], 'x.png') })

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
    expect(failure).toHaveBeenCalledWith('Failed to replace asset file: Unknown error')
  })

  it('reports failure', async () => {
    assets.replaceFile.mockRejectedValue(new Error('mime mismatch'))

    await expect(
      mutations().replace.mutateAsync({ id: 'a1', file: new File(['x'], 'x.png') })
    ).rejects.toThrow('mime mismatch')
    expect(failure).toHaveBeenCalledWith('Failed to replace asset file: mime mismatch')
  })
})

describe('useUploadAssetPosterMutation', () => {
  it('reads the id out of the response envelope', async () => {
    assets.uploadPoster.mockResolvedValue({ data: asset('a2') })
    const { poster } = mutations()
    const invalidate = spyInvalidate()
    const file = new File(['x'], 'poster.jpg')

    await poster.mutateAsync({ id: 'a2', file })

    expect(assets.uploadPoster).toHaveBeenCalledWith('a2', file)
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.assets(SPACE).lists(),
      queryKeys.assets(SPACE).detail('a2'),
    ])
    expect(success).toHaveBeenCalledWith('Poster updated successfully')
  })

  it('reports a failure for an empty envelope', async () => {
    assets.uploadPoster.mockResolvedValue({})
    const { poster } = mutations()
    const invalidate = spyInvalidate()

    await poster.mutateAsync({ id: 'a2', file: new File(['x'], 'p.jpg') })

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
    expect(failure).toHaveBeenCalledWith('Failed to upload poster: Unknown error')
  })

  it('reports failure', async () => {
    assets.uploadPoster.mockRejectedValue(new Error('not a video'))

    await expect(
      mutations().poster.mutateAsync({ id: 'a2', file: new File(['x'], 'p.jpg') })
    ).rejects.toThrow('not a video')
    expect(failure).toHaveBeenCalledWith('Failed to upload poster: not a video')
  })
})

describe('useRemoveAssetPosterMutation', () => {
  it('reads the id out of the response envelope', async () => {
    assets.removePoster.mockResolvedValue({ data: asset('a2') })
    const { removePoster } = mutations()
    const invalidate = spyInvalidate()

    await removePoster.mutateAsync('a2')

    expect(assets.removePoster).toHaveBeenCalledWith('a2')
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.assets(SPACE).lists(),
      queryKeys.assets(SPACE).detail('a2'),
    ])
    expect(success).toHaveBeenCalledWith('Poster removed')
  })

  it('reports a failure for an empty envelope', async () => {
    assets.removePoster.mockResolvedValue({})
    const { removePoster } = mutations()
    const invalidate = spyInvalidate()

    await removePoster.mutateAsync('a2')

    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
    expect(failure).toHaveBeenCalledWith('Failed to remove poster: Unknown error')
  })

  it('reports failure', async () => {
    assets.removePoster.mockRejectedValue(new Error('no custom poster'))

    await expect(mutations().removePoster.mutateAsync('a2')).rejects.toThrow('no custom poster')
    expect(failure).toHaveBeenCalledWith('Failed to remove poster: no custom poster')
  })
})

describe('export and import', () => {
  it('exports without touching the cache', async () => {
    const blob = new Blob(['csv'])
    assets.export.mockResolvedValue(blob)
    const { exportAssets } = mutations()
    const invalidate = spyInvalidate()

    expect(await exportAssets.mutateAsync({ as: 'csv' } as never)).toBe(blob)
    expect(invalidate).not.toHaveBeenCalled()
    expect(success).not.toHaveBeenCalled()
  })

  it('reports an export failure', async () => {
    assets.export.mockRejectedValue(new Error('nope'))

    await expect(mutations().exportAssets.mutateAsync({ as: 'csv' } as never)).rejects.toThrow(
      'nope'
    )
    expect(failure).toHaveBeenCalledWith('Failed to export assets: nope')
  })

  it('invalidates only the lists after an import', async () => {
    assets.importData.mockResolvedValue({ created: 2 })
    const file = new File(['x'], 'x.json')
    const { importAssets } = mutations()
    const invalidate = spyInvalidate()

    await importAssets.mutateAsync(file)

    expect(assets.importData).toHaveBeenCalledWith(file)
    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.assets(SPACE).lists()])
    expect(success).toHaveBeenCalledWith('Assets imported successfully')
  })

  it('reports an import failure', async () => {
    assets.importData.mockRejectedValue(new Error('bad json'))

    await expect(mutations().importAssets.mutateAsync(new File(['x'], 'x.json'))).rejects.toThrow(
      'bad json'
    )
    expect(failure).toHaveBeenCalledWith('Failed to import assets: bad json')
  })
})

describe('uploadAsset', () => {
  const ok = (body: Record<string, unknown>) => (xhr: FakeXhr) => {
    xhr.status = 201
    xhr.responseText = JSON.stringify(body)
  }

  it('posts the file to the space endpoint with credentials and the xsrf header', async () => {
    const file = new File(['x'], 'x.png')

    const { xhr } = await uploadWith({ file }, ok({ data: asset() }))

    expect(ensureCsrfCookie).toHaveBeenCalled()
    expect(xhr.opened).toEqual(['POST', `/mgmt/v1/spaces/${SPACE}/assets`])
    expect(xhr.withCredentials).toBe(true)
    expect(xhr.headers).toEqual({ accept: 'application/json', 'X-XSRF-TOKEN': 'tok=1' })
    expect(fields(xhr.sent)).toEqual({ file })
  })

  it('json-encodes tags, metadata and data, and sends folder_id raw', async () => {
    const { xhr } = await uploadWith(
      {
        file: new File(['x'], 'x.png'),
        folder_id: 'f1',
        tags: ['a', 'b'],
        metadata: { alt: 'cat' },
        data: { credit: 'me' },
      } as never,
      ok({ data: asset() })
    )

    expect(fields(xhr.sent)).toMatchObject({
      folder_id: 'f1',
      tags: '["a","b"]',
      metadata: '{"alt":"cat"}',
      data: '{"credit":"me"}',
    })
  })

  it('omits every optional field that is falsy', async () => {
    const { xhr } = await uploadWith(
      { file: new File(['x'], 'x.png'), folder_id: null, tags: [], metadata: null } as never,
      ok({ data: asset() })
    )

    // Pinned: an empty tag array is falsy-adjacent — `[]` is truthy, so it is
    // sent, while `folder_id: null` is dropped.
    expect(Object.keys(fields(xhr.sent)).sort()).toEqual(['file', 'tags'])
  })

  it('sends force as the string "1" only when asked', async () => {
    const { xhr } = await uploadWith(
      { file: new File(['x'], 'x.png') } as never,
      ok({ data: asset() }),
      { force: true }
    )

    expect(fields(xhr.sent)).toMatchObject({ force: '1' })
  })

  it('returns a success outcome carrying the created asset', async () => {
    const { outcome } = await uploadWith(
      { file: new File(['x'], 'x.png') } as never,
      ok({ data: asset('new') })
    )

    expect(outcome).toEqual({ status: 'success', asset: asset('new') })
  })

  it('invalidates the lists and toasts only once the debounce elapses', async () => {
    const upload = mutations().uploadAsset
    const invalidate = spyInvalidate()
    const promise = upload({ file: new File(['x'], 'x.png') } as never)
    const xhr = await nextXhr()

    ok({ data: asset() })(xhr)
    xhr.flush('load')
    await promise

    // The outcome resolves before the cache is refreshed: a caller that reads
    // the list right after awaiting uploadAsset still sees the old page.
    expect(invalidate).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(300)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.assets(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Assets uploaded successfully')
  })

  it('coalesces a multi-file drop into one invalidation and one toast', async () => {
    // The debounce is shared by the composable instance, so N files produce
    // one refresh, not N.
    const { uploadAsset: upload } = mutations()
    const invalidate = spyInvalidate()

    for (const name of ['a.png', 'b.png']) {
      const promise = upload({ file: new File(['x'], name) } as never)
      const xhr = await nextXhr()

      ok({ data: asset() })(xhr)
      xhr.flush('load')
      await promise
    }

    await vi.advanceTimersByTimeAsync(300)

    expect(success).toHaveBeenCalledTimes(1)
    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.assets(SPACE).lists()])
  })

  it('resolves null and skips the toast when the response carries no asset', async () => {
    const { outcome } = await uploadWith({ file: new File(['x'], 'x.png') } as never, ok({}))

    expect(outcome).toBeNull()
    expect(success).not.toHaveBeenCalled()
  })

  it('returns a duplicate outcome for a 409 checksum match', async () => {
    const { outcome } = await uploadWith({ file: new File(['x'], 'x.png') } as never, (xhr) => {
      xhr.status = 409
      xhr.responseText = JSON.stringify({
        code: 'duplicate_asset',
        message: 'already here',
        existing_asset: asset('old'),
      })
    })

    expect(outcome).toEqual({
      status: 'duplicate',
      duplicate: {
        code: 'duplicate_asset',
        message: 'already here',
        existing_asset: asset('old'),
      },
    })
  })

  it('resolves null on a rejection but records and reports it', async () => {
    const result = mutations()
    const promise = result.uploadAsset({ file: new File(['x'], 'x.png') } as never)
    const xhr = await nextXhr()

    xhr.status = 422
    xhr.responseText = JSON.stringify({ message: 'file too large' })
    xhr.flush('load')

    // uploadAsset never rejects — the outcome is `null` — but the failure is
    // still surfaced, like every mutation in the file does.
    expect(await promise).toBeNull()
    expect(result.error.value).toBe('file too large')
    expect(failure).toHaveBeenCalledWith('file too large')
  })

  it('reports a 409 that is not a duplicate as a plain failure', async () => {
    const result = mutations()
    const promise = result.uploadAsset({ file: new File(['x'], 'x.png') } as never)
    const xhr = await nextXhr()

    xhr.status = 409
    xhr.responseText = JSON.stringify({ code: 'something_else', message: 'nope' })
    xhr.flush('load')

    expect(await promise).toBeNull()
    expect(result.error.value).toBe('nope')
  })

  it('falls back to the status text when the body has no message', async () => {
    const result = mutations()
    const promise = result.uploadAsset({ file: new File(['x'], 'x.png') } as never)
    const xhr = await nextXhr()

    xhr.status = 500
    xhr.statusText = 'Server Error'
    xhr.responseText = '{}'
    xhr.flush('load')

    await promise
    expect(result.error.value).toBe('Upload failed with status 500: Server Error')
  })

  it('reports an unparsable body', async () => {
    const result = mutations()
    const promise = result.uploadAsset({ file: new File(['x'], 'x.png') } as never)
    const xhr = await nextXhr()

    xhr.responseText = '<html>502</html>'
    xhr.flush('load')

    await promise
    expect(result.error.value).toBe('Failed to parse server response')
  })

  it('reports a network error', async () => {
    const result = mutations()
    const promise = result.uploadAsset({ file: new File(['x'], 'x.png') } as never)

    ;(await nextXhr()).flush('error')

    expect(await promise).toBeNull()
    expect(result.error.value).toBe('Network error occurred during upload')
  })

  it('reports an abort', async () => {
    const result = mutations()
    const promise = result.uploadAsset({ file: new File(['x'], 'x.png') } as never)

    ;(await nextXhr()).flush('abort')

    expect(await promise).toBeNull()
    expect(result.error.value).toBe('Upload was aborted')
  })

  it('reports progress as a rounded percentage', async () => {
    const onProgress = vi.fn()
    const promise = mutations().uploadAsset(
      { file: new File(['x'], 'x.png') } as never,
      onProgress
    )
    const xhr = await nextXhr()
    const [[, handler]] = xhr.upload.addEventListener.mock.calls as [
      [string, (event: ProgressEvent) => void],
    ]

    handler({ lengthComputable: true, loaded: 1, total: 3 } as ProgressEvent)
    handler({ lengthComputable: false, loaded: 2, total: 3 } as ProgressEvent)

    expect(onProgress).toHaveBeenCalledTimes(1)
    expect(onProgress).toHaveBeenCalledWith(33)

    xhr.status = 201
    xhr.responseText = JSON.stringify({ data: asset() })
    xhr.flush('load')
    await promise
  })

  it('uploads without an xsrf cookie rather than refusing to try', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'

    const { xhr } = await uploadWith({ file: new File(['x'], 'x.png') } as never, ok({ data: asset() }))

    expect(xhr.headers).toEqual({ accept: 'application/json' })
  })
})
