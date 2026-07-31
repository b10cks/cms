import { afterEach, describe, expect, it, vi } from 'vitest'

import type { AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'

import {
  createAssetManagerDragData,
  getAssetManagerDragItems,
  isAssetManagerDragData,
  setAssetManagerDragPreview,
} from '~/lib/assets/assetDragAndDrop'

const asset = (id: string): AssetManagerDragItem => ({ id, type: 'asset' })
const folder = (id: string): AssetManagerDragItem => ({ id, type: 'folder' })

describe('createAssetManagerDragData', () => {
  it('tags the payload and records the primary item', () => {
    expect(createAssetManagerDragData([folder('f1'), asset('a1')], asset('a1'))).toEqual({
      kind: 'asset-manager',
      items: [folder('f1'), asset('a1')],
      primaryId: 'a1',
      primaryType: 'asset',
    })
  })

  it('records a folder as the primary', () => {
    expect(createAssetManagerDragData([folder('f1')], folder('f1'))).toMatchObject({
      primaryId: 'f1',
      primaryType: 'folder',
    })
  })

  it('does not require the primary to be in the item list', () => {
    // The grid drags the whole selection but names the grabbed tile as primary;
    // a tile dragged while unselected is therefore not in `items`.
    expect(createAssetManagerDragData([], asset('a1'))).toMatchObject({
      items: [],
      primaryId: 'a1',
    })
  })
})

describe('isAssetManagerDragData', () => {
  it('recognizes its own payload', () => {
    expect(isAssetManagerDragData(createAssetManagerDragData([asset('a1')], asset('a1')))).toBe(true)
  })

  it.each([
    ['a foreign kind', { kind: 'content-tree' }],
    ['no kind', { items: [] }],
    ['null', null],
    ['undefined', undefined],
    ['an empty object', {}],
  ])('rejects %s', (_label, data) => {
    expect(isAssetManagerDragData(data)).toBe(false)
  })
})

describe('getAssetManagerDragItems', () => {
  it('returns the items from its own payload', () => {
    expect(
      getAssetManagerDragItems(createAssetManagerDragData([folder('f1'), asset('a1')], asset('a1')))
    ).toEqual([folder('f1'), asset('a1')])
  })

  it('returns nothing for a foreign payload, even one carrying items', () => {
    expect(getAssetManagerDragItems({ kind: 'content-tree', items: [asset('a1')] })).toEqual([])
  })

  it('returns nothing when items is missing or not an array', () => {
    expect(getAssetManagerDragItems({ kind: 'asset-manager' })).toEqual([])
    expect(getAssetManagerDragItems({ kind: 'asset-manager', items: 'nope' })).toEqual([])
  })

  it('returns nothing for null or undefined', () => {
    expect(getAssetManagerDragItems(null)).toEqual([])
    expect(getAssetManagerDragItems(undefined)).toEqual([])
  })

  it('drops malformed entries and keeps the valid ones', () => {
    expect(
      getAssetManagerDragItems({
        kind: 'asset-manager',
        items: [
          asset('a1'),
          null,
          'nope',
          42,
          { id: 'a2' },
          { type: 'asset' },
          { id: 7, type: 'asset' },
          { id: 'a3', type: 'mystery' },
          folder('f1'),
        ],
      })
    ).toEqual([asset('a1'), folder('f1')])
  })

  it('narrows each entry to just id and type', () => {
    expect(
      getAssetManagerDragItems({
        kind: 'asset-manager',
        items: [{ id: 'a1', type: 'asset', filename: 'a.jpg', extra: true }],
      })
    ).toEqual([{ id: 'a1', type: 'asset' }])
  })

  it('round-trips a created payload', () => {
    const items = [folder('f1'), asset('a1'), asset('a2')]

    expect(getAssetManagerDragItems(createAssetManagerDragData(items, items[0]))).toEqual(items)
  })
})

describe('setAssetManagerDragPreview', () => {
  /**
   * pragmatic-drag-and-drop renders the preview into a container it appends to
   * the document and hands to `render`. Driving it through the real
   * setCustomNativeDragPreview keeps the offset/render wiring under test; only
   * the browser's nativeSetDragImage is faked.
   */
  const renderPreview = async (count: number, title: string) => {
    const nativeSetDragImage = vi.fn()

    setAssetManagerDragPreview({ nativeSetDragImage, count, title })

    // The container reaches nativeSetDragImage on the next frame, not synchronously.
    await vi.waitFor(() => expect(nativeSetDragImage).toHaveBeenCalled())

    const [container] = nativeSetDragImage.mock.calls[0] as [HTMLElement]

    return { container, nativeSetDragImage }
  }

  // The container is only removed when the drag ends, which never happens here.
  afterEach(() => {
    document.body.replaceChildren()
  })

  it('hands the browser a container holding the preview', async () => {
    const { container, nativeSetDragImage } = await renderPreview(1, 'photo.jpg')

    expect(nativeSetDragImage).toHaveBeenCalledTimes(1)
    expect(container.firstElementChild).not.toBeNull()
  })

  it('labels the preview with the title', async () => {
    expect((await renderPreview(1, 'photo.jpg')).container.textContent).toContain('photo.jpg')
  })

  it('adds no count badge for a single item', async () => {
    const { container } = await renderPreview(1, 'photo.jpg')

    expect(container.firstElementChild?.children).toHaveLength(1)
  })

  it('adds a count badge when several items are dragged', async () => {
    const { container } = await renderPreview(4, 'photo.jpg')
    const preview = container.firstElementChild as HTMLElement

    expect(preview.children).toHaveLength(2)
    expect(preview.lastElementChild?.textContent).toBe('4')
  })

  it('sets the title as text, so a crafted filename cannot inject markup', async () => {
    const { container } = await renderPreview(1, '<img src=x onerror=alert(1)>')

    expect(container.querySelector('img')).toBeNull()
    expect(container.textContent).toContain('<img src=x onerror=alert(1)>')
  })
})
