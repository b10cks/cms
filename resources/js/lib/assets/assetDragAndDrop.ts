import type { ElementEventPayloadMap } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { pointerOutsideOfPreview } from '@atlaskit/pragmatic-drag-and-drop/element/pointer-outside-of-preview'
import { setCustomNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/set-custom-native-drag-preview'

export type AssetManagerDragItemType = 'asset' | 'folder'

export interface AssetManagerDragItem {
  id: string
  type: AssetManagerDragItemType
}

const ASSET_MANAGER_DRAG_KIND = 'asset-manager'

export function createAssetManagerDragData(
  items: AssetManagerDragItem[],
  primary: AssetManagerDragItem
): Record<string, unknown> {
  return {
    kind: ASSET_MANAGER_DRAG_KIND,
    items,
    primaryId: primary.id,
    primaryType: primary.type,
  }
}

export function isAssetManagerDragData(data: Record<string, unknown> | null | undefined): boolean {
  return data?.kind === ASSET_MANAGER_DRAG_KIND
}

export function getAssetManagerDragItems(
  data: Record<string, unknown> | null | undefined
): AssetManagerDragItem[] {
  if (!isAssetManagerDragData(data) || !Array.isArray(data?.items)) {
    return []
  }

  return data.items.flatMap((item) => {
    if (
      item &&
      typeof item === 'object' &&
      'id' in item &&
      'type' in item &&
      typeof item.id === 'string' &&
      (item.type === 'asset' || item.type === 'folder')
    ) {
      return [{ id: item.id, type: item.type }]
    }

    return []
  })
}

export function setAssetManagerDragPreview({
  nativeSetDragImage,
  count,
  title,
}: {
  nativeSetDragImage: ElementEventPayloadMap['onGenerateDragPreview']['nativeSetDragImage']
  count: number
  title: string
}) {
  setCustomNativeDragPreview({
    nativeSetDragImage,
    getOffset: pointerOutsideOfPreview({
      x: '12px',
      y: '10px',
    }),
    render({ container }) {
      const preview = document.createElement('div')
      const label = document.createElement('div')
      const badge = document.createElement('div')

      preview.style.display = 'inline-flex'
      preview.style.alignItems = 'center'
      preview.style.gap = '8px'
      preview.style.maxWidth = '180px'
      preview.style.padding = '8px 10px'
      preview.style.borderRadius = '10px'
      preview.style.border = '1px solid rgba(148, 163, 184, 0.35)'
      preview.style.background = 'rgba(15, 23, 42, 0.94)'
      preview.style.boxShadow = '0 12px 24px rgba(15, 23, 42, 0.18)'
      preview.style.color = '#f8fafc'
      preview.style.fontSize = '12px'
      preview.style.fontWeight = '600'
      preview.style.lineHeight = '16px'

      label.textContent = title
      label.style.minWidth = '0'
      label.style.overflow = 'hidden'
      label.style.textOverflow = 'ellipsis'
      label.style.whiteSpace = 'nowrap'

      preview.appendChild(label)

      if (count > 1) {
        badge.textContent = String(count)
        badge.style.flexShrink = '0'
        badge.style.padding = '2px 6px'
        badge.style.borderRadius = '999px'
        badge.style.background = 'rgba(248, 250, 252, 0.14)'
        badge.style.fontSize = '11px'
        badge.style.fontWeight = '700'
        preview.appendChild(badge)
      }

      container.appendChild(preview)

      return () => {
        preview.remove()
      }
    },
  })
}
