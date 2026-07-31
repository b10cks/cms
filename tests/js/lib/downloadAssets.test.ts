import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  assetDownloadName,
  downloadAssetFile,
  downloadAssetFiles,
} from '~/lib/assets/downloadAssets'

const asset = (overrides: Partial<AssetResource> = {}) =>
  ({
    id: 'asset-1',
    filename: 'photo',
    extension: 'jpg',
    url: 'https://cdn.test/photo.jpg',
    full_path: '/storage/photo.jpg',
    ...overrides,
  }) as unknown as AssetResource

const fetchMock = vi.fn()

// A Response body can only be read once, so every call needs a fresh one —
// otherwise the second download in a batch fails for the wrong reason.
const ok = () => new Response('bytes', { status: 200 })
const createObjectURL = vi.fn(() => 'blob:fake-url')
const revokeObjectURL = vi.fn()
let clicks: Array<{ href: string; download: string }>

beforeEach(() => {
  clicks = []
  fetchMock.mockReset()
  createObjectURL.mockClear()
  revokeObjectURL.mockClear()
  vi.stubGlobal('fetch', fetchMock)
  // jsdom implements neither, and never actually downloads.
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
    this: HTMLAnchorElement
  ) {
    clicks.push({ href: this.href, download: this.download })
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('assetDownloadName', () => {
  it('joins the filename and extension', () => {
    expect(assetDownloadName(asset())).toBe('photo.jpg')
  })

  it('omits the dot when the asset has no extension', () => {
    expect(assetDownloadName(asset({ extension: null } as unknown as Partial<AssetResource>))).toBe('photo')
    expect(assetDownloadName(asset({ extension: '' }))).toBe('photo')
  })
})

describe('downloadAssetFile', () => {
  it('fetches the CDN url with credentials and clicks a named download link', async () => {
    fetchMock.mockResolvedValue(new Response('bytes', { status: 200 }))

    await downloadAssetFile(asset())

    expect(fetchMock).toHaveBeenCalledWith('https://cdn.test/photo.jpg', {
      credentials: 'include',
    })
    expect(clicks).toEqual([{ href: 'blob:fake-url', download: 'photo.jpg' }])
  })

  it('falls back to the storage path when the asset has no url', async () => {
    fetchMock.mockResolvedValue(new Response('bytes', { status: 200 }))

    await downloadAssetFile(asset({ url: null } as unknown as Partial<AssetResource>))

    expect(fetchMock).toHaveBeenCalledWith('/storage/photo.jpg', { credentials: 'include' })
  })

  it('revokes the object url afterwards', async () => {
    fetchMock.mockResolvedValue(new Response('bytes', { status: 200 }))

    await downloadAssetFile(asset())

    expect(createObjectURL).toHaveBeenCalledTimes(1)
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:fake-url')
  })

  it('leaves no link behind in the document', async () => {
    fetchMock.mockResolvedValue(new Response('bytes', { status: 200 }))

    await downloadAssetFile(asset())

    expect(document.querySelector('a')).toBeNull()
  })

  it('throws on a failed response and downloads nothing', async () => {
    fetchMock.mockResolvedValue(new Response('nope', { status: 403 }))

    await expect(downloadAssetFile(asset())).rejects.toThrow('Download failed with status 403')
    expect(clicks).toEqual([])
  })

  it('propagates a network error', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(downloadAssetFile(asset())).rejects.toThrow('Failed to fetch')
  })
})

describe('downloadAssetFiles', () => {
  it('downloads each asset and counts the successes', async () => {
    fetchMock.mockImplementation(async () => ok())

    const result = await downloadAssetFiles([asset({ filename: 'a' }), asset({ filename: 'b' })])

    expect(clicks.map((entry) => entry.download)).toEqual(['a.jpg', 'b.jpg'])
    expect(result).toEqual({ succeeded: 2, failed: [] })
  })

  it('keeps going past a failure and names what failed', async () => {
    fetchMock
      .mockResolvedValueOnce(new Response('bytes', { status: 200 }))
      .mockResolvedValueOnce(new Response('nope', { status: 500 }))
      .mockResolvedValueOnce(new Response('bytes', { status: 200 }))

    const result = await downloadAssetFiles([
      asset({ filename: 'a' }),
      asset({ filename: 'broken' }),
      asset({ filename: 'c' }),
    ])

    expect(clicks.map((entry) => entry.download)).toEqual(['a.jpg', 'c.jpg'])
    expect(result).toEqual({ succeeded: 2, failed: ['broken.jpg'] })
  })

  it('swallows a network error into the failed list', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(downloadAssetFiles([asset()])).resolves.toEqual({
      succeeded: 0,
      failed: ['photo.jpg'],
    })
  })

  it('reports progress after every asset, failures included', async () => {
    fetchMock
      .mockResolvedValueOnce(new Response('bytes', { status: 200 }))
      .mockResolvedValueOnce(new Response('nope', { status: 500 }))

    const progress: Array<[number, number]> = []

    await downloadAssetFiles([asset({ filename: 'a' }), asset({ filename: 'b' })], (done, total) =>
      progress.push([done, total])
    )

    expect(progress).toEqual([
      [1, 2],
      [2, 2],
    ])
  })

  it('downloads one at a time rather than flooding the browser', async () => {
    let inFlight = 0
    let peak = 0

    fetchMock.mockImplementation(async () => {
      inFlight += 1
      peak = Math.max(peak, inFlight)
      await Promise.resolve()
      inFlight -= 1
      return new Response('bytes', { status: 200 })
    })

    await downloadAssetFiles([asset(), asset(), asset()])

    expect(peak).toBe(1)
  })

  it('handles an empty selection', async () => {
    expect(await downloadAssetFiles([])).toEqual({ succeeded: 0, failed: [] })
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
