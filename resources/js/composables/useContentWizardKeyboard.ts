import {
  CONTENT_WIZARD_ROOT_ID,
  type ContentWizardAddPosition,
  type ContentWizardEditableField,
} from '~/types/content-wizard'

export function useContentWizardKeyboard(options: {
  getNode: (nodeId: string | null | undefined) => {
    id: string
    parentId: string | null
    childrenIds: string[]
    isRootVirtual: boolean
    isCollapsed?: boolean
    deletedReason?: string
  } | null
  focusNode: (nodeId: string) => void
  createNodeFromSpaceDefault: (nodeId: string) => boolean
  duplicateWithCurrentBlock: (nodeId: string, position: ContentWizardAddPosition) => boolean
  openAddMenu: (nodeId: string, position: ContentWizardAddPosition) => boolean
  toggleDelete: (nodeId: string) => void
  startEditing: (nodeId: string, field: ContentWizardEditableField, initialChar?: string) => void
  clearTransientState: () => void
}) {
  const getSiblingIds = (nodeId: string) => {
    const node = options.getNode(nodeId)
    if (!node) {
      return []
    }

    const parent = options.getNode(node.parentId)
    return parent?.childrenIds || []
  }

  const findSibling = (nodeId: string, direction: 'up' | 'down') => {
    const siblingIds = getSiblingIds(nodeId)
    const currentIndex = siblingIds.indexOf(nodeId)

    if (currentIndex < 0) {
      return null
    }

    return direction === 'up'
      ? siblingIds[currentIndex - 1] || null
      : siblingIds[currentIndex + 1] || null
  }

  const findParentOrChild = (nodeId: string, direction: 'left' | 'right') => {
    const node = options.getNode(nodeId)
    if (!node) {
      return null
    }

    if (direction === 'left') {
      if (node.isRootVirtual) {
        return null
      }

      return node.parentId ?? CONTENT_WIZARD_ROOT_ID
    }

    if (node.isCollapsed) {
      return null
    }

    return node.childrenIds[0] || null
  }

  const handleKeydown = (event: KeyboardEvent, nodeId: string) => {
    const target = event.target as HTMLElement | null
    const isEditingField = !!target?.closest('input,textarea,[contenteditable="true"]')
    const isBlockSelect = !!target?.closest('[data-block-select]')
    const node = options.getNode(nodeId)

    if (event.key === 'Tab') {
      event.preventDefault()
      if (node?.isRootVirtual) {
        if (!options.createNodeFromSpaceDefault(nodeId)) {
          options.openAddMenu(nodeId, 'child')
        }
        return
      }

      if (event.altKey) {
        options.openAddMenu(nodeId, 'child')
        return
      }

      if (!options.duplicateWithCurrentBlock(nodeId, 'child')) {
        options.openAddMenu(nodeId, 'child')
      }
      return
    }

    if (event.key === 'Enter') {
      event.preventDefault()
      if (node?.isRootVirtual) {
        if (event.altKey) {
          options.openAddMenu(nodeId, 'child')
          return
        }

        if (!options.createNodeFromSpaceDefault(nodeId)) {
          options.openAddMenu(nodeId, 'child')
        }
        return
      }

      if (event.altKey) {
        options.openAddMenu(nodeId, 'sibling')
        return
      }

      if (!options.duplicateWithCurrentBlock(nodeId, 'sibling')) {
        options.openAddMenu(nodeId, 'sibling')
      }
      return
    }

    if (event.key === 'Delete' || event.key === 'Backspace') {
      if (isEditingField && !event.altKey) {
        return
      }

      event.preventDefault()
      options.toggleDelete(nodeId)
      return
    }

    if (event.key === 'Escape') {
      event.preventDefault()
      options.clearTransientState()
      return
    }

    if (event.key === 'F2') {
      event.preventDefault()
      options.startEditing(nodeId, 'title')
      return
    }

    if (event.key.startsWith('Arrow')) {
      if (isEditingField && !event.altKey) {
        return
      }

      event.preventDefault()
      const direction = event.key.replace('Arrow', '').toLowerCase() as
        | 'left'
        | 'right'
        | 'up'
        | 'down'
      const neighbor =
        direction === 'up' || direction === 'down'
          ? findSibling(nodeId, direction)
          : findParentOrChild(nodeId, direction)
      if (neighbor) {
        options.focusNode(neighbor)
      }
      return
    }

    if (isEditingField || isBlockSelect) {
      return
    }

    if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
      event.preventDefault()
      options.startEditing(nodeId, 'title', event.key)
    }
  }

  return {
    handleKeydown,
  }
}
