<script setup lang="ts">
import { useThrottleFn } from '@vueuse/core'
import { toast } from 'vue-sonner'

import type { MentionItem } from '~/api/resources/ai'
import ContentCanvasHelpDialog from '~/components/content-wizard/ContentCanvasHelpDialog.vue'
import ContentWizardAiDock from '~/components/content-wizard/ContentWizardAiDock.vue'
import ContentWizardCanvas from '~/components/content-wizard/ContentWizardCanvas.vue'
import ContentWizardToolbar from '~/components/content-wizard/ContentWizardToolbar.vue'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { AvatarList } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Spinner } from '~/components/ui/spinner'
import {
  extractStreamingTreeOperations,
  parseTreeOperations,
  useAiContentTree,
  type TreeOperation,
} from '~/composables/useAiContentTree'
import {
  useContentCanvasCommands,
  type ContentCanvasHistoryEntry,
} from '~/composables/useContentCanvasCommands'
import { useContentWizardApply } from '~/composables/useContentWizardApply'
import { useContentWizardCollaboration } from '~/composables/useContentWizardCollaboration'
import { useContentWizardKeyboard } from '~/composables/useContentWizardKeyboard'
import { useContentWizardTree } from '~/composables/useContentWizardTree'
import {
  createContentDefaultsBlockLookup,
  hydrateContentWithSchema,
} from '~/composables/useSchemaDefaults'
import { aiErrorMessage } from '~/lib/aiErrors'
import { resolvePreferredCreateContentBlock } from '~/lib/content-children'
import {
  CONTENT_WIZARD_ROOT_ID,
  type ContentWizardAddPosition,
  type ContentWizardDraftTree,
  type ContentWizardEditableField,
  type ContentWizardSyncOperation,
} from '~/types/content-wizard'

type AiPreviewWarning = {
  id: string
  message: string
  nodeId?: string | null
  nodeLabel?: string | null
}

const route = useRoute()
const { t } = useI18n()
const { showAiError } = useAiErrorToast()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const spaceId = computed(() => route.params.space as string)
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canApplyCanvas = computed(() => access.hasAbility('content.manage'))

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)

const { useBlocksQuery } = useBlocks(spaceId)
const {
  data: blocksResponse,
  error: blocksError,
  isLoading: isBlocksLoading,
  refetch: refetchBlocks,
} = useBlocksQuery({ per_page: 1000 })

const { useContentMenuQuery } = useContentMenu(spaceId)
const {
  data: menuData,
  error: menuError,
  isLoading: isMenuLoading,
  refetch: refetchMenu,
} = useContentMenuQuery()

const blocks = computed(() => blocksResponse.value?.data || [])
// Resolves nested-block schemas when hydrating a template's content.
const blockLookup = computed(() => createContentDefaultsBlockLookup(blocks.value))
const treeApi = useContentWizardTree(blocks, menuData)
const { apply, applyError, invalidateContentQueries, isApplying } = useContentWizardApply(
  spaceId,
  treeApi
)
const {
  streamTreeInteraction,
  cancelStream,
  isStreaming: isAiStreaming,
} = useAiContentTree(spaceId)
const collaboration = useContentWizardCollaboration(spaceId)

const canvasRef = useTemplateRef<InstanceType<typeof ContentWizardCanvas>>('canvas')
const editingField = ref<ContentWizardEditableField | null>(null)
const editingNodeId = ref<string | null>(null)
const focusedNodeId = ref<string | null>(CONTENT_WIZARD_ROOT_ID)
const selectedNodeIds = ref<string[]>([])
const draggingNodeId = ref<string | null>(null)
const draggingNodeIds = ref<string[]>([])
const dropTargetId = ref<string | null>(null)
const rootDropActive = ref(false)
const isHelpDialogOpen = ref(false)
const initialized = ref(false)
const isTreeBooting = ref(false)
const aiStatus = ref<{
  message: string
  tone: 'info' | 'success' | 'error'
} | null>(null)
const aiPreviewSnapshot = ref<ContentWizardDraftTree | null>(null)
const aiResponseBuffer = ref('')
const aiWarnings = ref<AiPreviewWarning[]>([])
const pendingRemoteOperations: ContentWizardSyncOperation[] = []
const liveFieldTimers = new Map<string, ReturnType<typeof setTimeout>>()
const queuedRemoteOperations = new Map<string, ContentWizardSyncOperation>()
const editSessionSnapshots = new Map<string, ContentWizardDraftTree>()
let pendingInitializationFrame: number | null = null
let preserveSelectionOnNextFocus = false

const isLoading = computed(() => isBlocksLoading.value || isMenuLoading.value)
const loadError = computed(() => blocksError.value || menuError.value || null)
const draftNodes = computed(() => treeApi.tree.value.nodes)
const bounds = computed(() => treeApi.bounds.value)
const validationCount = computed(() => treeApi.validations.value.length)
const hasUnsavedChanges = computed(() => treeApi.hasUnsavedChanges.value)
const zoomPercent = computed(() => canvasRef.value?.zoomPercent ?? 100)
const isAiBusy = computed(() => isAiStreaming.value)
const draftMentionItems = computed<AiMentionItem[]>(() =>
  treeApi.orderedNodes.value
    .filter((node) => !node.isRootVirtual)
    .map((node) => ({
      id: node.id,
      label:
        node.title.trim() || node.blockName || (t('labels.contents.canvas.untitledNode') as string),
      type: 'draft-content',
      color: node.color,
      icon: node.icon ? `lucide:${node.icon}` : 'lucide:file',
    }))
)
const selectedNodeIdSet = computed(() => new Set(selectedNodeIds.value))

const broadcastCursorPosition = useThrottleFn(
  (payload: { x: number; y: number } | null) => {
    collaboration.broadcastCursor(payload)
  },
  100,
  true
)

const clearLiveFieldBroadcasts = () => {
  liveFieldTimers.forEach((timer) => clearTimeout(timer))
  liveFieldTimers.clear()
}

const createLiveFieldKey = (nodeId: string, field: 'title' | 'slug') => `${nodeId}:${field}`

const parseLiveFieldKey = (fieldKey: string) => {
  const separatorIndex = fieldKey.lastIndexOf(':')
  if (separatorIndex < 0) {
    return null
  }

  const nodeId = fieldKey.slice(0, separatorIndex)
  const field = fieldKey.slice(separatorIndex + 1)

  if (field !== 'title' && field !== 'slug') {
    return null
  }

  return {
    nodeId,
    field,
  }
}

const createEditSessionKey = (nodeId: string, field: ContentWizardEditableField) =>
  `${nodeId}:${field}`

const startEditSession = (nodeId: string, field: ContentWizardEditableField) => {
  const sessionKey = createEditSessionKey(nodeId, field)
  if (!editSessionSnapshots.has(sessionKey)) {
    editSessionSnapshots.set(sessionKey, treeApi.createSnapshot())
  }
}

const clearEditSession = (nodeId: string, field: ContentWizardEditableField) => {
  editSessionSnapshots.delete(createEditSessionKey(nodeId, field))
}

const getRemoteOperationKey = (operation: ContentWizardSyncOperation) =>
  `${operation.type}:${operation.nodeId}`

const flushLiveFieldBroadcast = (fieldKey: string) => {
  const parsedFieldKey = parseLiveFieldKey(fieldKey)
  if (!parsedFieldKey) {
    return
  }

  const { nodeId, field } = parsedFieldKey
  const node = treeApi.getNode(nodeId)

  const existingTimer = liveFieldTimers.get(fieldKey)
  if (existingTimer) {
    clearTimeout(existingTimer)
    liveFieldTimers.delete(fieldKey)
  }

  if (!node || node.isRootVirtual) {
    return
  }

  if (field === 'title') {
    collaboration.broadcastOperation({
      type: 'title',
      nodeId,
      value: node.title,
    })
    return
  }

  collaboration.broadcastOperation({
    type: 'slug',
    nodeId,
    value: node.slug,
  })
}

const scheduleLiveFieldBroadcast = (
  nodeId: string,
  field: 'title' | 'slug',
  debounceMs: number = 200
) => {
  const fieldKey = createLiveFieldKey(nodeId, field)
  const existingTimer = liveFieldTimers.get(fieldKey)

  if (existingTimer) {
    clearTimeout(existingTimer)
  }

  liveFieldTimers.set(
    fieldKey,
    setTimeout(() => {
      flushLiveFieldBroadcast(fieldKey)
    }, debounceMs)
  )
}

const queueRemoteOperation = (operation: ContentWizardSyncOperation) => {
  queuedRemoteOperations.set(getRemoteOperationKey(operation), operation)
}

const applyRemoteOperation = (operation: ContentWizardSyncOperation) => {
  if (!initialized.value) {
    pendingRemoteOperations.push(operation)
    return false
  }

  if (operation.type === 'add') {
    if (treeApi.getNode(operation.nodeId)) {
      return true
    }

    const block = blocks.value.find((item) => item.id === operation.blockId)
    if (!block) {
      return false
    }

    if (operation.parentId && !treeApi.getNode(operation.parentId)) {
      queueRemoteOperation(operation)
      return false
    }

    try {
      treeApi.addNode(block, {
        nodeId: operation.nodeId,
        parentId: operation.parentId,
        position: 'child',
        title: operation.title,
        slug: operation.slug,
        slugMode: operation.slugMode,
        content: operation.content,
      })
    } catch {
      queueRemoteOperation(operation)
      return false
    }

    return true
  }

  if (operation.type === 'replace-draft') {
    treeApi.restoreSnapshot(operation.snapshot)
    clearTransientState()
    const focusedNode = focusedNodeId.value ? treeApi.getNode(focusedNodeId.value) : null
    if (!focusedNode || !focusedNode.isVisible) {
      const fallbackNodeId = treeApi.getNode(operation.nodeId)?.id || CONTENT_WIZARD_ROOT_ID
      focusNode(fallbackNodeId)
    }
    return true
  }

  if (!treeApi.getNode(operation.nodeId)) {
    queueRemoteOperation(operation)
    return false
  }

  if (operation.type === 'title') {
    treeApi.updateTitle(operation.nodeId, operation.value)
    return true
  }

  if (operation.type === 'slug') {
    treeApi.updateSlug(operation.nodeId, operation.value)
    return true
  }

  if (operation.type === 'block') {
    treeApi.updateBlock(operation.nodeId, operation.blockId)
    return true
  }

  if (operation.type === 'move') {
    treeApi.moveNode(operation.nodeId, operation.parentId, operation.index)
    return true
  }

  if (operation.type === 'collapse-state') {
    treeApi.setCollapsed(operation.nodeId, operation.collapsed)
    if (focusedNodeId.value) {
      const focusedNode = treeApi.getNode(focusedNodeId.value)
      if (focusedNode && !focusedNode.isVisible) {
        focusNode(operation.nodeId)
      }
    }
    return true
  }

  treeApi.setDeletedState(operation.nodeId, operation.deleted)
  return true
}

const flushPendingRemoteOperations = () => {
  while (pendingRemoteOperations.length > 0) {
    const nextOperation = pendingRemoteOperations.shift()
    if (!nextOperation) {
      continue
    }

    applyRemoteOperation(nextOperation)
  }
}

const flushQueuedRemoteOperations = () => {
  let appliedOperation = true

  while (appliedOperation && queuedRemoteOperations.size > 0) {
    appliedOperation = false

    const queuedEntries = new Map(queuedRemoteOperations)

    for (const [operationKey, operation] of queuedEntries) {
      queuedRemoteOperations.delete(operationKey)

      if (applyRemoteOperation(operation)) {
        appliedOperation = true
        continue
      }

      const nextKey = getRemoteOperationKey(operation)
      if (!queuedRemoteOperations.has(nextKey)) {
        queuedRemoteOperations.set(nextKey, operation)
      }
    }
  }
}

function focusNode(nodeId: string) {
  focusedNodeId.value = nodeId
}

const setSelectedNodes = (nodeIds: string[]) => {
  selectedNodeIds.value = [...new Set(nodeIds)].filter((nodeId) => {
    const node = treeApi.getNode(nodeId)
    return !!node && !node.isRootVirtual
  })
}

const isDescendantOf = (nodeId: string, possibleAncestorId: string) => {
  let currentNode = treeApi.getNode(nodeId)

  while (currentNode?.parentId) {
    if (currentNode.parentId === possibleAncestorId) {
      return true
    }

    currentNode = treeApi.getNode(currentNode.parentId)
  }

  return false
}

const getSelectedDragRootIds = (originNodeId: string) => {
  const sourceIds =
    selectedNodeIdSet.value.has(originNodeId) && selectedNodeIds.value.length > 0
      ? selectedNodeIds.value
      : [originNodeId]

  const movableIds = sourceIds.filter((nodeId) => {
    const node = treeApi.getNode(nodeId)
    return !!node && !node.isRootVirtual
  })

  return movableIds.filter(
    (nodeId) =>
      !movableIds.some(
        (otherNodeId) => otherNodeId !== nodeId && isDescendantOf(nodeId, otherNodeId)
      )
  )
}

function clearTransientState() {
  editingField.value = null
  editingNodeId.value = null
  dropTargetId.value = null
}

const clearEditSessions = () => {
  editSessionSnapshots.clear()
}

const cancelPendingInitialization = () => {
  if (pendingInitializationFrame !== null) {
    cancelAnimationFrame(pendingInitializationFrame)
    pendingInitializationFrame = null
  }
}

const initializeCanvasTree = () => {
  cancelPendingInitialization()
  treeApi.initializeFromSource()
  history.clearHistory()
  clearEditSessions()
  focusedNodeId.value = CONTENT_WIZARD_ROOT_ID
  initialized.value = true
  isTreeBooting.value = false
  flushPendingRemoteOperations()
  flushQueuedRemoteOperations()
  nextTick(() => canvasRef.value?.fitToView())
}

const scheduleInitialCanvasInitialization = () => {
  if (initialized.value || isTreeBooting.value) {
    return
  }

  isTreeBooting.value = true
  cancelPendingInitialization()

  pendingInitializationFrame = requestAnimationFrame(() => {
    pendingInitializationFrame = requestAnimationFrame(() => {
      initializeCanvasTree()
    })
  })
}

const serializeHistorySnapshot = (snapshot: ContentWizardDraftTree) => {
  return JSON.stringify({
    rootId: snapshot.rootId,
    nodes: Object.fromEntries(
      Object.entries(snapshot.nodes).map(([nodeId, node]) => [
        nodeId,
        {
          id: node.id,
          backendId: node.backendId,
          parentId: node.parentId,
          childrenIds: node.childrenIds,
          blockId: node.blockId,
          blockType: node.blockType,
          blockName: node.blockName,
          title: node.title,
          slug: node.slug,
          slugMode: node.slugMode,
          icon: node.icon,
          color: node.color,
          isRootVirtual: node.isRootVirtual,
          canHaveChildren: node.canHaveChildren,
          isAiAltered: node.isAiAltered,
          isDeletedSelf: node.isDeletedSelf,
          original: node.original,
        },
      ])
    ),
  })
}

const mergeCurrentCollapsedState = (snapshot: ContentWizardDraftTree): ContentWizardDraftTree => ({
  rootId: snapshot.rootId,
  nodes: Object.fromEntries(
    Object.entries(snapshot.nodes).map(([nodeId, node]) => {
      const currentNode = treeApi.getNode(nodeId)

      return [
        nodeId,
        {
          ...node,
          childrenIds: [...node.childrenIds],
          layout: { ...node.layout },
          changes: { ...node.changes },
          validationState: {
            hasErrors: node.validationState.hasErrors,
            errors: node.validationState.errors.map((error) => ({ ...error })),
          },
          original: node.original ? { ...node.original } : null,
          isCollapsed: currentNode?.isCollapsed ?? node.isCollapsed,
        },
      ]
    })
  ),
})

const getSnapshotNodeDepth = (
  snapshot: ContentWizardDraftTree,
  nodeId: string,
  depth: number = 0
): number => {
  const node = snapshot.nodes[nodeId]
  if (!node || !node.parentId) {
    return depth
  }

  return getSnapshotNodeDepth(snapshot, node.parentId, depth + 1)
}

const buildAddSubtreeOperations = (
  snapshot: ContentWizardDraftTree,
  nodeId: string
): ContentWizardSyncOperation[] => {
  const node = snapshot.nodes[nodeId]
  if (!node || node.isRootVirtual) {
    return []
  }

  const operations: ContentWizardSyncOperation[] = [
    {
      type: 'add',
      nodeId: node.id,
      parentId: node.parentId,
      blockId: node.blockId,
      content: node.content,
      title: node.title,
      slug: node.slug,
      slugMode: node.slugMode,
    },
  ]

  if (node.isDeletedSelf) {
    operations.push({
      type: 'delete-state',
      nodeId: node.id,
      deleted: true,
    })
  }

  node.childrenIds.forEach((childId) => {
    operations.push(...buildAddSubtreeOperations(snapshot, childId))
  })

  return operations
}

const buildHistoryOperations = (
  fromSnapshot: ContentWizardDraftTree,
  toSnapshot: ContentWizardDraftTree
): ContentWizardSyncOperation[] => {
  const operations: ContentWizardSyncOperation[] = []
  const fromNodes = fromSnapshot.nodes
  const toNodes = toSnapshot.nodes
  const fromNodeIds = new Set(Object.keys(fromNodes))
  const toNodeIds = new Set(Object.keys(toNodes))

  const addedRootIds = [...toNodeIds]
    .filter((nodeId) => !fromNodeIds.has(nodeId) && nodeId !== CONTENT_WIZARD_ROOT_ID)
    .filter((nodeId) => {
      const parentId = toNodes[nodeId]?.parentId
      return !parentId || fromNodeIds.has(parentId) || !toNodeIds.has(parentId)
    })
    .sort(
      (left, right) =>
        getSnapshotNodeDepth(toSnapshot, left) - getSnapshotNodeDepth(toSnapshot, right)
    )

  addedRootIds.forEach((nodeId) => {
    operations.push(...buildAddSubtreeOperations(toSnapshot, nodeId))
  })

  const sharedNodeIds = [...toNodeIds]
    .filter((nodeId) => fromNodeIds.has(nodeId) && nodeId !== CONTENT_WIZARD_ROOT_ID)
    .sort(
      (left, right) =>
        getSnapshotNodeDepth(toSnapshot, left) - getSnapshotNodeDepth(toSnapshot, right)
    )

  sharedNodeIds.forEach((nodeId) => {
    const fromNode = fromNodes[nodeId]
    const toNode = toNodes[nodeId]
    if (!fromNode || !toNode) {
      return
    }

    const titleChanged = fromNode.title !== toNode.title
    const slugChanged = fromNode.slug !== toNode.slug
    const blockChanged = fromNode.blockId !== toNode.blockId
    const moveChanged =
      fromNode.parentId !== toNode.parentId || fromNode.position !== toNode.position
    const deletedChanged = fromNode.isDeletedSelf !== toNode.isDeletedSelf

    if (deletedChanged && !toNode.isDeletedSelf) {
      operations.push({
        type: 'delete-state',
        nodeId,
        deleted: false,
      })
    }

    if (fromNode.blockType !== 'single' && toNode.blockType === 'single') {
      if (moveChanged) {
        operations.push({
          type: 'move',
          nodeId,
          parentId: toNode.parentId,
          index: toNode.position,
        })
      }

      if (blockChanged) {
        operations.push({
          type: 'block',
          nodeId,
          blockId: toNode.blockId,
        })
      }
    } else {
      if (blockChanged) {
        operations.push({
          type: 'block',
          nodeId,
          blockId: toNode.blockId,
        })
      }

      if (moveChanged) {
        operations.push({
          type: 'move',
          nodeId,
          parentId: toNode.parentId,
          index: toNode.position,
        })
      }
    }

    if (titleChanged) {
      operations.push({
        type: 'title',
        nodeId,
        value: toNode.title,
      })
    }

    if (slugChanged) {
      operations.push({
        type: 'slug',
        nodeId,
        value: toNode.slug,
      })
    }

    if (deletedChanged && toNode.isDeletedSelf) {
      operations.push({
        type: 'delete-state',
        nodeId,
        deleted: true,
      })
    }
  })

  const removedRootIds = [...fromNodeIds]
    .filter((nodeId) => !toNodeIds.has(nodeId) && nodeId !== CONTENT_WIZARD_ROOT_ID)
    .filter((nodeId) => {
      const parentId = fromNodes[nodeId]?.parentId
      return !parentId || toNodeIds.has(parentId) || !fromNodeIds.has(parentId)
    })
    .sort(
      (left, right) =>
        getSnapshotNodeDepth(fromSnapshot, right) - getSnapshotNodeDepth(fromSnapshot, left)
    )

  removedRootIds.forEach((nodeId) => {
    operations.push({
      type: 'delete-state',
      nodeId,
      deleted: true,
    })
  })

  return operations
}

const broadcastOperations = (operations: ContentWizardSyncOperation[]) => {
  operations.forEach((operation) => {
    collaboration.broadcastOperation(operation)
  })
}

const broadcastHistoryEntry = (entry: ContentCanvasHistoryEntry, direction: 'undo' | 'redo') => {
  const operations =
    direction === 'undo'
      ? buildHistoryOperations(entry.after, entry.before)
      : buildHistoryOperations(entry.before, entry.after)

  broadcastOperations(operations)
}

const history = useContentCanvasCommands({
  createSnapshot: treeApi.createSnapshot,
  restoreSnapshot: (snapshot) => {
    treeApi.restoreSnapshot(mergeCurrentCollapsedState(snapshot))
    clearTransientState()
    const focusedNode = focusedNodeId.value ? treeApi.getNode(focusedNodeId.value) : null
    if (!focusedNode || !focusedNode.isVisible) {
      focusNode(CONTENT_WIZARD_ROOT_ID)
    }
  },
  onHistoryRestore: ({ entry, direction }) => {
    broadcastHistoryEntry(entry, direction)
  },
  serializeSnapshot: serializeHistorySnapshot,
})

const stopRemoteOperationListener = collaboration.onOperation((operation) => {
  history.clearHistory()
  clearEditSessions()
  applyRemoteOperation(operation)
  flushQueuedRemoteOperations()
})

watch(
  [menuData, blocks],
  ([menu, availableBlocks]) => {
    if (!menu || availableBlocks.length === 0 || initialized.value) {
      return
    }

    scheduleInitialCanvasInitialization()
  },
  { immediate: true }
)

onScopeDispose(() => {
  cancelPendingInitialization()
})

watch(
  focusedNodeId,
  (nodeId) => {
    collaboration.broadcastFocus(nodeId)
  },
  { immediate: true }
)

const handleCanvasFocusNode = (nodeId: string) => {
  focusNode(nodeId)

  if (preserveSelectionOnNextFocus) {
    preserveSelectionOnNextFocus = false
    return
  }

  if (nodeId === CONTENT_WIZARD_ROOT_ID) {
    setSelectedNodes([])
    return
  }

  setSelectedNodes([nodeId])
}

const handleNodePointerDown = ({ nodeId, event }: { nodeId: string; event: PointerEvent }) => {
  if (event.button !== 0) {
    return
  }

  const target = event.target as HTMLElement | null
  if (
    target?.closest(
      'input,textarea,[data-block-select],[data-add-menu],button:not([data-drag-handle])'
    )
  ) {
    return
  }

  if (nodeId === CONTENT_WIZARD_ROOT_ID) {
    preserveSelectionOnNextFocus = false
    setSelectedNodes([])
    return
  }

  if (event.metaKey || event.ctrlKey) {
    preserveSelectionOnNextFocus = true
    if (selectedNodeIdSet.value.has(nodeId)) {
      setSelectedNodes(selectedNodeIds.value.filter((selectedNodeId) => selectedNodeId !== nodeId))
    } else {
      setSelectedNodes([...selectedNodeIds.value, nodeId])
    }
    focusNode(nodeId)
    return
  }

  preserveSelectionOnNextFocus = false
  setSelectedNodes([nodeId])
  focusNode(nodeId)
}

const handleCanvasPointerDown = (event: PointerEvent) => {
  const target = event.target as HTMLElement | null
  if (
    target?.closest('[data-node-card], [data-shared-add-controls], [data-add-menu]') ||
    event.metaKey ||
    event.ctrlKey
  ) {
    return
  }

  preserveSelectionOnNextFocus = false
  setSelectedNodes([])
}

const clearAiStreamState = () => {
  aiResponseBuffer.value = ''
  aiPreviewSnapshot.value = null
}

const clearAiState = () => {
  clearAiStreamState()
  aiWarnings.value = []
}

const restoreAiSnapshot = () => {
  if (!aiPreviewSnapshot.value) {
    return
  }

  treeApi.restoreSnapshot(aiPreviewSnapshot.value)
  history.clearHistory()
  clearEditSessions()
}

const summarizeAiOperations = (operations: TreeOperation[]) => {
  const counts = operations.reduce(
    (summary, operation) => {
      if (operation.type === 'create') {
        summary.create += 1
      } else if (operation.type === 'move') {
        summary.move += 1
      } else if (operation.type === 'update' || operation.type === 'rename') {
        summary.update += 1
      } else if (operation.type === 'delete' || operation.type === 'remove') {
        summary.delete += 1
      } else if (operation.type === 'restore') {
        summary.restore += 1
      }

      return summary
    },
    { create: 0, move: 0, update: 0, delete: 0, restore: 0 }
  )

  const parts = [
    counts.create > 0
      ? (t('labels.contents.canvas.aiSummaryCreate', { count: counts.create }) as string)
      : null,
    counts.move > 0
      ? (t('labels.contents.canvas.aiSummaryMove', { count: counts.move }) as string)
      : null,
    counts.update > 0
      ? (t('labels.contents.canvas.aiSummaryUpdate', { count: counts.update }) as string)
      : null,
    counts.delete > 0
      ? (t('labels.contents.canvas.aiSummaryDelete', { count: counts.delete }) as string)
      : null,
    counts.restore > 0
      ? (t('labels.contents.canvas.aiSummaryRestore', { count: counts.restore }) as string)
      : null,
  ].filter((value): value is string => !!value)

  return parts.join(' • ')
}

const normalizeAiReference = (value: string | null | undefined) =>
  (value || '').trim().toLowerCase().replace(/^@+/, '')

const normalizeAiLookupKey = (value: string | null | undefined) =>
  normalizeAiReference(value)
    .replace(/^draft-content:/, '')
    .replace(/[^a-z0-9:-]+/g, '-')
    .replace(/^-+|-+$/g, '')

const createAiNodeReferenceMap = () => {
  const map = new Map<string, string>()

  for (const node of treeApi.orderedNodes.value) {
    const references = [
      node.id,
      `draft-content:${node.id}`,
      node.backendId,
      node.slug,
      node.title,
      node.blockName ? `${node.blockName} ${node.title}` : null,
    ]

    for (const reference of references) {
      const key = normalizeAiLookupKey(reference)
      if (key && !map.has(key)) {
        map.set(key, node.id)
      }
    }
  }

  return map
}

const resolveAiNodeId = (id: string | null | undefined, tempIdMap: Map<string, string>) => {
  const rawReference = normalizeAiReference(id)
  if (!rawReference) {
    return null
  }

  const directReference = rawReference.replace(/^draft-content:/, '')
  if (tempIdMap.has(rawReference)) {
    return tempIdMap.get(rawReference) || null
  }

  if (tempIdMap.has(directReference)) {
    return tempIdMap.get(directReference) || null
  }

  if (treeApi.getNode(directReference)) {
    return directReference
  }

  const referenceMap = createAiNodeReferenceMap()
  const resolvedReference = referenceMap.get(normalizeAiLookupKey(rawReference))
  return resolvedReference || directReference
}

const normalizeAiBlockReference = (value: string | null | undefined) =>
  (value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

const resolveAiBlock = (blockReference: string | null | undefined) => {
  if (!blockReference) {
    return null
  }

  const normalizedReference = normalizeAiBlockReference(blockReference)
  if (!normalizedReference) {
    return null
  }

  const scoredBlocks = blocks.value
    .map((block) => {
      const normalizedSlug = normalizeAiBlockReference(block.slug)
      const normalizedName = normalizeAiBlockReference(block.name)
      const singularReference = normalizedReference.endsWith('s')
        ? normalizedReference.slice(0, -1)
        : normalizedReference
      const singularSlug = normalizedSlug.endsWith('s')
        ? normalizedSlug.slice(0, -1)
        : normalizedSlug
      const singularName = normalizedName.endsWith('s')
        ? normalizedName.slice(0, -1)
        : normalizedName

      let score = 0

      if (block.id === blockReference) {
        score = 100
      } else if (normalizedSlug === normalizedReference) {
        score = 90
      } else if (normalizedName === normalizedReference) {
        score = 85
      } else if (singularSlug === singularReference || singularName === singularReference) {
        score = 80
      } else if (
        normalizedSlug.includes(normalizedReference) ||
        normalizedName.includes(normalizedReference)
      ) {
        score = 65
      } else if (
        normalizedReference.includes(normalizedSlug) ||
        normalizedReference.includes(normalizedName)
      ) {
        score = 55
      }

      return { block, score }
    })
    .filter((candidate) => candidate.score > 0)
    .sort((left, right) => right.score - left.score)

  if (scoredBlocks.length > 0) {
    return scoredBlocks[0]?.block || null
  }

  if (['page', 'pages'].includes(normalizedReference) && blocks.value.length === 1) {
    return blocks.value[0] || null
  }

  return null
}

const getAiNodeLabel = (nodeId: string | null | undefined) => {
  if (!nodeId) {
    return null
  }

  const node = treeApi.getNode(nodeId)
  if (!node) {
    return null
  }

  if (node.isRootVirtual) {
    return space.value?.name || (t('labels.contents.canvas.rootNodeTitle') as string)
  }

  return node.title.trim() || node.blockName || (t('labels.contents.canvas.untitledNode') as string)
}

const formatAiWarning = ({
  id,
  action,
  detail,
  nodeId,
  nodeLabel,
}: {
  id: string
  action: string
  detail: string
  nodeId?: string | null
  nodeLabel?: string | null
}): AiPreviewWarning => {
  const resolvedLabel = nodeLabel || getAiNodeLabel(nodeId)

  return {
    id,
    nodeId,
    nodeLabel: resolvedLabel,
    message: resolvedLabel ? `${action} "${resolvedLabel}": ${detail}` : `${action}: ${detail}`,
  }
}

const applyAiOperationsPreview = (operations: TreeOperation[]) => {
  if (!aiPreviewSnapshot.value) {
    return {
      alteredNodeIds: [] as string[],
      warnings: [] as AiPreviewWarning[],
    }
  }

  treeApi.restoreSnapshot(aiPreviewSnapshot.value)

  const tempIdMap = new Map<string, string>()
  const alteredNodeIds = new Set<string>()
  const warnings: AiPreviewWarning[] = []

  for (const [operationIndex, operation] of operations.entries()) {
    const warningId = `${operation.type}:${operationIndex}`

    if (operation.type === 'create') {
      if (!operation.block_id) {
        warnings.push(
          formatAiWarning({
            id: warningId,
            action: 'Create',
            detail: 'missing a block type.',
          })
        )
        continue
      }

      const block = resolveAiBlock(operation.block_id)
      if (!block) {
        warnings.push(
          formatAiWarning({
            id: warningId,
            action: 'Create',
            detail: `references an unavailable block (${operation.block_id}).`,
          })
        )
        continue
      }

      const parentId = resolveAiNodeId(operation.parent_id, tempIdMap)
      try {
        const createdNode = treeApi.addNode(block, {
          parentId,
          position: 'child',
          title: operation.name,
        })

        if (typeof operation.slug === 'string') {
          treeApi.updateSlug(createdNode.id, operation.slug)
        }

        if (operation.temp_id) {
          tempIdMap.set(operation.temp_id, createdNode.id)
        }

        alteredNodeIds.add(createdNode.id)
      } catch (error) {
        warnings.push(
          formatAiWarning({
            id: warningId,
            action: 'Create',
            detail: error instanceof Error ? error.message : 'could not be previewed.',
            nodeId: parentId,
            nodeLabel: parentId
              ? `${t('labels.contents.canvas.aiCreateUnder') as string} ${getAiNodeLabel(parentId) || parentId}`
              : (t('labels.contents.canvas.aiCreateAtRoot') as string),
          })
        )
      }

      continue
    }

    const targetNodeId = resolveAiNodeId(operation.id, tempIdMap)
    if (!targetNodeId) {
      warnings.push(
        formatAiWarning({
          id: warningId,
          action: operation.type[0].toUpperCase() + operation.type.slice(1),
          detail: operation.id
            ? `target node "${operation.id}" is missing from the current draft.`
            : 'is missing a target node.',
        })
      )
      continue
    }

    if (operation.type === 'move') {
      const parentId = resolveAiNodeId(operation.parent_id, tempIdMap)
      const result = treeApi.moveNode(targetNodeId, parentId, operation.position)
      if (!result.valid) {
        warnings.push(
          formatAiWarning({
            id: warningId,
            action: 'Move',
            detail: result.message || 'could not be previewed.',
            nodeId: targetNodeId,
          })
        )
        continue
      }

      alteredNodeIds.add(targetNodeId)
      continue
    }

    if (operation.type === 'update' || operation.type === 'rename') {
      if (typeof operation.name === 'string') {
        treeApi.updateTitle(targetNodeId, operation.name)
      }

      if (typeof operation.slug === 'string') {
        treeApi.updateSlug(targetNodeId, operation.slug)
      }

      if (typeof operation.block_id === 'string') {
        const result = treeApi.updateBlock(targetNodeId, operation.block_id)
        if (!result.valid) {
          warnings.push(
            formatAiWarning({
              id: warningId,
              action: operation.type === 'rename' ? 'Rename' : 'Update',
              detail: result.message || 'block change could not be previewed.',
              nodeId: targetNodeId,
            })
          )
          continue
        }
      }

      alteredNodeIds.add(targetNodeId)
      continue
    }

    if (operation.type === 'delete' || operation.type === 'remove') {
      const result = treeApi.setDeletedState(targetNodeId, true)
      if (!result.valid) {
        warnings.push(
          formatAiWarning({
            id: warningId,
            action: 'Delete',
            detail: result.message || 'could not be previewed.',
            nodeId: targetNodeId,
          })
        )
        continue
      }

      alteredNodeIds.add(targetNodeId)
      continue
    }

    if (operation.type === 'restore') {
      const result = treeApi.setDeletedState(targetNodeId, false)
      if (!result.valid) {
        warnings.push(
          formatAiWarning({
            id: warningId,
            action: 'Restore',
            detail: result.message || 'could not be previewed.',
            nodeId: targetNodeId,
          })
        )
        continue
      }

      alteredNodeIds.add(targetNodeId)
    }
  }

  treeApi.markAiAltered(alteredNodeIds)

  return {
    alteredNodeIds: [...alteredNodeIds],
    warnings,
  }
}

const canCreateChildAtNode = (nodeId: string) => {
  const node = treeApi.getNode(nodeId)
  if (!node || (!node.isRootVirtual && node.deletedReason)) {
    return false
  }

  return true
}

const getBottomBlocks = (nodeId: string) => {
  const node = treeApi.getNode(nodeId)
  if (!node || (!node.isRootVirtual && node.deletedReason)) {
    return []
  }

  return treeApi.getAvailableBlocks(node.isRootVirtual ? null : node.parentId, {
    excludeNodeId: node.isRootVirtual ? undefined : node.id,
  })
}

const getRightBlocks = (nodeId: string) => {
  const node = treeApi.getNode(nodeId)
  if (!node || node.isRootVirtual || node.deletedReason || !canCreateChildAtNode(nodeId)) {
    return []
  }

  return treeApi.getAvailableBlocks(node.id)
}
const getBlockOptions = (nodeId: string) => treeApi.getAssignableBlocks(nodeId)

const startEditing = (nodeId: string, field: ContentWizardEditableField, initialChar?: string) => {
  const node = treeApi.getNode(nodeId)
  if (!node || node.isRootVirtual || node.deletedReason) {
    return
  }

  focusNode(nodeId)
  startEditSession(nodeId, field)
  editingNodeId.value = nodeId
  editingField.value = field

  if (field === 'title' && initialChar) {
    treeApi.updateTitle(nodeId, initialChar)
    scheduleLiveFieldBroadcast(nodeId, 'title')
  }
}

const broadcastCreatedSubtree = (nodeId: string) => {
  const node = treeApi.getNode(nodeId)
  if (!node || node.isRootVirtual) {
    return
  }

  collaboration.broadcastOperation({
    type: 'add',
    nodeId: node.id,
    parentId: node.parentId,
    blockId: node.blockId,
    content: node.content,
    title: node.title,
    slug: node.slug,
    slugMode: node.slugMode,
  })

  if (node.deletedReason === 'self') {
    collaboration.broadcastOperation({
      type: 'delete-state',
      nodeId: node.id,
      deleted: true,
    })
  }

  node.childrenIds.forEach((childId) => {
    broadcastCreatedSubtree(childId)
  })
}

const resolveAddContext = (nodeId: string, position: ContentWizardAddPosition) => {
  const node = treeApi.getNode(nodeId)
  if (!node) {
    return null
  }

  const resolvedPosition: ContentWizardAddPosition = node.isRootVirtual ? 'child' : position
  const availableBlocks =
    node.isRootVirtual || resolvedPosition === 'sibling'
      ? getBottomBlocks(nodeId)
      : getRightBlocks(nodeId)
  const targetParentId =
    resolvedPosition === 'child'
      ? node.isRootVirtual
        ? null
        : node.id
      : node.isRootVirtual
        ? null
        : node.parentId
  const targetParent = targetParentId ? treeApi.getNode(targetParentId) : null
  const preferredBlockId = resolvePreferredCreateContentBlock({
    availableBlocks,
    parentSettings: targetParent?.settings,
    spaceDefaultBlockId: space.value?.settings?.default_block,
  })
  const preferredBlock =
    availableBlocks.find((candidate) => candidate.id === preferredBlockId) || null

  return {
    node,
    resolvedPosition,
    targetParentId,
    availableBlocks,
    preferredBlock,
  }
}

const createNodeFromContext = (
  nodeId: string,
  position: ContentWizardAddPosition,
  block?: BlockResource,
  template?: BlockTemplate | null
) => {
  const context = resolveAddContext(nodeId, position)
  if (!context) {
    return false
  }

  const { node, resolvedPosition, targetParentId, availableBlocks, preferredBlock } = context
  const selectedBlock = block || preferredBlock

  if (!selectedBlock || !availableBlocks.some((candidate) => candidate.id === selectedBlock.id)) {
    toast.error(t('labels.contents.canvas.noBlocksAvailable'))
    return false
  }

  // Same hydration the content tree's create dialog runs, so a template yields
  // the same entry whichever way it was added.
  const templateContent =
    template && selectedBlock.schema
      ? hydrateContentWithSchema(selectedBlock.schema, template.content || {}, blockLookup.value)
      : undefined

  const commandResult = history.executeCommand({
    label: 'add-node',
    execute: () => {
      const createdNode = treeApi.addNode(selectedBlock, {
        parentId: targetParentId,
        position: resolvedPosition,
        referenceNodeId: node.isRootVirtual ? null : node.id,
        content: templateContent,
      })

      if (resolvedPosition === 'child' && !node.isRootVirtual && node.isCollapsed) {
        treeApi.setCollapsed(node.id, false)
      }

      return createdNode
    },
    onCommitted: ({ result }) => {
      broadcastCreatedSubtree(result.id)
    },
  })

  if (!commandResult.changed) {
    return false
  }

  focusNode(commandResult.result.id)
  nextTick(() => {
    startEditing(commandResult.result.id, 'title')
  })

  return true
}

const createNodeFromPreferredBlock = (nodeId: string, position: ContentWizardAddPosition) =>
  createNodeFromContext(nodeId, position)

const openAddMenu = (nodeId: string, position: ContentWizardAddPosition) => {
  const context = resolveAddContext(nodeId, position)
  if (!context) {
    return false
  }

  const { availableBlocks, resolvedPosition } = context

  if (availableBlocks.length === 0) {
    toast.error(t('labels.contents.canvas.noBlocksAvailable'))
    return false
  }

  const opened = canvasRef.value?.openNodeAddMenu(nodeId, resolvedPosition)
  if (!opened) {
    toast.error(t('labels.contents.canvas.noBlocksAvailable'))
    return false
  }

  return true
}

function handleToggleDelete(nodeId: string) {
  const node = treeApi.getNode(nodeId)
  if (!node || node.isRootVirtual) {
    return
  }

  const parent = treeApi.getNode(node.parentId)
  const siblings = parent?.childrenIds || []
  const nodeIndex = siblings.indexOf(nodeId)
  const fallbackNodeId =
    nodeIndex > 0 ? siblings[nodeIndex - 1] : node.parentId || CONTENT_WIZARD_ROOT_ID
  const removesDraftNode = !node.backendId
  const nextDeletedState = node.deletedReason !== 'self'

  history.executeCommand({
    label: 'toggle-delete',
    execute: () => treeApi.toggleDelete(nodeId),
    onCommitted: () => {
      collaboration.broadcastOperation({
        type: 'delete-state',
        nodeId,
        deleted: nextDeletedState,
      })
    },
  })
  clearTransientState()
  clearEditSessions()
  focusNode(removesDraftNode ? fallbackNodeId : nodeId)
}

const { handleKeydown } = useContentWizardKeyboard({
  getNode: treeApi.getNode,
  focusNode,
  createNodeFromPreferredBlock,
  openAddMenu,
  toggleDelete: handleToggleDelete,
  startEditing,
  clearTransientState,
})

const handleCommitTitle = (nodeId: string, value: string) => {
  treeApi.updateTitle(nodeId, value)
  scheduleLiveFieldBroadcast(nodeId, 'title')
  focusNode(nodeId)
}

const handleCommitSlug = (nodeId: string, value: string) => {
  treeApi.updateSlug(nodeId, value)
  scheduleLiveFieldBroadcast(nodeId, 'slug')
  focusNode(nodeId)
}

const handleTitleCommitEnd = (nodeId: string, value: string) => {
  const before = editSessionSnapshots.get(createEditSessionKey(nodeId, 'title'))
  treeApi.updateTitle(nodeId, value)
  flushLiveFieldBroadcast(`${nodeId}:title`)
  if (before) {
    history.recordSnapshotChange({
      label: 'update-title',
      before,
      after: treeApi.createSnapshot(),
    })
  }
  clearEditSession(nodeId, 'title')
  clearTransientState()
  focusNode(nodeId)
}

const handleSlugCommitEnd = (nodeId: string, value: string) => {
  const before = editSessionSnapshots.get(createEditSessionKey(nodeId, 'slug'))
  treeApi.updateSlug(nodeId, value)
  flushLiveFieldBroadcast(`${nodeId}:slug`)
  if (before) {
    history.recordSnapshotChange({
      label: 'update-slug',
      before,
      after: treeApi.createSnapshot(),
    })
  }
  clearEditSession(nodeId, 'slug')
  clearTransientState()
  focusNode(nodeId)
}

const handleBlockUpdate = (nodeId: string, blockId: string) => {
  const commandResult = history.executeCommand({
    label: 'update-block',
    execute: () => treeApi.updateBlock(nodeId, blockId),
    onCommitted: () => {
      collaboration.broadcastOperation({
        type: 'block',
        nodeId,
        blockId,
      })
    },
  })
  const result = commandResult.result
  if (!result.valid && result.message) {
    toast.error(result.message)
    return
  }
}

const handleToggleCollapse = (nodeId: string) => {
  const node = treeApi.getNode(nodeId)
  if (!node || node.isRootVirtual) {
    return
  }

  const nextCollapsed = !node.isCollapsed
  const result = treeApi.setCollapsed(nodeId, nextCollapsed)
  if (!result.valid && result.message) {
    toast.error(result.message)
    return
  }

  collaboration.broadcastOperation({
    type: 'collapse-state',
    nodeId,
    collapsed: nextCollapsed,
  })

  if (nextCollapsed && focusedNodeId.value) {
    const focusedNode = treeApi.getNode(focusedNodeId.value)
    if (focusedNode && !focusedNode.isVisible) {
      focusNode(nodeId)
    }
  }
}

const createDragPreviewElement = (draggedNodeIds: string[]) => {
  const preview = document.createElement('div')
  preview.className =
    'pointer-events-none fixed left-0 top-0 z-[9999] flex max-w-[320px] flex-col gap-2 opacity-95'
  preview.style.transform = 'translate(-10000px, -10000px)'

  draggedNodeIds.slice(0, 3).forEach((draggedNodeId, index) => {
    const source = document.querySelector<HTMLElement>(
      `[data-node-card-id="${CSS.escape(draggedNodeId)}"]`
    )
    if (!source) {
      return
    }

    const clone = source.cloneNode(true) as HTMLElement
    clone.style.position = 'relative'
    clone.style.left = '0'
    clone.style.top = '0'
    clone.style.transform = `translate(${index * 10}px, 0)`
    clone.style.width = `${source.offsetWidth}px`
    clone.style.height = `${source.offsetHeight}px`
    clone.style.pointerEvents = 'none'
    preview.appendChild(clone)
  })

  if (draggedNodeIds.length > 1) {
    const badge = document.createElement('div')
    badge.className =
      'ml-auto rounded-full bg-primary px-2 py-1 text-xs font-semibold text-primary-foreground shadow-sm'
    badge.textContent = `${draggedNodeIds.length}`
    preview.appendChild(badge)
  }

  if (!preview.childElementCount) {
    return null
  }

  document.body.appendChild(preview)
  return preview
}

const handleDragStart = (nodeId: string, event: DragEvent) => {
  const draggedNodeIds = getSelectedDragRootIds(nodeId)
  if (draggedNodeIds.length === 0) {
    event.preventDefault()
    return
  }

  setSelectedNodes(draggedNodeIds)
  draggingNodeIds.value = draggedNodeIds
  draggingNodeId.value = draggedNodeIds[0] || null
  rootDropActive.value = true
  dropTargetId.value = null
  event.dataTransfer?.setData('text/plain', draggedNodeIds.join(','))
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'copyMove'
    const preview = createDragPreviewElement(draggedNodeIds)
    if (preview) {
      event.dataTransfer.setDragImage(preview, 28, 24)
      requestAnimationFrame(() => {
        preview.remove()
      })
    }
  }
}

const handleDragOverNode = ({ nodeId }: { nodeId: string; event: DragEvent }) => {
  if (nodeId === CONTENT_WIZARD_ROOT_ID) {
    handleDragOverRoot()
    return
  }

  if (!draggingNodeIds.value.length || draggingNodeIds.value.includes(nodeId)) {
    return
  }

  dropTargetId.value = nodeId
  rootDropActive.value = false
}

const handleDragOverRoot = () => {
  if (!draggingNodeIds.value.length) {
    return
  }

  dropTargetId.value = null
  rootDropActive.value = true
}

const handleDragEnd = () => {
  draggingNodeId.value = null
  draggingNodeIds.value = []
  dropTargetId.value = null
  rootDropActive.value = false
}

const isCopyDrop = (event: DragEvent) =>
  event.altKey || event.ctrlKey || event.metaKey || event.dataTransfer?.dropEffect === 'copy'

type DragDropCommandResult = {
  valid: boolean
  message?: string
  createdNodeId?: string
  createdNodeIds?: string[]
}

const executeDragDrop = (
  draggedNodeIds: string[],
  copyMode: boolean,
  targetParentId: string | null
): DragDropCommandResult => {
  const beforeSnapshot = treeApi.createSnapshot()
  const createdNodeIds: string[] = []

  for (const draggedNodeId of draggedNodeIds) {
    const result = copyMode
      ? treeApi.duplicateNode(draggedNodeId, targetParentId)
      : treeApi.moveNode(draggedNodeId, targetParentId)

    if (!result.valid) {
      treeApi.restoreSnapshot(beforeSnapshot)
      return result
    }

    if ('createdNodeId' in result && typeof result.createdNodeId === 'string') {
      createdNodeIds.push(result.createdNodeId)
    }
  }

  return {
    valid: true,
    createdNodeId: createdNodeIds[0],
    createdNodeIds,
  }
}

const getDropFocusNodeId = (
  draggedNodeIds: string[],
  result: DragDropCommandResult,
  copyMode: boolean
) => {
  if (copyMode) {
    return typeof result.createdNodeId === 'string' ? result.createdNodeId : draggedNodeIds[0]
  }

  return draggedNodeIds[0]
}

const handleDropOnNode = ({ nodeId, event }: { nodeId: string; event: DragEvent }) => {
  if (nodeId === CONTENT_WIZARD_ROOT_ID) {
    handleDropOnRoot(event)
    return
  }

  if (!draggingNodeIds.value.length) {
    return
  }

  const draggedNodeIds = [...draggingNodeIds.value]
  if (draggedNodeIds.includes(nodeId)) {
    handleDragEnd()
    return
  }

  const copyMode = isCopyDrop(event)
  const commandResult = history.executeCommand({
    label: copyMode ? 'duplicate-subtree' : 'move-node',
    execute: () => executeDragDrop(draggedNodeIds, copyMode, nodeId),
    onCommitted: ({ result }) => {
      if (copyMode && result.valid) {
        result.createdNodeIds?.forEach((createdNodeId) => {
          broadcastCreatedSubtree(createdNodeId)
        })
        return
      }

      if (!copyMode && result.valid) {
        draggedNodeIds.forEach((draggedNodeId) => {
          const draggedNode = treeApi.getNode(draggedNodeId)
          collaboration.broadcastOperation({
            type: 'move',
            nodeId: draggedNodeId,
            parentId: draggedNode?.parentId ?? null,
          })
        })
      }
    },
  })
  const result = commandResult.result
  if (!result.valid && result.message) {
    toast.error(result.message)
  }

  const nextSelectedNodeIds =
    copyMode && result.valid
      ? result.createdNodeIds || []
      : result.valid
        ? draggedNodeIds
        : selectedNodeIds.value

  setSelectedNodes(nextSelectedNodeIds)
  preserveSelectionOnNextFocus = nextSelectedNodeIds.length > 1
  focusNode(getDropFocusNodeId(draggedNodeIds, result, copyMode))
  handleDragEnd()
}

const handleDropOnRoot = (event: DragEvent) => {
  if (!draggingNodeIds.value.length) {
    return
  }

  const draggedNodeIds = [...draggingNodeIds.value]
  const copyMode = isCopyDrop(event)
  const commandResult = history.executeCommand({
    label: copyMode ? 'duplicate-subtree-root' : 'move-node-root',
    execute: () => executeDragDrop(draggedNodeIds, copyMode, null),
    onCommitted: ({ result }) => {
      if (copyMode && result.valid) {
        result.createdNodeIds?.forEach((createdNodeId) => {
          broadcastCreatedSubtree(createdNodeId)
        })
        return
      }

      if (!copyMode && result.valid) {
        draggedNodeIds.forEach((draggedNodeId) => {
          collaboration.broadcastOperation({
            type: 'move',
            nodeId: draggedNodeId,
            parentId: null,
          })
        })
      }
    },
  })
  const result = commandResult.result
  if (!result.valid && result.message) {
    toast.error(result.message)
  }

  const nextSelectedNodeIds =
    copyMode && result.valid
      ? result.createdNodeIds || []
      : result.valid
        ? draggedNodeIds
        : selectedNodeIds.value

  setSelectedNodes(nextSelectedNodeIds)
  preserveSelectionOnNextFocus = nextSelectedNodeIds.length > 1
  focusNode(getDropFocusNodeId(draggedNodeIds, result, copyMode))
  handleDragEnd()
}

const updateAiPreview = (operations: TreeOperation[], isFinal: boolean = false) => {
  if (!aiPreviewSnapshot.value) {
    return
  }

  const { warnings } = applyAiOperationsPreview(operations)
  aiWarnings.value = warnings
  const summary = summarizeAiOperations(operations)

  if (warnings.length > 0) {
    aiStatus.value = isFinal
      ? null
      : {
          message:
            summary.length > 0
              ? `${summary} • ${t('labels.contents.canvas.aiWarnings', { count: warnings.length })}`
              : (t('labels.contents.canvas.aiWarningsOnly', { count: warnings.length }) as string),
          tone: 'error',
        }
    return
  }

  aiStatus.value = {
    message:
      operations.length === 0
        ? isFinal
          ? ''
          : (t('labels.contents.canvas.aiGenerating') as string)
        : isFinal
          ? ''
          : `${t('labels.contents.canvas.aiStreamingProgress', { count: operations.length }) as string} • ${summary}`,
    tone: 'info',
  }

  if (isFinal) {
    aiStatus.value = null
  }
}

const handleAiSubmit = async ({
  prompt,
  configId,
  mentions,
}: {
  prompt: string
  configId: string | null
  mentions: MentionItem[]
}) => {
  clearTransientState()
  history.clearHistory()
  clearEditSessions()
  cancelStream()
  restoreAiSnapshot()
  clearAiState()

  aiPreviewSnapshot.value = treeApi.createSnapshot()
  aiResponseBuffer.value = ''
  aiStatus.value = {
    message: t('labels.contents.canvas.aiGenerating') as string,
    tone: 'info',
  }

  await streamTreeInteraction(
    {
      prompt,
      tree: treeApi.exportForAi(),
      config_id: configId,
      mentions,
    },
    {
      onStatus: (message) => {
        aiStatus.value = { message, tone: 'info' }
      },
      onDelta: (chunk) => {
        aiResponseBuffer.value += chunk
        const nextPartial = extractStreamingTreeOperations(aiResponseBuffer.value)
        if (nextPartial.length > 0) {
          updateAiPreview(nextPartial)
        }
      },
      onDone: (content) => {
        const parsed = parseTreeOperations(content)
        if (!parsed) {
          restoreAiSnapshot()
          aiStatus.value = {
            message: t('labels.contents.canvas.aiParseError') as string,
            tone: 'error',
          }
          clearAiStreamState()
          return
        }

        const beforeSnapshot = aiPreviewSnapshot.value
        updateAiPreview(parsed.operations, true)
        if (beforeSnapshot) {
          const afterSnapshot = treeApi.createSnapshot()
          broadcastOperations(buildHistoryOperations(beforeSnapshot, afterSnapshot))
        }
        clearAiStreamState()
      },
      onError: (message, reason) => {
        restoreAiSnapshot()
        aiStatus.value = { message: aiErrorMessage(t, reason, message), tone: 'error' }
        clearAiStreamState()
        if (reason === 'plan_excluded') {
          showAiError(reason, message)
        }
      },
    }
  )
}

const handleAiCancel = () => {
  cancelStream()
  restoreAiSnapshot()
  aiStatus.value = {
    message: t('labels.contents.canvas.aiCanceled') as string,
    tone: 'info',
  }
  clearAiState()
}

const handleFocusAiWarning = (warning: AiPreviewWarning) => {
  if (!warning.nodeId) {
    return
  }

  const node = treeApi.getNode(warning.nodeId)
  if (!node) {
    return
  }

  clearTransientState()
  focusNode(node.id)
  canvasRef.value?.centerNode(node.id)
}

const reloadFromServer = async () => {
  cancelStream()
  cancelPendingInitialization()
  clearAiState()
  clearLiveFieldBroadcasts()
  clearEditSessions()
  history.clearHistory()
  await Promise.all([invalidateContentQueries(), refetchMenu(), refetchBlocks()])
  initializeCanvasTree()
  clearTransientState()
  aiStatus.value = null
  focusNode(CONTENT_WIZARD_ROOT_ID)
}

const handleDiscard = async () => {
  if (
    hasUnsavedChanges.value &&
    !(await alert.confirm(t('labels.contents.canvas.discardConfirmMessage')))
  ) {
    return
  }

  await reloadFromServer()
}

const handleApply = async () => {
  const result = await apply()
  if (!result.success) {
    if (result.error) {
      toast.error(result.error)
    }
    return
  }

  await reloadFromServer()
  toast.success(
    t('labels.contents.canvas.applySuccess', { count: result.operations.length }) as string
  )
}

const handleUndo = () => {
  clearEditSessions()
  clearTransientState()
  history.undo()
}

const handleRedo = () => {
  clearEditSessions()
  clearTransientState()
  history.redo()
}

const handleGlobalKeydown = (event: KeyboardEvent) => {
  const target = event.target as HTMLElement | null
  if (target?.closest('input,textarea,[contenteditable="true"]')) {
    return
  }

  if (!(event.metaKey || event.ctrlKey) || event.altKey) {
    return
  }

  if (event.key.toLowerCase() === 'z') {
    event.preventDefault()
    if (event.shiftKey) {
      handleRedo()
      return
    }

    handleUndo()
  }

  if (event.key.toLowerCase() === 'y') {
    event.preventDefault()
    handleRedo()
  }
}

useSeoMeta({
  title: computed(() => t('labels.contents.canvas.title')),
})

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleGlobalKeydown)
  clearLiveFieldBroadcasts()
  clearEditSessions()
  stopRemoteOperationListener()
})
</script>

<template>
  <div class="flex min-h-0 w-full flex-1 flex-col bg-background">
    <Teleport
      defer
      to="#appHeader"
    >
      <div class="min-w-0">
        <h1 class="flex items-center gap-2">
          <span class="text-lg font-semibold text-primary">{{
            $t('labels.contents.canvas.title')
          }}</span>
          <Badge>Beta</Badge>
        </h1>
      </div>
    </Teleport>

    <Teleport
      defer
      to="#appHeaderActions"
    >
      <div class="flex items-center gap-2">
        <AvatarList
          v-if="collaboration.collaborators.value.length > 0"
          :users="collaboration.collaborators.value"
          :max="4"
          tooltip-side="bottom"
        />
        <Button
          variant="outline"
          :disabled="isApplying || !hasUnsavedChanges"
          @click="handleDiscard"
        >
          {{ $t('labels.contents.canvas.discard') }}
        </Button>
        <Button
          v-if="canApplyCanvas"
          variant="primary"
          :loading="isApplying"
          :disabled="validationCount > 0 || !hasUnsavedChanges"
          @click="handleApply"
        >
          {{
            isApplying ? $t('labels.contents.canvas.applying') : $t('labels.contents.canvas.apply')
          }}
        </Button>
      </div>
    </Teleport>

    <main class="relative min-h-0 flex-1 overflow-hidden">
      <div
        v-if="isLoading || isTreeBooting"
        class="flex h-full items-center justify-center"
      >
        <div
          class="flex items-center gap-3 rounded-2xl border border-border bg-background px-4 py-3 shadow-soft"
        >
          <Spinner class="text-primary" />
          <span class="font-semibold">{{ $t('labels.loading') }}</span>
        </div>
      </div>

      <div
        v-else-if="loadError"
        class="mx-auto flex h-full w-full max-w-4xl items-center justify-center p-6"
      >
        <Alert
          icon="lucide:triangle-alert"
          color="destructive"
          class="rounded-2xl"
        >
          {{ loadError.message }}
        </Alert>
      </div>

      <template v-else>
        <ContentWizardToolbar
          :validation-count="validationCount"
          :zoom-percent="zoomPercent"
          :can-undo="history.canUndo.value"
          :can-redo="history.canRedo.value"
          :apply-error="applyError"
          @reload="reloadFromServer"
          @help="isHelpDialogOpen = true"
          @undo="handleUndo"
          @redo="handleRedo"
          @zoom-reset="canvasRef?.setZoom100()"
          @zoom-in="canvasRef?.zoomIn()"
          @zoom-out="canvasRef?.zoomOut()"
          @fit="canvasRef?.fitToView()"
        />

        <ContentWizardCanvas
          ref="canvas"
          :nodes="draftNodes"
          :bounds="bounds"
          :space-id="spaceId"
          :can-mutate="canApplyCanvas"
          :root-title="space?.name || $t('labels.contents.canvas.rootNodeTitle')"
          :focused-node-id="focusedNodeId"
          :selected-node-ids="selectedNodeIds"
          :editing-node-id="editingNodeId"
          :editing-field="editingField"
          :drop-target-id="dropTargetId"
          :root-drop-active="rootDropActive"
          :remote-focus-users-by-node-id="collaboration.focusedUsersByNodeId.value"
          :remote-cursors="collaboration.visibleRemoteCursors.value"
          :get-bottom-blocks="getBottomBlocks"
          :get-right-blocks="getRightBlocks"
          :get-block-options="getBlockOptions"
          @canvas-pointerdown="handleCanvasPointerDown"
          @node-pointerdown="handleNodePointerDown"
          @focus-node="handleCanvasFocusNode"
          @node-keydown="handleKeydown($event.event, $event.nodeId)"
          @start-edit="startEditing($event.nodeId, $event.field, $event.initialChar)"
          @cursor-move="broadcastCursorPosition"
          @input-title="handleCommitTitle($event.nodeId, $event.value)"
          @input-slug="handleCommitSlug($event.nodeId, $event.value)"
          @commit-title="handleTitleCommitEnd($event.nodeId, $event.value)"
          @commit-slug="handleSlugCommitEnd($event.nodeId, $event.value)"
          @update-block="handleBlockUpdate($event.nodeId, $event.blockId)"
          @toggle-collapse="handleToggleCollapse($event)"
          @toggle-delete="handleToggleDelete($event)"
          @add-node="
            createNodeFromContext($event.nodeId, $event.position, $event.block, $event.template)
          "
          @dragstart="handleDragStart($event.nodeId, $event.event)"
          @dragend="handleDragEnd"
          @dragover-node="handleDragOverNode"
          @dragover-root="handleDragOverRoot"
          @drop-on-node="handleDropOnNode"
          @drop-on-root="handleDropOnRoot"
        />

        <ContentWizardAiDock
          :space-id="spaceId"
          :loading="isAiBusy"
          :status-message="aiStatus?.message"
          :status-tone="aiStatus?.tone || 'info'"
          :warnings="aiWarnings"
          :draft-mention-items="draftMentionItems"
          @send="handleAiSubmit"
          @cancel="handleAiCancel"
          @focus-warning="handleFocusAiWarning"
        />

        <ContentCanvasHelpDialog v-model:open="isHelpDialogOpen" />
      </template>
    </main>
  </div>
</template>
