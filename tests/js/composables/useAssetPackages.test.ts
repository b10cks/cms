import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AssetPackageResource } from '~/types/asset-distribution'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const create = vi.fn()
const destroy = vi.fn()
const download = vi.fn()

const forSpace = vi.fn(() => ({
  assetPackages: { index, get, create, delete: destroy, download },
}))

vi.mock('~/api', () => ({ api: { forSpace } }))

const success = vi.fn()
const error = vi.fn()
const loading = vi.fn(() => 'toast-1')
vi.mock('vue-sonner', () => ({ toast: { success, error, loading } }))

const { useAssetPackages } = await import('~/composables/useAssetPackages')

const SPACE = 'space-1'
const keys = queryKeys.assetPackages(SPACE)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/** `state` is deliberately a plain string so a state outside the union can be pinned. */
const pkg = (state: string, extra: Record<string, unknown> = {}) =>
  ({ id: 'p1', state, progress: 0, ...extra }) as unknown as AssetPackageResource

const mounted: Array<() => void> = []

/** Factories call useQuery/useMutation, so they must be built inside setup(). */
const mount = <T>(build: () => T, seed?: Array<[readonly unknown[], unknown]>): Harness<T> => {
  const harness = withSetup<T>(build, { seed })
  mounted.push(harness.unmount)
  return harness
}

const inSpace = (spaceId: MaybeRef<string> = SPACE) => useAssetPackages(spaceId)

/** Reads the refetchInterval the query registered, so polling is tested for real. */
const pollFor = (harness: Harness<unknown>, queryKey: readonly unknown[]) => {
  const query = harness.queryClient.getQueryCache().find({ queryKey })
  const options = (query?.options ?? {}) as {
    refetchInterval?: (q: unknown) => number | false
  }
  return options.refetchInterval as (q: unknown) => number | false
}

beforeEach(() => {
  for (const fn of [index, get, create, destroy, download, success, error, loading]) fn.mockReset()
  forSpace.mockClear()
  index.mockResolvedValue({ data: [] })
  loading.mockReturnValue('toast-1')
})

afterEach(() => {
  while (mounted.length) mounted.pop()?.()
  vi.useRealTimers()
})

describe('useAssetPackagesQuery', () => {
  it('passes the caller params straight through — no default sort', async () => {
    mount(() => inSpace().useAssetPackagesQuery({ state: 'completed' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ state: 'completed' })
    expect(forSpace).toHaveBeenCalledWith(SPACE)
  })

  it('caches under the filter-scoped list key', async () => {
    const harness = mount(() => inSpace().useAssetPackagesQuery({ page: 2 }))
    await flush()

    expect(harness.queryClient.getQueryData(keys.list({ page: 2 }))).toBeDefined()
  })

  it('stays idle while the space id is empty', async () => {
    const query = mount(() => inSpace('').useAssetPackagesQuery()).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
    expect(index).not.toHaveBeenCalled()
  })

  it('stays idle when explicitly disabled', async () => {
    const query = mount(() => inSpace().useAssetPackagesQuery({}, false)).result
    await flush()

    expect(query.fetchStatus.value).toBe('idle')
  })

  it.each([['pending'], ['building']] as const)('polls every 2.5s while one package is %s', async (state) => {
    const harness = mount(() => inSpace().useAssetPackagesQuery())
    await flush()

    const interval = pollFor(harness, keys.list({}))

    expect(interval({ state: { data: { data: [pkg(state)] } } })).toBe(2500)
  })

  it.each([['completed'], ['failed']] as const)(
    'stops polling once every package is %s',
    async (state) => {
      const harness = mount(() => inSpace().useAssetPackagesQuery())
      await flush()

      const interval = pollFor(harness, keys.list({}))

      expect(interval({ state: { data: { data: [pkg(state)] } } })).toBe(false)
    }
  )

  it('keeps polling while a single package of a mixed page is still building', async () => {
    const harness = mount(() => inSpace().useAssetPackagesQuery())
    await flush()

    const interval = pollFor(harness, keys.list({}))

    expect(interval({ state: { data: { data: [pkg('completed'), pkg('building')] } } })).toBe(2500)
  })

  it.each([
    ['an empty page', { data: [] }],
    ['no data at all', undefined],
  ])('does not poll for %s', async (_label, data) => {
    const harness = mount(() => inSpace().useAssetPackagesQuery())
    await flush()

    const interval = pollFor(harness, keys.list({}))

    expect(interval({ state: { data } })).toBe(false)
  })
})

describe('useCreateAssetPackageMutation', () => {
  it('invalidates the lists and shows no success toast', async () => {
    create.mockResolvedValue({ data: { id: 'p1', state: 'pending' } })
    const harness = mount(() => inSpace().useCreateAssetPackageMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const result = await harness.result.mutateAsync({
      source_type: 'selection',
      asset_ids: ['a1'],
    })

    expect(create).toHaveBeenCalledWith({ source_type: 'selection', asset_ids: ['a1'] })
    expect(result).toEqual({ id: 'p1', state: 'pending' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(success).not.toHaveBeenCalled()
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('too many assets'))
    const harness = mount(() => inSpace().useCreateAssetPackageMutation())

    await expect(
      harness.result.mutateAsync({ source_type: 'selection', asset_ids: [] })
    ).rejects.toThrow('too many assets')
    expect(error).toHaveBeenCalledWith('Failed to create download package: too many assets')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue(new Error(''))
    const harness = mount(() => inSpace().useCreateAssetPackageMutation())

    await harness.result.mutateAsync({ source_type: 'selection', asset_ids: [] }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create download package: Unknown error')
  })
})

describe('useDeleteAssetPackageMutation', () => {
  it('invalidates the lists and drops the detail cache', async () => {
    destroy.mockResolvedValue(undefined)
    const harness = mount(() => inSpace().useDeleteAssetPackageMutation(), [
      [keys.detail('p1'), { id: 'p1' }],
    ])
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')
    const remove = vi.spyOn(harness.queryClient, 'removeQueries')

    await harness.result.mutateAsync('p1')

    expect(destroy).toHaveBeenCalledWith('p1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(remove).toHaveBeenCalledWith({ queryKey: keys.detail('p1') })
    expect(harness.queryClient.getQueryData(keys.detail('p1'))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Download package deleted successfully')
  })

  it('does not invalidate when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('gone'))
    const harness = mount(() => inSpace().useDeleteAssetPackageMutation())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.mutateAsync('p1').catch(() => {})

    expect(invalidate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith('Failed to delete download package: gone')
  })
})

describe('downloadPackage', () => {
  it('navigates to the signed URL via a throwaway link', async () => {
    download.mockResolvedValue({ url: 'https://cdn.test/p1.zip?sig=abc' })
    const clicked: Array<{ href: string; rel: string; attached: boolean }> = []
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        clicked.push({
          href: this.href,
          rel: this.rel,
          // The link must be in the document at click time for Firefox.
          attached: document.body.contains(this),
        })
      })

    await mount(() => inSpace()).result.downloadPackage('p1')

    expect(download).toHaveBeenCalledWith('p1')
    expect(clicked).toEqual([
      { href: 'https://cdn.test/p1.zip?sig=abc', rel: 'noopener', attached: true },
    ])
    // Nothing is left behind in the DOM.
    expect(document.querySelector('a')).toBeNull()
    click.mockRestore()
  })

  it('propagates a failure to fetch the signed URL', async () => {
    download.mockRejectedValue(new Error('expired'))

    await expect(mount(() => inSpace()).result.downloadPackage('p1')).rejects.toThrow('expired')
  })
})

describe('waitForPackageAndDownload', () => {
  it('downloads immediately when the package is already complete', async () => {
    get.mockResolvedValue({ data: pkg('completed') })
    download.mockResolvedValue({ url: 'https://cdn.test/p1.zip' })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const harness = mount(() => inSpace())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await harness.result.waitForPackageAndDownload('p1')

    expect(get).toHaveBeenCalledTimes(1)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(download).toHaveBeenCalledWith('p1')
    click.mockRestore()
  })

  it('polls through the building states and reports progress', async () => {
    vi.useFakeTimers()
    get
      .mockResolvedValueOnce({ data: pkg('pending', { progress: 0 }) })
      .mockResolvedValueOnce({ data: pkg('building', { progress: 40 }) })
      .mockResolvedValueOnce({ data: pkg('completed', { progress: 100 }) })
    download.mockResolvedValue({ url: 'https://cdn.test/p1.zip' })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const progress: number[] = []

    const pending = mount(() => inSpace()).result.waitForPackageAndDownload('p1', (value) =>
      progress.push(value)
    )
    await vi.advanceTimersByTimeAsync(5000)
    await pending

    // Progress is only reported for non-terminal polls, so 100 never arrives.
    expect(progress).toEqual([0, 40])
    expect(get).toHaveBeenCalledTimes(3)
    click.mockRestore()
  })

  it('waits 2.5s between polls', async () => {
    vi.useFakeTimers()
    get
      .mockResolvedValueOnce({ data: pkg('building') })
      .mockResolvedValueOnce({ data: pkg('completed') })
    download.mockResolvedValue({ url: 'https://cdn.test/p1.zip' })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    const pending = mount(() => inSpace()).result.waitForPackageAndDownload('p1')

    await vi.advanceTimersByTimeAsync(2400)
    expect(get).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(200)
    await pending
    expect(get).toHaveBeenCalledTimes(2)
    click.mockRestore()
  })

  it('throws the server error message on a failed build and refreshes the list', async () => {
    get.mockResolvedValue({ data: pkg('failed', { error: 'disk full' }) })
    const harness = mount(() => inSpace())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    await expect(harness.result.waitForPackageAndDownload('p1')).rejects.toThrow('disk full')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    expect(download).not.toHaveBeenCalled()
  })

  it('falls back to a generic message when the failure carries none', async () => {
    get.mockResolvedValue({ data: pkg('failed') })

    await expect(mount(() => inSpace()).result.waitForPackageAndDownload('p1')).rejects.toThrow(
      'Package build failed'
    )
  })

  it('bails on the first poll for a state that is not still building', async () => {
    // A state added server-side later (e.g. 'expired') is terminal as far as this
    // client is concerned — polling it for ten minutes only burns requests.
    get.mockResolvedValue({ data: pkg('expired') })

    const pending = mount(() => inSpace())
      .result.waitForPackageAndDownload('p1')
      .catch((reason: Error) => reason.message)

    expect(await pending).toBe('Package build expired')
    expect(get).toHaveBeenCalledTimes(1)
  })

  it('gives up after 240 attempts rather than polling forever', async () => {
    vi.useFakeTimers()
    get.mockResolvedValue({ data: pkg('building') })

    const pending = mount(() => inSpace())
      .result.waitForPackageAndDownload('p1')
      .catch((reason: Error) => reason.message)

    await vi.advanceTimersByTimeAsync(2500 * 240)

    expect(await pending).toBe('Package build timed out')
    expect(get).toHaveBeenCalledTimes(240)
  })

  it('propagates a polling request failure instead of retrying', async () => {
    get.mockRejectedValue(new Error('network'))

    await expect(mount(() => inSpace()).result.waitForPackageAndDownload('p1')).rejects.toThrow(
      'network'
    )
    expect(get).toHaveBeenCalledTimes(1)
  })
})

describe('downloadSelectionAsPackage', () => {
  it('does nothing at all for an empty selection', async () => {
    await expect(mount(() => inSpace()).result.downloadSelectionAsPackage([])).resolves.toBe(false)

    expect(create).not.toHaveBeenCalled()
    expect(loading).not.toHaveBeenCalled()
  })

  it('creates a selection package, polls it and downloads it under one toast id', async () => {
    vi.useFakeTimers()
    create.mockResolvedValue({ data: { id: 'p1' } })
    get
      .mockResolvedValueOnce({ data: pkg('building', { progress: 60 }) })
      .mockResolvedValueOnce({ data: pkg('completed') })
    download.mockResolvedValue({ url: 'https://cdn.test/p1.zip' })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const harness = mount(() => inSpace())
    const invalidate = vi.spyOn(harness.queryClient, 'invalidateQueries')

    const pending = harness.result.downloadSelectionAsPackage(['a1', 'a2'])
    await vi.advanceTimersByTimeAsync(3000)

    expect(await pending).toBe(true)
    expect(loading).toHaveBeenNthCalledWith(1, 'Preparing download of 2 assets…')
    expect(create).toHaveBeenCalledWith({ source_type: 'selection', asset_ids: ['a1', 'a2'] })
    expect(loading).toHaveBeenNthCalledWith(2, 'Building archive… 60%', { id: 'toast-1' })
    expect(success).toHaveBeenCalledWith('Your download is starting', { id: 'toast-1' })
    expect(error).not.toHaveBeenCalled()
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys.lists() })
    click.mockRestore()
  })

  it('uses the singular preparing copy for one asset', async () => {
    create.mockResolvedValue({ data: { id: 'p1' } })
    get.mockResolvedValue({ data: pkg('completed') })
    download.mockResolvedValue({ url: 'https://cdn.test/p1.zip' })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    await mount(() => inSpace()).result.downloadSelectionAsPackage(['a1'])

    expect(loading).toHaveBeenNthCalledWith(1, 'Preparing download of 1 asset…')
    click.mockRestore()
  })

  it('reports a build failure to the caller as well as to the toast', async () => {
    create.mockResolvedValue({ data: { id: 'p1' } })
    get.mockResolvedValue({ data: pkg('failed', { error: 'disk full' }) })

    // false, so a caller can tell a started download from a failed one.
    await expect(mount(() => inSpace()).result.downloadSelectionAsPackage(['a1'])).resolves.toBe(
      false
    )

    expect(error).toHaveBeenCalledWith('Failed to prepare download: disk full', { id: 'toast-1' })
    expect(success).not.toHaveBeenCalled()
  })

  it('reports a create failure under the same toast', async () => {
    create.mockRejectedValue(new Error('quota exceeded'))

    await mount(() => inSpace()).result.downloadSelectionAsPackage(['a1'])

    expect(error).toHaveBeenCalledWith('Failed to prepare download: quota exceeded', {
      id: 'toast-1',
    })
    expect(get).not.toHaveBeenCalled()
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    create.mockRejectedValue({})

    await mount(() => inSpace()).result.downloadSelectionAsPackage(['a1'])

    expect(error).toHaveBeenCalledWith('Failed to prepare download: Unknown error', {
      id: 'toast-1',
    })
  })
})

describe('query key shape', () => {
  it('scopes every key to the space', () => {
    expect(keys.all()).toEqual(['spaces', SPACE, 'asset-packages'])
    expect(queryKeys.assetPackages('a').lists()).not.toEqual(queryKeys.assetPackages('b').lists())
  })

  it('makes lists() a prefix of list(filters)', () => {
    const list = keys.list({ state: 'pending' })

    expect(list.slice(0, keys.lists().length)).toEqual([...keys.lists()])
  })
})
