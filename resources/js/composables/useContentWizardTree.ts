import {
  CONTENT_WIZARD_ROOT_ID,
  type ContentWizardAddPosition,
  type ContentWizardBounds,
  type ContentWizardDraftNode,
  type ContentWizardDraftTree,
  type ContentWizardOperation,
  type ContentWizardValidationError,
} from '~/types/content-wizard'
import type { ContentSettings } from '~/types/contents'
import { resolveAllowedChildContentBlocks } from '~/lib/content-children'

import { useContentWizardLayout } from './useContentWizardLayout'
import { useContentWizardSlug } from './useContentWizardSlug'

const createDraftId = () => `draft:${crypto.randomUUID?.() || `${Date.now()}-${Math.random()}`}`

type ValidationResult = {
  valid: boolean
  message?: string
}

const EMPTY_BOUNDS: ContentWizardBounds = {
  minX: 0,
  maxX: 0,
  minY: 0,
  maxY: 0,
  width: 0,
  height: 0,
}

const cloneNodeSettings = (settings: Partial<ContentSettings> | null | undefined) => ({
  ...settings,
  child_block_whitelist: [...(settings?.child_block_whitelist || [])],
  child_tag_whitelist: [...(settings?.child_tag_whitelist || [])],
})

export function useContentWizardTree(
  blocks: Ref<BlockResource[]>,
  menuData: Ref<Record<string, FlatContentMenuItem> | undefined>
) {
  const { layoutTree } = useContentWizardLayout()
  const { resolveEffectiveSlug, resolveSlugMode, slugify, syncSlugWithTitle } =
    useContentWizardSlug()

  const tree = ref<ContentWizardDraftTree>({
    rootId: CONTENT_WIZARD_ROOT_ID,
    nodes: {},
  })

  const layoutBounds = ref<ContentWizardBounds>(EMPTY_BOUNDS)
  const blockMap = computed(() => new Map(blocks.value.map((block) => [block.id, block])))

  const createRootNode = (): ContentWizardDraftNode => ({
    id: CONTENT_WIZARD_ROOT_ID,
    backendId: null,
    parentId: null,
    childrenIds: [],
    blockId: '__root__',
    blockType: 'root',
    blockName: 'Root',
    settings: {},
    title: 'Root',
    slug: '',
    slugMode: 'manual',
    icon: 'tree-pine',
    color: null,
    depth: 0,
    position: 0,
    layout: { x: 0, y: 0 },
    isRootVirtual: true,
    canHaveChildren: true,
    isCollapsed: false,
    isVisible: true,
    isAiAltered: false,
    isDeletedSelf: false,
    changes: {
      created: false,
      updated: false,
      moved: false,
      deleted: false,
    },
    validationState: {
      hasErrors: false,
      errors: [],
    },
    original: null,
  })

  const cloneNode = (node: ContentWizardDraftNode): ContentWizardDraftNode => ({
    ...node,
    settings: cloneNodeSettings(node.settings),
    childrenIds: [...node.childrenIds],
    layout: { ...node.layout },
    changes: { ...node.changes },
    validationState: {
      hasErrors: node.validationState.hasErrors,
      errors: node.validationState.errors.map((error) => ({ ...error })),
    },
    original: node.original ? { ...node.original } : null,
  })

  const cloneTreeSnapshot = (
    source: ContentWizardDraftTree = tree.value
  ): ContentWizardDraftTree => ({
    rootId: source.rootId,
    nodes: Object.fromEntries(
      Object.entries(source.nodes).map(([nodeId, node]) => [nodeId, cloneNode(node)])
    ),
  })

  const getNode = (nodeId: string | null | undefined) => {
    if (!nodeId) {
      return tree.value.nodes[CONTENT_WIZARD_ROOT_ID] || null
    }

    return tree.value.nodes[nodeId] || null
  }

  const isRootLevelItem = (item: Pick<FlatContentMenuItem, 'pid' | 'type'>) => item.pid === null

  const compareMenuItems = (left: FlatContentMenuItem, right: FlatContentMenuItem) => {
    if (isRootLevelItem(left) && isRootLevelItem(right) && left.type !== right.type) {
      if (left.type === 'single') {
        return 1
      }

      if (right.type === 'single') {
        return -1
      }
    }

    const nameResult = left.name.localeCompare(right.name)
    if (nameResult !== 0) {
      return nameResult
    }

    return left.id.localeCompare(right.id)
  }

  const applyLayout = () => {
    const result = layoutTree(tree.value.nodes)
    layoutBounds.value = result.bounds

    Object.entries(result.positions).forEach(([nodeId, position]) => {
      const node = tree.value.nodes[nodeId]
      if (node) {
        node.layout = position
      }
    })
  }

  const activeChildrenCount = (node: ContentWizardDraftNode) => {
    return node.childrenIds.filter((childId) => {
      const child = getNode(childId)
      return !!child && !child.deletedReason
    }).length
  }

  const isSingleBlockTaken = (blockId: string, excludeNodeId?: string) => {
    return Object.values(tree.value.nodes).some((node) => {
      if (
        node.isRootVirtual ||
        node.id === excludeNodeId ||
        node.deletedReason ||
        node.blockId !== blockId
      ) {
        return false
      }

      return node.blockType === 'single'
    })
  }

  const canPlaceBlockUnderParent = (
    block: Pick<BlockResource, 'id' | 'type'>,
    parentId: string | null,
    options: {
      excludeNodeId?: string
    } = {}
  ): ValidationResult => {
    if (block.type === 'nestable') {
      return {
        valid: false,
        message: 'Nestable blocks are not available in the content wizard.',
      }
    }

    const parent = parentId ? getNode(parentId) : getNode(CONTENT_WIZARD_ROOT_ID)
    if (!parent) {
      return {
        valid: false,
        message: 'Missing parent node.',
      }
    }

    if (!parent.isRootVirtual) {
      if (parent.deletedReason) {
        return {
          valid: false,
          message: 'Move the parent out of the deleted branch first.',
        }
      }

      if (!parent.canHaveChildren) {
        return {
          valid: false,
          message: 'Single blocks cannot contain children.',
        }
      }

      const allowedBlocks = resolveAllowedChildContentBlocks(blocks.value, parent.settings)
      if (!allowedBlocks.some((candidate) => candidate.id === block.id)) {
        return {
          valid: false,
          message: 'This content type is not allowed under the selected parent.',
        }
      }
    }

    if (block.type !== 'single') {
      return { valid: true }
    }

    if (parentId !== null) {
      return {
        valid: false,
        message: 'Single blocks can only live at the root.',
      }
    }

    if (isSingleBlockTaken(block.id, options.excludeNodeId)) {
      return {
        valid: false,
        message: 'This single block already exists in the tree.',
      }
    }

    const node = options.excludeNodeId ? getNode(options.excludeNodeId) : null
    if (node && activeChildrenCount(node) > 0) {
      return {
        valid: false,
        message: 'Single blocks cannot keep children.',
      }
    }

    return { valid: true }
  }

  const getAssignableBlocks = (nodeId: string) => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return []
    }

    const blocksForLocation = blocks.value.filter(
      (block) => canPlaceBlockUnderParent(block, node.parentId, { excludeNodeId: nodeId }).valid
    )

    if (blocksForLocation.some((block) => block.id === node.blockId)) {
      return blocksForLocation
    }

    const currentBlock = blockMap.value.get(node.blockId)
    return currentBlock ? [currentBlock, ...blocksForLocation] : blocksForLocation
  }

  const getAvailableBlocks = (
    parentId: string | null,
    options: {
      excludeNodeId?: string
    } = {}
  ) => {
    return blocks.value.filter((block) => canPlaceBlockUnderParent(block, parentId, options).valid)
  }

  const removeFromParent = (nodeId: string) => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return
    }

    const parent = node.parentId ? getNode(node.parentId) : getNode(CONTENT_WIZARD_ROOT_ID)
    if (!parent) {
      return
    }

    parent.childrenIds = parent.childrenIds.filter((childId) => childId !== nodeId)
  }

  const insertIntoParent = (nodeId: string, parentId: string | null, index?: number) => {
    const parent = parentId ? getNode(parentId) : getNode(CONTENT_WIZARD_ROOT_ID)
    const node = getNode(nodeId)

    if (!parent || !node) {
      return
    }

    const nextChildren = [...parent.childrenIds]
    const targetIndex =
      typeof index === 'number'
        ? Math.max(0, Math.min(index, nextChildren.length))
        : nextChildren.length

    nextChildren.splice(targetIndex, 0, nodeId)
    parent.childrenIds = nextChildren
    node.parentId = parentId
  }

  const isDescendant = (nodeId: string, possibleAncestorId: string) => {
    let current = getNode(nodeId)

    while (current?.parentId) {
      if (current.parentId === possibleAncestorId) {
        return true
      }

      current = getNode(current.parentId)
    }

    return false
  }

  const recomputeNodeState = () => {
    const root = getNode(CONTENT_WIZARD_ROOT_ID)
    if (!root) {
      return
    }

    const visit = (nodeId: string, depth: number, ancestorDeleted: boolean, isVisible: boolean) => {
      const node = getNode(nodeId)
      if (!node) {
        return
      }

      node.depth = depth
      node.canHaveChildren = node.blockType !== 'single'
      node.isVisible = isVisible
      node.deletedReason = node.isRootVirtual
        ? undefined
        : node.isDeletedSelf
          ? 'self'
          : ancestorDeleted
            ? 'ancestor'
            : undefined

      const descendantsVisible = node.isVisible && !node.isCollapsed

      node.childrenIds.forEach((childId, index) => {
        const child = getNode(childId)
        if (child) {
          child.position = index
          child.isVisible = descendantsVisible
        }

        visit(childId, depth + 1, ancestorDeleted || node.isDeletedSelf, descendantsVisible)
      })
    }

    root.position = 0
    root.isVisible = true
    visit(root.id, 0, false, true)

    Object.values(tree.value.nodes).forEach((node) => {
      if (node.isRootVirtual) {
        node.changes = {
          created: false,
          updated: false,
          moved: false,
          deleted: false,
        }
        return
      }

      const effectiveSlug = resolveEffectiveSlug(node.title, node.slug)
      const originalSlug = node.original
        ? resolveEffectiveSlug(node.original.title, node.original.slug)
        : ''

      node.changes = {
        created: !node.backendId,
        updated:
          !!node.backendId &&
          (node.title !== node.original?.title ||
            effectiveSlug !== originalSlug ||
            node.blockId !== node.original?.blockId),
        moved: !!node.backendId && node.parentId !== node.original?.parentId,
        deleted: node.deletedReason === 'self',
      }
    })

    applyLayout()
  }

  const initializeFromSource = () => {
    const nextNodes: Record<string, ContentWizardDraftNode> = {
      [CONTENT_WIZARD_ROOT_ID]: createRootNode(),
    }

    const sourceItems = Object.values(menuData.value || {})
    const childrenByParent = new Map<string | null, FlatContentMenuItem[]>()

    sourceItems.forEach((item) => {
      const key = item.pid ?? null
      const currentChildren = childrenByParent.get(key) || []
      childrenByParent.set(key, [...currentChildren, item])
    })

    childrenByParent.forEach((items, key) => {
      childrenByParent.set(key, [...items].sort(compareMenuItems))
    })

    sourceItems.forEach((item) => {
      const position = (childrenByParent.get(item.pid ?? null) || []).findIndex(
        (child) => child.id === item.id
      )

      const block = blockMap.value.get(item.block_id)
      const blockType = block?.type || item.type

      nextNodes[item.id] = {
        id: item.id,
        backendId: item.id,
        parentId: item.pid ?? null,
        childrenIds: [],
        blockId: item.block_id,
        blockType,
        blockName: block?.name || item.name,
        settings: cloneNodeSettings(item.settings || {}),
        title: item.name,
        slug: item.slug,
        slugMode: resolveSlugMode(item.name, item.slug),
        icon: block?.icon || item.icon || null,
        color: block?.color || item.color,
        depth: 0,
        position: position < 0 ? 0 : position,
        layout: { x: 0, y: 0 },
        isRootVirtual: false,
        canHaveChildren: blockType !== 'single',
        isCollapsed: false,
        isVisible: true,
        isAiAltered: false,
        isDeletedSelf: false,
        changes: {
          created: false,
          updated: false,
          moved: false,
          deleted: false,
        },
        validationState: {
          hasErrors: false,
          errors: [],
        },
        original: {
          parentId: item.pid ?? null,
          title: item.name,
          slug: item.slug,
          blockId: item.block_id,
          blockType,
          position: position < 0 ? 0 : position,
        },
      }
    })

    Object.values(nextNodes).forEach((node) => {
      if (node.isRootVirtual) {
        node.childrenIds = (childrenByParent.get(null) || []).map((item) => item.id)
        return
      }

      node.childrenIds = (childrenByParent.get(node.id) || []).map((item) => item.id)
    })

    tree.value = {
      rootId: CONTENT_WIZARD_ROOT_ID,
      nodes: nextNodes,
    }

    recomputeNodeState()
  }

  const addNode = (
    block: BlockResource,
    options: {
      parentId: string | null
      position: ContentWizardAddPosition
      referenceNodeId?: string | null
      nodeId?: string
      slug?: string
      slugMode?: 'auto' | 'manual'
      title?: string
      settings?: Partial<ContentSettings>
    }
  ) => {
    const targetParentId =
      options.position === 'sibling' && options.referenceNodeId
        ? (getNode(options.referenceNodeId)?.parentId ?? options.parentId)
        : options.parentId
    const validation = canPlaceBlockUnderParent(block, targetParentId)
    if (!validation.valid) {
      throw new Error(validation.message || 'Invalid block placement')
    }

    const nodeId = options.nodeId || createDraftId()
    const title = options.title || block.name
    const nextNode: ContentWizardDraftNode = {
      id: nodeId,
      backendId: null,
      parentId: options.parentId,
      childrenIds: [],
      blockId: block.id,
      blockType: block.type,
      blockName: block.name,
      settings: cloneNodeSettings(options.settings),
      title,
      slug: options.slug ?? slugify(title),
      slugMode: options.slugMode ?? 'auto',
      icon: block.icon || null,
      color: block.color || null,
      depth: 0,
      position: 0,
      layout: { x: 0, y: 0 },
      isRootVirtual: false,
      canHaveChildren: block.type !== 'single',
      isCollapsed: false,
      isVisible: true,
      isAiAltered: false,
      isDeletedSelf: false,
      changes: {
        created: true,
        updated: false,
        moved: false,
        deleted: false,
      },
      validationState: {
        hasErrors: false,
        errors: [],
      },
      original: null,
    }

    tree.value.nodes[nodeId] = nextNode

    insertIntoParent(nodeId, targetParentId)
    recomputeNodeState()

    return nextNode
  }

  const duplicateNode = (
    nodeId: string,
    nextParentId: string | null
  ): ValidationResult & {
    createdNodeId?: string
  } => {
    const sourceNode = getNode(nodeId)
    if (!sourceNode || sourceNode.isRootVirtual) {
      return {
        valid: false,
        message: 'The selected node cannot be copied.',
      }
    }

    const block = blockMap.value.get(sourceNode.blockId)
    if (!block) {
      return {
        valid: false,
        message: 'The selected block is not available.',
      }
    }

    const validation = canPlaceBlockUnderParent(block, nextParentId)
    if (!validation.valid) {
      return validation
    }

    const cloneSubtree = (
      sourceId: string,
      parentId: string | null,
      isRootClone: boolean
    ): ContentWizardDraftNode | null => {
      const source = getNode(sourceId)
      if (!source || source.isRootVirtual) {
        return null
      }

      const sourceBlock = blockMap.value.get(source.blockId)
      if (!sourceBlock) {
        return null
      }

      const clonedNode = addNode(sourceBlock, {
        parentId,
        position: 'child',
        title: isRootClone ? `${source.title} Copy` : source.title,
        settings: source.settings,
      })

      clonedNode.slug = isRootClone ? slugify(`${source.title} Copy`) : source.slug
      clonedNode.slugMode = source.slugMode
      clonedNode.isDeletedSelf = source.isDeletedSelf

      source.childrenIds.forEach((childId) => {
        cloneSubtree(childId, clonedNode.id, false)
      })

      return clonedNode
    }

    const createdNode = cloneSubtree(nodeId, nextParentId, true)
    recomputeNodeState()

    if (!createdNode) {
      return {
        valid: false,
        message: 'The selected node cannot be copied.',
      }
    }

    return {
      valid: true,
      createdNodeId: createdNode.id,
    }
  }

  const updateTitle = (nodeId: string, title: string) => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return
    }

    node.title = title
    const slugState = syncSlugWithTitle(title, node.slug, node.slugMode)
    node.slug = slugState.slug
    node.slugMode = slugState.slugMode
    recomputeNodeState()
  }

  const updateSlug = (nodeId: string, slug: string) => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return
    }

    const normalizedSlug = slugify(slug)
    node.slug = slug.trim() ? normalizedSlug : ''
    node.slugMode = slug.trim() ? resolveSlugMode(node.title, normalizedSlug) : 'auto'
    recomputeNodeState()
  }

  const updateBlock = (nodeId: string, blockId: string): ValidationResult => {
    const node = getNode(nodeId)
    const block = blockMap.value.get(blockId)

    if (!node || node.isRootVirtual || !block) {
      return {
        valid: false,
        message: 'The selected block is not available.',
      }
    }

    const validation = canPlaceBlockUnderParent(block, node.parentId, {
      excludeNodeId: node.id,
    })

    if (!validation.valid) {
      return validation
    }

    node.blockId = block.id
    node.blockType = block.type
    node.blockName = block.name
    node.icon = block.icon || null
    node.color = block.color || null
    node.canHaveChildren = block.type !== 'single'
    recomputeNodeState()

    return { valid: true }
  }

  const setCollapsed = (nodeId: string, collapsed: boolean): ValidationResult => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return {
        valid: false,
        message: 'The selected node cannot be collapsed.',
      }
    }

    if (node.childrenIds.length === 0) {
      return {
        valid: false,
        message: 'Only nodes with children can be collapsed.',
      }
    }

    node.isCollapsed = collapsed
    recomputeNodeState()

    return { valid: true }
  }

  const setDeletedState = (nodeId: string, shouldBeDeleted: boolean): ValidationResult => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return {
        valid: false,
        message: 'The selected node cannot be changed.',
      }
    }

    const isCurrentlyDeleted = !!node.deletedReason
    if (shouldBeDeleted && !isCurrentlyDeleted) {
      toggleDelete(nodeId)
    }

    if (!shouldBeDeleted && node.deletedReason === 'self') {
      toggleDelete(nodeId)
      return { valid: true }
    }

    if (!shouldBeDeleted && node.deletedReason === 'ancestor') {
      return {
        valid: false,
        message: 'Restore the deleted ancestor or move this node out of that branch first.',
      }
    }

    return { valid: true }
  }

  const markAiAltered = (nodeIds: Iterable<string>) => {
    for (const nodeId of nodeIds) {
      const node = getNode(nodeId)
      if (node) {
        node.isAiAltered = true
      }
    }
  }

  const createSnapshot = () => cloneTreeSnapshot()

  const restoreSnapshot = (snapshot: ContentWizardDraftTree) => {
    tree.value = cloneTreeSnapshot(snapshot)
    recomputeNodeState()
  }

  const exportForAi = () => {
    return orderedNodes.value
      .filter((node) => !node.isRootVirtual)
      .map((node) => ({
        id: node.id,
        backend_id: node.backendId,
        parent_id: node.parentId,
        name: node.title,
        slug: resolveEffectiveSlug(node.title, node.slug),
        block_id: node.blockId,
        block_name: node.blockName,
        block_type: node.blockType,
        deleted_reason: node.deletedReason ?? null,
        is_deleted: !!node.deletedReason,
      }))
  }

  const toggleDelete = (nodeId: string) => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return
    }

    if (!node.backendId) {
      const removeSubtree = (targetNodeId: string) => {
        const target = getNode(targetNodeId)
        if (!target || target.isRootVirtual) {
          return
        }

        ;[...target.childrenIds].forEach((childId) => {
          removeSubtree(childId)
        })

        removeFromParent(targetNodeId)
        delete tree.value.nodes[targetNodeId]
      }

      removeSubtree(nodeId)
      recomputeNodeState()
      return
    }

    node.isDeletedSelf = !node.isDeletedSelf
    recomputeNodeState()
  }

  const moveNode = (
    nodeId: string,
    nextParentId: string | null,
    index?: number
  ): ValidationResult => {
    const node = getNode(nodeId)
    if (!node || node.isRootVirtual) {
      return {
        valid: false,
        message: 'The root cannot be moved.',
      }
    }

    if (nextParentId === node.id || (nextParentId && isDescendant(nextParentId, node.id))) {
      return {
        valid: false,
        message: 'A node cannot move into its own branch.',
      }
    }

    const block = blockMap.value.get(node.blockId)
    if (!block) {
      return {
        valid: false,
        message: 'The selected block is not available.',
      }
    }

    const validation = canPlaceBlockUnderParent(block, nextParentId, {
      excludeNodeId: nodeId,
    })

    if (!validation.valid) {
      return validation
    }

    removeFromParent(nodeId)
    insertIntoParent(nodeId, nextParentId, index)
    recomputeNodeState()

    return { valid: true }
  }

  const validations = computed<ContentWizardValidationError[]>(() => {
    const errors: ContentWizardValidationError[] = []
    const slugGroups = new Map<string, string[]>()
    const singletonGroups = new Map<string, string[]>()

    Object.values(tree.value.nodes)
      .filter((node) => !node.isRootVirtual && !node.deletedReason)
      .forEach((node) => {
        if (!node.title.trim()) {
          errors.push({
            nodeId: node.id,
            field: 'title',
            message: 'A title is required.',
          })
        }

        const effectiveSlug = resolveEffectiveSlug(node.title, node.slug)
        if (!effectiveSlug) {
          errors.push({
            nodeId: node.id,
            field: 'slug',
            message: 'A valid slug is required.',
          })
        }

        const siblingKey = `${node.parentId ?? CONTENT_WIZARD_ROOT_ID}:${effectiveSlug}`
        slugGroups.set(siblingKey, [...(slugGroups.get(siblingKey) || []), node.id])

        const block = blockMap.value.get(node.blockId)
        if (!block) {
          errors.push({
            nodeId: node.id,
            field: 'block',
            message: 'The selected block is no longer available.',
          })
          return
        }

        const placement = canPlaceBlockUnderParent(block, node.parentId, {
          excludeNodeId: node.id,
        })

        if (!placement.valid) {
          errors.push({
            nodeId: node.id,
            field: 'placement',
            message: placement.message || 'This block cannot live here.',
          })
        }

        if (block.type === 'single') {
          singletonGroups.set(block.id, [...(singletonGroups.get(block.id) || []), node.id])
        }
      })

    slugGroups.forEach((nodeIds) => {
      if (nodeIds.length <= 1) {
        return
      }

      nodeIds.forEach((nodeId) => {
        errors.push({
          nodeId,
          field: 'slug',
          message: 'Sibling slugs must stay unique.',
        })
      })
    })

    singletonGroups.forEach((nodeIds) => {
      if (nodeIds.length <= 1) {
        return
      }

      nodeIds.forEach((nodeId) => {
        errors.push({
          nodeId,
          field: 'block',
          message: 'This single block already exists in the tree.',
        })
      })
    })

    return errors
  })

  const validationMap = computed(() => {
    const map = new Map<string, ContentWizardValidationError[]>()

    validations.value.forEach((error) => {
      const currentErrors = map.get(error.nodeId) || []
      map.set(error.nodeId, [...currentErrors, error])
    })

    return map
  })

  watchEffect(() => {
    Object.values(tree.value.nodes).forEach((node) => {
      if (node.isRootVirtual) {
        node.validationState = {
          hasErrors: false,
          errors: [],
        }
        return
      }

      const errors = validationMap.value.get(node.id) || []
      node.validationState = {
        hasErrors: errors.length > 0,
        errors,
      }
    })
  })

  const orderedNodes = computed(() => {
    return Object.values(tree.value.nodes).sort((left, right) => {
      if (left.depth !== right.depth) {
        return left.depth - right.depth
      }

      return left.position - right.position
    })
  })

  const hasUnsavedChanges = computed(() => {
    return Object.values(tree.value.nodes).some((node) => {
      if (node.isRootVirtual) {
        return false
      }

      return (
        node.changes.created || node.changes.updated || node.changes.moved || node.changes.deleted
      )
    })
  })

  const operationPlan = computed<ContentWizardOperation[]>(() => {
    const nodes = Object.values(tree.value.nodes).filter((node) => !node.isRootVirtual)

    const creates = nodes
      .filter((node) => !node.backendId && !node.deletedReason)
      .sort((left, right) => left.depth - right.depth)
      .map((node) => ({
        type: 'create' as const,
        nodeId: node.id,
        parentId: node.parentId,
        depth: node.depth,
      }))

    const updates = nodes
      .filter((node) => {
        if (!node.backendId || node.deletedReason) {
          return false
        }

        const effectiveSlug = resolveEffectiveSlug(node.title, node.slug)
        const originalSlug = node.original
          ? resolveEffectiveSlug(node.original.title, node.original.slug)
          : ''

        return (
          node.title !== node.original?.title ||
          effectiveSlug !== originalSlug ||
          node.blockId !== node.original?.blockId
        )
      })
      .map((node) => ({
        type: 'update' as const,
        nodeId: node.id,
        depth: node.depth,
        fromBlockType: node.original?.blockType || node.blockType,
        toBlockType: node.blockType,
        requiresMoveBeforeUpdate:
          node.original?.blockType !== 'single' && node.blockType === 'single',
        requiresUpdateBeforeMove:
          node.original?.blockType === 'single' && node.blockType !== 'single',
      }))

    const moves = nodes
      .filter(
        (node) =>
          !!node.backendId && !node.deletedReason && node.parentId !== node.original?.parentId
      )
      .sort((left, right) => left.depth - right.depth)
      .map((node) => ({
        type: 'move' as const,
        nodeId: node.id,
        parentId: node.parentId,
        depth: node.depth,
        position: node.position,
      }))

    const deletes = nodes
      .filter((node) => !!node.backendId && node.deletedReason === 'self')
      .sort((left, right) => right.depth - left.depth)
      .map((node) => ({
        type: 'delete' as const,
        nodeId: node.id,
        depth: node.depth,
      }))

    return [...creates, ...updates, ...moves, ...deletes]
  })

  return {
    tree,
    blockMap,
    bounds: computed(() => layoutBounds.value),
    orderedNodes,
    validations,
    validationMap,
    hasUnsavedChanges,
    operationPlan,
    initializeFromSource,
    getNode,
    getAvailableBlocks,
    getAssignableBlocks,
    canPlaceBlockUnderParent,
    addNode,
    duplicateNode,
    createSnapshot,
    restoreSnapshot,
    exportForAi,
    markAiAltered,
    setDeletedState,
    updateTitle,
    updateSlug,
    updateBlock,
    setCollapsed,
    toggleDelete,
    moveNode,
  }
}
