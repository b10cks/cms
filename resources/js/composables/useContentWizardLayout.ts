import {
  CONTENT_WIZARD_CARD_HEIGHT,
  CONTENT_WIZARD_CARD_WIDTH,
  CONTENT_WIZARD_HORIZONTAL_GAP,
  CONTENT_WIZARD_ROOT_ID,
  CONTENT_WIZARD_VERTICAL_GAP,
} from '~/types/content-wizard'
import type {
  ContentWizardBounds,
  ContentWizardDraftNode,
  ContentWizardLayoutResult,
} from '~/types/content-wizard'

export function useContentWizardLayout() {
  const createEmptyBounds = (): ContentWizardBounds => ({
    minX: 0,
    maxX: 0,
    minY: 0,
    maxY: 0,
    width: 0,
    height: 0,
  })

  const layoutTree = (nodes: Record<string, ContentWizardDraftNode>): ContentWizardLayoutResult => {
    const positions: Record<string, { x: number; y: number }> = {}
    let rowIndex = 0

    const place = (nodeId: string, depth: number) => {
      const node = nodes[nodeId]

      if (!node || !node.isVisible) {
        return
      }

      positions[nodeId] = {
        x: depth * (CONTENT_WIZARD_CARD_WIDTH + CONTENT_WIZARD_HORIZONTAL_GAP),
        y: rowIndex * (CONTENT_WIZARD_CARD_HEIGHT + CONTENT_WIZARD_VERTICAL_GAP),
      }
      rowIndex += 1

      if (!node.childrenIds.length) {
        return
      }

      node.childrenIds.forEach((childId) => {
        place(childId, node.isRootVirtual ? 1 : depth + 1)
      })
    }

    if (!nodes[CONTENT_WIZARD_ROOT_ID]) {
      return {
        positions,
        bounds: createEmptyBounds(),
      }
    }

    place(CONTENT_WIZARD_ROOT_ID, 0)

    const values = Object.values(positions)
    if (!values.length) {
      return {
        positions,
        bounds: createEmptyBounds(),
      }
    }

    const minX = Math.min(...values.map((item) => item.x))
    const maxX = Math.max(...values.map((item) => item.x + CONTENT_WIZARD_CARD_WIDTH))
    const minY = Math.min(...values.map((item) => item.y))
    const maxY = Math.max(...values.map((item) => item.y + CONTENT_WIZARD_CARD_HEIGHT))

    return {
      positions,
      bounds: {
        minX,
        maxX,
        minY,
        maxY,
        width: maxX - minX,
        height: maxY - minY,
      },
    }
  }

  return {
    layoutTree,
  }
}
