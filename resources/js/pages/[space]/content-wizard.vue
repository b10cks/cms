<script setup lang="ts">
import type { MentionItem } from '~/api/resources/ai'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import { RouterLink } from 'vue-router'
import { toast } from 'vue-sonner'

import ContentWizardAiDock from '~/components/content-wizard/ContentWizardAiDock.vue'
import ContentWizardCanvas from '~/components/content-wizard/ContentWizardCanvas.vue'
import ContentWizardToolbar from '~/components/content-wizard/ContentWizardToolbar.vue'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  extractStreamingTreeOperations,
  parseTreeOperations,
  useAiContentTree,
  type TreeOperation,
} from '~/composables/useAiContentTree'
import { useContentWizardApply } from '~/composables/useContentWizardApply'
import { useContentWizardKeyboard } from '~/composables/useContentWizardKeyboard'
import { useContentWizardTree } from '~/composables/useContentWizardTree'
import {
  CONTENT_WIZARD_ROOT_ID,
  type ContentWizardAddPosition,
  type ContentWizardDraftTree,
  type ContentWizardEditableField,
} from '~/types/content-wizard'

type AiPreviewWarning = {
  id: string
  message: string
  nodeId?: string | null
  nodeLabel?: string | null
}

const route = useRoute()
const { t } = useI18n()
const { alert } = useAlertDialog()
const spaceId = computed(() => route.params.space as string)


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
const treeApi = useContentWizardTree(blocks, menuData)
const { apply, applyError, invalidateContentQueries, isApplying } = useContentWizardApply(
  spaceId,
  treeApi
)
const { streamTreeInteraction, cancelStream, isStreaming: isAiStreaming } = useAiContentTree(spaceId)


const canvasRef = useTemplateRef<InstanceType<typeof ContentWizardCanvas>>('canvas')
const editingField = ref<ContentWizardEditableField | null>(null)
const editingNodeId = ref<string | null>(null)
const focusedNodeId = ref<string | null>(CONTENT_WIZARD_ROOT_ID)
const draggingNodeId = ref<string | null>(null)
const dropTargetId = ref<string | null>(null)
const rootDropActive = ref(false)
const initialized = ref(false)
const aiStatus = ref<{
  message: string
  tone: 'info' | 'success' | 'error'
} | null>(null)
const aiPreviewSnapshot = ref<ContentWizardDraftTree | null>(null)
const aiResponseBuffer = ref('')
const aiWarnings = ref<AiPreviewWarning[]>([])


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
      label: node.title.trim() || node.blockName || (t('labels.contents.wizard.untitledNode') as string),
      type: 'draft-content',
      color: node.color,
      icon: node.icon ? `lucide:${node.icon}` : 'lucide:file',
    }))
)


watch(
  [menuData, blocks],
  ([menu, availableBlocks]) => {
    if (!menu || availableBlocks.length === 0 || initialized.value) {
      return
    }

    treeApi.initializeFromSource()
    focusedNodeId.value = CONTENT_WIZARD_ROOT_ID
    initialized.value = true
    nextTick(() => canvasRef.value?.fitToView())
  },
  { immediate: true }
)


const focusNode = (nodeId: string) => {
  focusedNodeId.value = nodeId
}


const clearTransientState = () => {
  editingField.value = null
  editingNodeId.value = null
  dropTargetId.value = null
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
      ? (t('labels.contents.wizard.aiSummaryCreate', { count: counts.create }) as string)
      : null,
    counts.move > 0
      ? (t('labels.contents.wizard.aiSummaryMove', { count: counts.move }) as string)
      : null,
    counts.update > 0
      ? (t('labels.contents.wizard.aiSummaryUpdate', { count: counts.update }) as string)
      : null,
    counts.delete > 0
      ? (t('labels.contents.wizard.aiSummaryDelete', { count: counts.delete }) as string)
      : null,
    counts.restore > 0
      ? (t('labels.contents.wizard.aiSummaryRestore', { count: counts.restore }) as string)
      : null,
  ].filter((value): value is string => !!value)

  return parts.join(' • ')
}

const normalizeAiReference = (value: string | null | undefined) =>
  (value || '')
    .trim()
    .toLowerCase()
    .replace(/^@+/, '')

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
      const singularSlug = normalizedSlug.endsWith('s') ? normalizedSlug.slice(0, -1) : normalizedSlug
      const singularName = normalizedName.endsWith('s') ? normalizedName.slice(0, -1) : normalizedName

      let score = 0

      if (block.id === blockReference) {
        score = 100
      } else if (normalizedSlug === normalizedReference) {
        score = 90
      } else if (normalizedName === normalizedReference) {
        score = 85
      } else if (singularSlug === singularReference || singularName === singularReference) {
        score = 80
      } else if (normalizedSlug.includes(normalizedReference) || normalizedName.includes(normalizedReference)) {
        score = 65
      } else if (normalizedReference.includes(normalizedSlug) || normalizedReference.includes(normalizedName)) {
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
    return space.value?.name || (t('labels.contents.wizard.rootNodeTitle') as string)
  }

  return node.title.trim() || node.blockName || (t('labels.contents.wizard.untitledNode') as string)
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
              ? `${t('labels.contents.wizard.aiCreateUnder') as string} ${getAiNodeLabel(parentId) || parentId}`
              : (t('labels.contents.wizard.aiCreateAtRoot') as string),
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
  editingNodeId.value = nodeId
  editingField.value = field


  if (field === 'title' && initialChar) {
    treeApi.updateTitle(nodeId, initialChar)
  }
}


const createNodeFromContext = (
  nodeId: string,
  position: ContentWizardAddPosition,
  block?: BlockResource
) => {
  const node = treeApi.getNode(nodeId)
  if (!node) {
    return false
  }


  const resolvedPosition: ContentWizardAddPosition = node.isRootVirtual ? 'child' : position
  const availableBlocks =
    resolvedPosition === 'sibling' ? getBottomBlocks(nodeId) : getRightBlocks(nodeId)


  const preferredBlock =
    block ||
    (!node.isRootVirtual
      ? blocks.value.find((candidate) => candidate.id === node.blockId)
      : null) ||
    availableBlocks[0] ||
    null


  if (!preferredBlock || !availableBlocks.some((candidate) => candidate.id === preferredBlock.id)) {
    toast.error(t('labels.contents.wizard.noBlocksAvailable'))
    return false
  }


  const parentId =
    resolvedPosition === 'child'
      ? node.isRootVirtual
        ? null
        : node.id
      : node.isRootVirtual
        ? null
        : node.parentId


  const createdNode = treeApi.addNode(preferredBlock, {
    parentId,
    position: resolvedPosition,
    referenceNodeId: node.isRootVirtual ? null : node.id,
  })


  focusNode(createdNode.id)
  nextTick(() => {
    startEditing(createdNode.id, 'title')
  })


  return true
}


const duplicateWithCurrentBlock = (nodeId: string, position: ContentWizardAddPosition) => {
  const node = treeApi.getNode(nodeId)
  if (!node || node.isRootVirtual || node.deletedReason) {
    return false
  }


  const currentBlock = blocks.value.find((block) => block.id === node.blockId)
  if (!currentBlock) {
    return false
  }


  return createNodeFromContext(nodeId, position, currentBlock)
}


const openAddMenu = (nodeId: string, position: ContentWizardAddPosition) => {
  const resolvedPosition: ContentWizardAddPosition =
    nodeId === CONTENT_WIZARD_ROOT_ID ? 'child' : position
  const availableBlocks =
    resolvedPosition === 'sibling' ? getBottomBlocks(nodeId) : getRightBlocks(nodeId)


  if (availableBlocks.length === 0) {
    toast.error(t('labels.contents.wizard.noBlocksAvailable'))
    return false
  }


  const opened = canvasRef.value?.openNodeAddMenu(nodeId, resolvedPosition)
  if (!opened) {
    toast.error(t('labels.contents.wizard.noBlocksAvailable'))
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


  treeApi.toggleDelete(nodeId)
  clearTransientState()
  focusNode(removesDraftNode ? fallbackNodeId : nodeId)
}


const { handleKeydown } = useContentWizardKeyboard({
  getNode: treeApi.getNode,
  focusNode,
  duplicateWithCurrentBlock,
  openAddMenu,
  toggleDelete: handleToggleDelete,
  startEditing,
  clearTransientState,
})


const handleCommitTitle = (nodeId: string, value: string) => {
  treeApi.updateTitle(nodeId, value)
  clearTransientState()
  focusNode(nodeId)
}


const handleCommitSlug = (nodeId: string, value: string) => {
  treeApi.updateSlug(nodeId, value)
  clearTransientState()
  focusNode(nodeId)
}


const handleBlockUpdate = (nodeId: string, blockId: string) => {
  const result = treeApi.updateBlock(nodeId, blockId)
  if (!result.valid && result.message) {
    toast.error(result.message)
  }
}


const handleDragStart = (nodeId: string, event: DragEvent) => {
  draggingNodeId.value = nodeId
  rootDropActive.value = true
  dropTargetId.value = null
  event.dataTransfer?.setData('text/plain', nodeId)
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'copyMove'
  }
}


const handleDragEnterNode = (nodeId: string) => {
  if (!draggingNodeId.value || draggingNodeId.value === nodeId) {
    return
  }


  dropTargetId.value = nodeId
  rootDropActive.value = false
}


const handleDragLeaveNode = (nodeId: string) => {
  if (dropTargetId.value === nodeId) {
    dropTargetId.value = null
    rootDropActive.value = !!draggingNodeId.value
  }
}


const handleDragEnd = () => {
  draggingNodeId.value = null
  dropTargetId.value = null
  rootDropActive.value = false
}


const isCopyDrop = (event: DragEvent) =>
  event.altKey || event.ctrlKey || event.metaKey || event.dataTransfer?.dropEffect === 'copy'


const getDropFocusNodeId = (
  draggedNodeId: string,
  result: { valid: boolean; createdNodeId?: string },
  copyMode: boolean
) => {
  if (!copyMode) {
    return draggedNodeId
  }


  return typeof result.createdNodeId === 'string' ? result.createdNodeId : draggedNodeId
}


const handleDropOnNode = ({ nodeId, event }: { nodeId: string; event: DragEvent }) => {
  if (!draggingNodeId.value) {
    return
  }


  const draggedNodeId = draggingNodeId.value
  const copyMode = isCopyDrop(event)
  const result = copyMode
    ? treeApi.duplicateNode(draggedNodeId, nodeId)
    : treeApi.moveNode(draggedNodeId, nodeId)
  if (!result.valid && result.message) {
    toast.error(result.message)
  }


  focusNode(getDropFocusNodeId(draggedNodeId, result, copyMode))
  handleDragEnd()
}


const handleDropOnRoot = (event: DragEvent) => {
  if (!draggingNodeId.value) {
    return
  }


  const draggedNodeId = draggingNodeId.value
  const copyMode = isCopyDrop(event)
  const result = copyMode
    ? treeApi.duplicateNode(draggedNodeId, null)
    : treeApi.moveNode(draggedNodeId, null)
  if (!result.valid && result.message) {
    toast.error(result.message)
  }


  focusNode(getDropFocusNodeId(draggedNodeId, result, copyMode))
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
              ? `${summary} • ${t('labels.contents.wizard.aiWarnings', { count: warnings.length })}`
              : (t('labels.contents.wizard.aiWarningsOnly', { count: warnings.length }) as string),
          tone: 'error',
        }
    return
  }

  aiStatus.value = {
    message:
      operations.length === 0
        ? (isFinal
            ? ''
            : (t('labels.contents.wizard.aiGenerating') as string))
        : isFinal
          ? ''
          : `${t('labels.contents.wizard.aiStreamingProgress', { count: operations.length }) as string} • ${summary}`,
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
  cancelStream()
  restoreAiSnapshot()
  clearAiState()

  aiPreviewSnapshot.value = treeApi.createSnapshot()
  aiResponseBuffer.value = ''
  aiStatus.value = {
    message: t('labels.contents.wizard.aiGenerating') as string,
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
            message: t('labels.contents.wizard.aiParseError') as string,
            tone: 'error',
          }
          clearAiStreamState()
          return
        }

        updateAiPreview(parsed.operations, true)
        clearAiStreamState()
      },
      onError: (message) => {
        restoreAiSnapshot()
        aiStatus.value = { message, tone: 'error' }
        clearAiStreamState()
      },
    }
  )
}

const handleAiCancel = () => {
  cancelStream()
  restoreAiSnapshot()
  aiStatus.value = {
    message: t('labels.contents.wizard.aiCanceled') as string,
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
  clearAiState()
  await Promise.all([invalidateContentQueries(), refetchMenu(), refetchBlocks()])
  treeApi.initializeFromSource()
  clearTransientState()
  aiStatus.value = null
  focusNode(CONTENT_WIZARD_ROOT_ID)
  await nextTick()
  canvasRef.value?.fitToView()
}


const handleDiscard = async () => {
  if (
    hasUnsavedChanges.value &&
    !(await alert.confirm(t('labels.contents.wizard.discardConfirmMessage')))
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
    t('labels.contents.wizard.applySuccess', { count: result.operations.length }) as string
  )
}


useSeoMeta({
  title: computed(() => t('labels.contents.wizard.title')),
})
</script>

<template>
  <div class="flex min-h-0 w-full flex-1 flex-col bg-background">
    <Teleport to="#appHeader">
      <div class="min-w-0">
        <h1 class="flex items-center gap-2">
          <span class="text-lg font-semibold text-primary">{{
            $t('labels.contents.wizard.title')
          }}</span>
          <Badge>Beta</Badge>
        </h1>
      </div>
    </Teleport>

    <Teleport to="#appHeaderActions">
      <div class="flex items-center gap-2">
        <Button
          :as="RouterLink"
          :to="{ name: 'space-content-index', params: { space: spaceId } }"
          variant="outline"
        >
          <Icon name="lucide:arrow-left" />
          {{ $t('labels.contents.wizard.backToTree') }}
        </Button>
        <Button
          variant="outline"
          :disabled="isApplying || !hasUnsavedChanges"
          @click="handleDiscard"
        >
          {{ $t('labels.contents.wizard.discard') }}
        </Button>
        <Button
          variant="primary"
          :disabled="isApplying || validationCount > 0 || !hasUnsavedChanges"
          @click="handleApply"
        >
          <Icon
            v-if="isApplying"
            name="lucide:loader-circle"
            class="animate-spin"
          />
          {{
            isApplying ? $t('labels.contents.wizard.applying') : $t('labels.contents.wizard.apply')
          }}
        </Button>
      </div>
    </Teleport>

    <main class="relative min-h-0 flex-1 overflow-hidden">
      <div
        v-if="isLoading"
        class="flex h-full items-center justify-center"
      >
        <div
          class="flex items-center gap-3 rounded-2xl border border-border bg-background px-4 py-3 shadow-soft"
        >
          <Icon
            name="lucide:loader-circle"
            class="animate-spin text-primary"
          />
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
          :apply-error="applyError"
          @reload="reloadFromServer"
          @zoom-in="canvasRef?.zoomIn()"
          @zoom-out="canvasRef?.zoomOut()"
          @fit="canvasRef?.fitToView()"
        />

        <ContentWizardCanvas
          ref="canvas"
          :nodes="draftNodes"
          :bounds="bounds"
          :root-title="space?.name || $t('labels.contents.wizard.rootNodeTitle')"
          :focused-node-id="focusedNodeId"
          :editing-node-id="editingNodeId"
          :editing-field="editingField"
          :drop-target-id="dropTargetId"
          :root-drop-active="rootDropActive"
          :get-bottom-blocks="getBottomBlocks"
          :get-right-blocks="getRightBlocks"
          :get-block-options="getBlockOptions"
          @focus-node="focusNode"
          @node-keydown="handleKeydown($event.event, $event.nodeId)"
          @start-edit="startEditing($event.nodeId, $event.field, $event.initialChar)"
          @commit-title="handleCommitTitle($event.nodeId, $event.value)"
          @commit-slug="handleCommitSlug($event.nodeId, $event.value)"
          @update-block="handleBlockUpdate($event.nodeId, $event.blockId)"
          @toggle-delete="handleToggleDelete($event)"
          @add-node="createNodeFromContext($event.nodeId, $event.position, $event.block)"
          @dragstart="handleDragStart($event.nodeId, $event.event)"
          @dragend="handleDragEnd"
          @dragenter="handleDragEnterNode"
          @dragleave="handleDragLeaveNode"
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
      </template>
    </main>
  </div>
</template>
