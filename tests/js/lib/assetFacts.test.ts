import { describe, expect, it } from 'vitest'

import { assetFacts, assetFileName, toAssetValue } from '~/lib/assetFacts'

const size = (bytes: number) => `${bytes} B`

describe('assetFacts', () => {
  it('reads type, pixels and size', () => {
    const asset = { filename: 'hall', extension: 'jpg', size: 42, metadata: { width: 800, height: 600 } }

    expect(assetFacts(asset, size)).toBe('JPG, 800 × 600, 42 B')
  })

  it('leaves out pixels the value does not carry', () => {
    expect(assetFacts({ filename: 'cv', extension: 'pdf', size: 42 }, size)).toBe('PDF, 42 B')
  })
})

describe('assetFileName', () => {
  it('adds the extension once', () => {
    expect(assetFileName({ filename: 'hall', extension: 'jpg', size: 1 })).toBe('hall.jpg')
    expect(assetFileName({ filename: 'hall.JPG', extension: 'jpg', size: 1 })).toBe('hall.JPG')
  })
})

describe('toAssetValue', () => {
  it('keeps the stored shape and carries pixels only when the library has them', () => {
    const asset = {
      id: 'a1',
      filename: 'hall',
      extension: 'jpg',
      mime_type: 'image/jpeg',
      size: 42,
      full_path: 'store/a1/hall.jpg',
      metadata: { width: 800, height: 600, exif: {} },
    } as unknown as AssetResource

    expect(toAssetValue(asset)).toEqual({
      id: 'a1',
      type: 'asset',
      full_path: 'store/a1/hall.jpg',
      extension: 'jpg',
      mime_type: 'image/jpeg',
      size: 42,
      filename: 'hall',
      metadata: { width: 800, height: 600 },
      data: {},
    })
    expect(toAssetValue({ ...asset, metadata: {} })).not.toHaveProperty('metadata')
  })
})
