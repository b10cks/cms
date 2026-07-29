<script setup lang="ts">
import type { ComponentPublicInstance } from 'vue'

import Icon from '~/components/Icon.vue'
import { useContentWizardViewport } from '~/composables/useContentWizardViewport'
import {
  CONTENT_WIZARD_CARD_HEIGHT,
  CONTENT_WIZARD_CARD_WIDTH,
  CONTENT_WIZARD_ROOT_ID,
  type ContentWizardAddPosition,
  type ContentWizardBounds,
  type ContentWizardCollaborator,
  type ContentWizardDraftNode,
  type ContentWizardEditableField,
} from '~/types/content-wizard'

import ContentWizardAddMenu from './ContentWizardAddMenu.vue'
import ContentWizardConnectorLayer from './ContentWizardConnectorLayer.vue'
import ContentWizardNodeCard from './ContentWizardNodeCard.vue'

const props = defineProps<{
  nodes: Record<string, ContentWizardDraftNode>
  bounds: ContentWizardBounds
  spaceId: string
  rootTitle?: string
  canMutate?: boolean
  focusedNodeId: string | null
  selectedNodeIds: string[]
  editingField: ContentWizardEditableField | null
  editingNodeId: string | null
  dropTargetId: string | null
  rootDropActive: boolean
  remoteFocusUsersByNodeId: Record<string, ContentWizardCollaborator[]>
  remoteCursors: Array<{
    user: ContentWizardCollaborator
    userId: string
    x: number
    y: number
    updatedAt: number
    visible: boolean
  }>
  getBottomBlocks: (nodeId: string) => BlockResource[]
  getRightBlocks: (nodeId: string) => BlockResource[]
  getBlockOptions: (nodeId: string) => BlockResource[]
}>()

const emit = defineEmits<{
  (event: 'canvas-pointerdown', payload: PointerEvent): void
  (event: 'focus-node', nodeId: string): void
  (event: 'node-pointerdown', payload: { nodeId: string; event: PointerEvent }): void
  (event: 'node-keydown', payload: { nodeId: string; event: KeyboardEvent }): void
  (
    event: 'start-edit',
    payload: { nodeId: string; field: ContentWizardEditableField; initialChar?: string }
  ): void
  (event: 'input-title', payload: { nodeId: string; value: string }): void
  (event: 'input-slug', payload: { nodeId: string; value: string }): void
  (event: 'commit-title', payload: { nodeId: string; value: string }): void
  (event: 'commit-slug', payload: { nodeId: string; value: string }): void
  (event: 'update-block', payload: { nodeId: string; blockId: string }): void
  (event: 'toggle-delete', nodeId: string): void
  (event: 'toggle-collapse', nodeId: string): void
  (
    event: 'add-node',
    payload: {
      nodeId: string
      position: ContentWizardAddPosition
      block: BlockResource
      template?: BlockTemplate | null
    }
  ): void
  (event: 'dragstart', payload: { nodeId: string; event: DragEvent }): void
  (event: 'dragend'): void
  (event: 'dragover-node', payload: { nodeId: string; event: DragEvent }): void
  (event: 'dragover-root', payload: DragEvent): void
  (event: 'dragenter', nodeId: string): void
  (event: 'dragleave', nodeId: string): void
  (event: 'drop-on-node', payload: { nodeId: string; event: DragEvent }): void
  (event: 'drop-on-root', dragEvent: DragEvent): void
  (event: 'cursor-move', payload: { x: number; y: number } | null): void
}>()

const {
  canvasOrigin,
  canvasSize,
  containerRef,
  containerHeight,
  containerWidth,
  fitToView,
  handlePointerDown,
  handlePointerLeave,
  handlePointerMove,
  handlePointerUp,
  resetView,
  sceneViewport,
  setZoom100,
  viewport,
  zoomIn,
  zoomOut,
  zoomPercent,
} = useContentWizardViewport(toRef(props, 'bounds'))

const AI_DOCK_SAFE_AREA = 220
const VIRTUALIZATION_OVERSCAN_X = CONTENT_WIZARD_CARD_WIDTH * 1.5
const VIRTUALIZATION_OVERSCAN_Y = CONTENT_WIZARD_CARD_HEIGHT * 6
const SHARED_ADD_BUTTON_OFFSET = 24
const SHARED_ADD_TRIGGER_SIZE = 24

type SceneRect = {
  left: number
  top: number
  right: number
  bottom: number
}

const hasFittedInitially = ref(false)
const nodeRefs = new Map<string, InstanceType<typeof ContentWizardNodeCard>>()
const hoveredNodeId = ref<string | null>(null)
const sharedAddMenuOpen = ref(false)
const sharedAddMenuNodeId = ref<string | null>(null)
const sharedAddMenuPosition = ref<ContentWizardAddPosition>('child')
const sharedAddMenuBlocks = ref<BlockResource[]>([])
const selectedNodeIdSet = computed(() => new Set(props.selectedNodeIds))

const sortedNodes = computed(() =>
  Object.values(props.nodes)
    .filter((node) => node.isVisible)
    .sort((left, right) => {
      if (left.depth !== right.depth) {
        return left.depth - right.depth
      }

      return left.position - right.position
    })
)

const canvasPositions = computed(() => {
  return Object.fromEntries(
    sortedNodes.value.map((node) => [
      node.id,
      {
        x: canvasOrigin.value.x + node.layout.x,
        y: canvasOrigin.value.y + node.layout.y,
      },
    ])
  )
})

const scaledCanvasSize = computed(() => ({
  width: canvasSize.value.width * viewport.scale,
  height: canvasSize.value.height * viewport.scale,
}))

const expandSceneRect = (rect: SceneRect, padX: number, padY: number): SceneRect => ({
  left: rect.left - padX,
  top: rect.top - padY,
  right: rect.right + padX,
  bottom: rect.bottom + padY,
})

const intersectsSceneRect = (left: number, top: number, right: number, bottom: number) => {
  const rect = expandSceneRect(
    sceneViewport.value,
    VIRTUALIZATION_OVERSCAN_X,
    VIRTUALIZATION_OVERSCAN_Y
  )

  return left <= rect.right && right >= rect.left && top <= rect.bottom && bottom >= rect.top
}

const getNodeSceneRect = (nodeId: string) => {
  const position = canvasPositions.value[nodeId]
  if (!position) {
    return null
  }

  return {
    left: position.x,
    top: position.y,
    right: position.x + CONTENT_WIZARD_CARD_WIDTH,
    bottom: position.y + CONTENT_WIZARD_CARD_HEIGHT,
  }
}

const shouldAlwaysRenderNode = (nodeId: string) =>
  nodeId === props.focusedNodeId ||
  nodeId === props.editingNodeId ||
  nodeId === props.dropTargetId ||
  nodeId === sharedAddMenuNodeId.value ||
  selectedNodeIdSet.value.has(nodeId)

const renderedNodes = computed(() =>
  sortedNodes.value.filter((node) => {
    if (node.isRootVirtual || shouldAlwaysRenderNode(node.id)) {
      return true
    }

    const rect = getNodeSceneRect(node.id)
    if (!rect) {
      return false
    }

    return intersectsSceneRect(rect.left, rect.top, rect.right, rect.bottom)
  })
)

const getConnectorSceneRect = (node: ContentWizardDraftNode) => {
  if (node.isRootVirtual) {
    return null
  }

  const parentId = node.parentId ?? CONTENT_WIZARD_ROOT_ID
  const parent = canvasPositions.value[parentId]
  const child = canvasPositions.value[node.id]

  if (!parent || !child) {
    return null
  }

  const isRootConnector = parentId === CONTENT_WIZARD_ROOT_ID
  const startX = isRootConnector
    ? parent.x + CONTENT_WIZARD_CARD_WIDTH / 2
    : parent.x + CONTENT_WIZARD_CARD_WIDTH
  const startY = isRootConnector
    ? parent.y + CONTENT_WIZARD_CARD_HEIGHT
    : parent.y + CONTENT_WIZARD_CARD_HEIGHT / 2
  const endX = child.x
  const endY = child.y + CONTENT_WIZARD_CARD_HEIGHT / 2

  return {
    left: Math.min(startX, endX),
    top: Math.min(startY, endY),
    right: Math.max(startX, endX),
    bottom: Math.max(startY, endY),
  }
}

const renderedConnectors = computed(() =>
  sortedNodes.value.filter((node) => {
    if (node.isRootVirtual) {
      return false
    }

    const rect = getConnectorSceneRect(node)
    if (!rect) {
      return false
    }

    return intersectsSceneRect(rect.left, rect.top, rect.right, rect.bottom)
  })
)

const activeAddControlsNodeId = computed(
  () =>
    (sharedAddMenuOpen.value ? sharedAddMenuNodeId.value : null) ??
    hoveredNodeId.value ??
    props.focusedNodeId
)
const activeAddControlsNode = computed(() =>
  activeAddControlsNodeId.value ? props.nodes[activeAddControlsNodeId.value] || null : null
)
const activeBottomBlocks = computed(() =>
  activeAddControlsNodeId.value ? props.getBottomBlocks(activeAddControlsNodeId.value) : []
)
const activeRightBlocks = computed(() =>
  activeAddControlsNodeId.value ? props.getRightBlocks(activeAddControlsNodeId.value) : []
)

const resolveAddMenuSide = (nodeId: string, position: ContentWizardAddPosition) => {
  const node = props.nodes[nodeId]
  if (!node || node.isRootVirtual || position === 'sibling') {
    return 'bottom' as const
  }

  return 'right' as const
}

const getAddAnchorStyle = (nodeId: string, position: ContentWizardAddPosition) => {
  const canvasPosition = canvasPositions.value[nodeId]
  if (!canvasPosition) {
    return null
  }

  const side = resolveAddMenuSide(nodeId, position)

  if (side === 'right') {
    return {
      width: `${SHARED_ADD_TRIGGER_SIZE}px`,
      height: `${SHARED_ADD_TRIGGER_SIZE}px`,
      left: `${canvasPosition.x + CONTENT_WIZARD_CARD_WIDTH + SHARED_ADD_BUTTON_OFFSET}px`,
      top: `${canvasPosition.y + CONTENT_WIZARD_CARD_HEIGHT / 2}px`,
      transform: 'translate(-120%, -50%)',
    }
  }

  return {
    width: `${SHARED_ADD_TRIGGER_SIZE}px`,
    height: `${SHARED_ADD_TRIGGER_SIZE}px`,
    left: `${canvasPosition.x + CONTENT_WIZARD_CARD_WIDTH / 2}px`,
    top: `${canvasPosition.y + CONTENT_WIZARD_CARD_HEIGHT + SHARED_ADD_BUTTON_OFFSET}px`,
    transform: 'translate(-50%, -120%)',
  }
}

const activeBottomButtonStyle = computed(() =>
  activeAddControlsNodeId.value ? getAddAnchorStyle(activeAddControlsNodeId.value, 'sibling') : null
)
const activeRightButtonStyle = computed(() =>
  activeAddControlsNodeId.value ? getAddAnchorStyle(activeAddControlsNodeId.value, 'child') : null
)
const sharedAddMenuAnchorStyle = computed(() =>
  sharedAddMenuNodeId.value
    ? getAddAnchorStyle(sharedAddMenuNodeId.value, sharedAddMenuPosition.value)
    : null
)
const sharedAddMenuSide = computed(() =>
  sharedAddMenuNodeId.value
    ? resolveAddMenuSide(sharedAddMenuNodeId.value, sharedAddMenuPosition.value)
    : 'bottom'
)

const setNodeRef = (nodeId: string) => {
  return (instance: Element | ComponentPublicInstance | null) => {
    const component = instance as InstanceType<typeof ContentWizardNodeCard> | null

    if (component) {
      nodeRefs.set(nodeId, component)
      return
    }

    nodeRefs.delete(nodeId)
  }
}

const focusNodeCard = (nodeId: string) => {
  nextTick(() => {
    nodeRefs.get(nodeId)?.focusCard()
  })
}

const centerNode = (nodeId: string) => {
  nextTick(() => {
    const element = containerRef.value
    const position = canvasPositions.value[nodeId]

    if (!element || !position) {
      return
    }

    const centerX = (position.x + CONTENT_WIZARD_CARD_WIDTH / 2) * viewport.scale
    const centerY = (position.y + CONTENT_WIZARD_CARD_HEIGHT / 2) * viewport.scale
    const viewportCenterX = element.clientWidth / 2
    const viewportCenterY = Math.max((element.clientHeight - AI_DOCK_SAFE_AREA) / 2, 120)

    element.scrollLeft = Math.max(0, centerX - viewportCenterX)
    element.scrollTop = Math.max(0, centerY - viewportCenterY)
  })
}

const openNodeAddMenu = (
  nodeId: string,
  position: ContentWizardAddPosition,
  options: {
    focusNode?: boolean
  } = {}
) => {
  const node = props.nodes[nodeId]
  if (!node) {
    return false
  }

  const resolvedPosition: ContentWizardAddPosition = node.isRootVirtual ? 'child' : position
  const side = resolveAddMenuSide(nodeId, resolvedPosition)
  const blocks = side === 'right' ? props.getRightBlocks(nodeId) : props.getBottomBlocks(nodeId)

  if (blocks.length === 0) {
    return false
  }

  if (options.focusNode !== false) {
    focusNodeCard(nodeId)
  }

  // With a single choice the menu is pure friction — unless that block has
  // templates, in which case there is still a decision to make.
  if (blocks.length === 1 && !blocks[0].templates_count) {
    sharedAddMenuOpen.value = false
    emit('add-node', {
      nodeId,
      position: resolvedPosition,
      block: blocks[0],
    })
    return true
  }

  hoveredNodeId.value = nodeId
  sharedAddMenuNodeId.value = nodeId
  sharedAddMenuPosition.value = resolvedPosition
  sharedAddMenuBlocks.value = blocks
  sharedAddMenuOpen.value = true

  return true
}

const handleCanvasDragOver = (event: DragEvent) => {
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = event.altKey || event.ctrlKey || event.metaKey ? 'copy' : 'move'
  }
}

const handleContainerPointerDown = (event: PointerEvent) => {
  emit('canvas-pointerdown', event)
  handlePointerDown(event)
}

const handleNodeDragOver = (node: ContentWizardDraftNode, event: DragEvent) => {
  if (node.isRootVirtual) {
    emit('dragover-root', event)
    return
  }

  emit('dragover-node', { nodeId: node.id, event })
}

const handleNodeDrop = (node: ContentWizardDraftNode, event: DragEvent) => {
  if (node.isRootVirtual) {
    emit('drop-on-root', event)
    return
  }

  emit('drop-on-node', { nodeId: node.id, event })
}

const emitCursorPosition = (event: PointerEvent | null) => {
  const element = containerRef.value
  if (!element || !event) {
    emit('cursor-move', null)
    return
  }

  const rect = element.getBoundingClientRect()
  const relativeX = event.clientX - rect.left
  const relativeY = event.clientY - rect.top

  emit('cursor-move', {
    x: (element.scrollLeft + relativeX) / viewport.scale,
    y: (element.scrollTop + relativeY) / viewport.scale,
  })
}

const handleCanvasPointerMove = (event: PointerEvent) => {
  emitCursorPosition(event)
  handlePointerMove(event)
}

const handleCanvasPointerLeave = () => {
  hoveredNodeId.value = null
  emitCursorPosition(null)
  handlePointerLeave()
}

const handleNodePointerEnter = (nodeId: string) => {
  hoveredNodeId.value = nodeId
}

const handleNodePointerLeave = (nodeId: string, event: PointerEvent) => {
  const nextTarget = event.relatedTarget as HTMLElement | null
  if (nextTarget?.closest('[data-shared-add-controls]')) {
    return
  }

  if (hoveredNodeId.value === nodeId) {
    hoveredNodeId.value = null
  }
}

const handleSharedAddControlsPointerEnter = (nodeId: string) => {
  hoveredNodeId.value = nodeId
}

const handleSharedAddControlsPointerLeave = (event: PointerEvent) => {
  const nextTarget = event.relatedTarget as HTMLElement | null
  if (nextTarget?.closest('[data-node-card], [data-shared-add-controls]')) {
    return
  }

  hoveredNodeId.value = null
}

const handleSharedAddSelect = (payload: {
  block: BlockResource
  template: BlockTemplate | null
}) => {
  if (!sharedAddMenuNodeId.value) {
    return
  }

  emit('add-node', {
    nodeId: sharedAddMenuNodeId.value,
    position: sharedAddMenuPosition.value,
    block: payload.block,
    template: payload.template,
  })
}

watchEffect(() => {
  if (!sharedAddMenuOpen.value || !sharedAddMenuNodeId.value) {
    return
  }

  const side = resolveAddMenuSide(sharedAddMenuNodeId.value, sharedAddMenuPosition.value)
  const blocks =
    side === 'right'
      ? props.getRightBlocks(sharedAddMenuNodeId.value)
      : props.getBottomBlocks(sharedAddMenuNodeId.value)

  if (blocks.length === 0) {
    sharedAddMenuOpen.value = false
    return
  }

  sharedAddMenuBlocks.value = blocks
})

watch(sharedAddMenuOpen, (isOpen) => {
  if (isOpen) {
    return
  }

  sharedAddMenuBlocks.value = []
})

watch(
  () => props.focusedNodeId,
  (nodeId) => {
    if (nodeId) {
      focusNodeCard(nodeId)
    }
  }
)

watch(
  () => [props.bounds.width, props.bounds.height, containerWidth.value, containerHeight.value],
  ([width, height, widthPx, heightPx]) => {
    if ((width > 0 || height > 0) && widthPx > 0 && heightPx > 0 && !hasFittedInitially.value) {
      hasFittedInitially.value = true
      nextTick(() => {
        requestAnimationFrame(() => {
          fitToView()
        })
      })
    }
  },
  { immediate: true }
)

defineExpose({
  centerNode,
  fitToView,
  focusNodeCard,
  openNodeAddMenu,
  resetView,
  setZoom100,
  zoomIn,
  zoomOut,
  zoomPercent,
})
</script>

<template>
  <div
    ref="containerRef"
    class="absolute inset-0 overflow-auto overscroll-none select-none"
    @pointerdown="handleContainerPointerDown"
    @pointermove="handleCanvasPointerMove"
    @pointerup="handlePointerUp"
    @pointerleave="handleCanvasPointerLeave"
  >
    <div
      class="relative min-h-full min-w-full"
      :style="{
        width: `${scaledCanvasSize.width}px`,
        height: `${scaledCanvasSize.height}px`,
      }"
      @dragover="handleCanvasDragOver"
    >
      <div class="pointer-events-none absolute inset-0">
        <div
          v-for="cursor in props.remoteCursors"
          :key="`${cursor.userId}:${cursor.updatedAt}`"
          class="absolute z-30"
          :style="{
            left: `${cursor.x * viewport.scale}px`,
            top: `${cursor.y * viewport.scale}px`,
          }"
        >
          <svg
            class="size-6"
            :style="{ color: cursor.user.color }"
            viewBox="0 0 24 24"
          >
            <path
              fill="currentColor"
              fill-rule="evenodd"
              d="M4.38 3.075a1 1 0 0 0-1.305 1.306l7 17a1 1 0 0 0 1.844.013l2.685-6.265a1 1 0 0 1 .525-.525l6.265-2.685a1 1 0 0 0-.013-1.844z"
              clip-rule="evenodd"
            />
          </svg>
          <div
            class="rounded-md ml-6 px-2 py-1 text-xs font-bold text-white shadow-soft"
            :style="{ backgroundColor: cursor.user.color }"
          >
            {{ cursor.user.firstname }} {{ cursor.user.lastname }}
          </div>
        </div>
      </div>

      <div
        class="absolute left-0 top-0 origin-top-left"
        :style="{
          width: `${canvasSize.width}px`,
          height: `${canvasSize.height}px`,
          transform: `scale(${viewport.scale})`,
        }"
      >
        <ContentWizardConnectorLayer
          :nodes="renderedConnectors"
          :positions="canvasPositions"
        />

        <ContentWizardNodeCard
          v-for="node in renderedNodes"
          :ref="setNodeRef(node.id)"
          :key="node.id"
          :node="node"
          :root-title="props.rootTitle"
          :can-mutate="props.canMutate ?? true"
          :selected="selectedNodeIdSet.has(node.id)"
          :focused="props.focusedNodeId === node.id"
          :editing-field="props.editingNodeId === node.id ? props.editingField : null"
          :drop-active="node.isRootVirtual ? props.rootDropActive : props.dropTargetId === node.id"
          :remote-focused-users="props.remoteFocusUsersByNodeId[node.id] || []"
          :block-options="props.getBlockOptions(node.id)"
          :style="{
            width: `${CONTENT_WIZARD_CARD_WIDTH}px`,
            height: `${CONTENT_WIZARD_CARD_HEIGHT}px`,
            transform: `translate(${canvasPositions[node.id]?.x || 0}px, ${canvasPositions[node.id]?.y || 0}px)`,
          }"
          @pointerdown="emit('node-pointerdown', { nodeId: node.id, event: $event })"
          @pointerenter="handleNodePointerEnter(node.id)"
          @pointerleave="handleNodePointerLeave(node.id, $event)"
          @focus="emit('focus-node', node.id)"
          @keydown="emit('node-keydown', { nodeId: node.id, event: $event })"
          @start-edit="
            emit('start-edit', {
              nodeId: node.id,
              field: $event.field,
              initialChar: $event.initialChar,
            })
          "
          @input-title="emit('input-title', { nodeId: node.id, value: $event })"
          @input-slug="emit('input-slug', { nodeId: node.id, value: $event })"
          @commit-title="emit('commit-title', { nodeId: node.id, value: $event })"
          @commit-slug="emit('commit-slug', { nodeId: node.id, value: $event })"
          @update-block="emit('update-block', { nodeId: node.id, blockId: $event })"
          @toggle-delete="emit('toggle-delete', node.id)"
          @toggle-collapse="emit('toggle-collapse', node.id)"
          @dragstart="emit('dragstart', { nodeId: node.id, event: $event })"
          @dragend="emit('dragend')"
          @dragover="handleNodeDragOver(node, $event)"
          @dragenter="emit('dragenter', node.id)"
          @dragleave="emit('dragleave', node.id)"
          @drop="handleNodeDrop(node, $event)"
        />

        <div
          v-if="props.canMutate && activeAddControlsNode && activeBottomBlocks.length > 0"
          data-shared-add-controls
          class="absolute z-20"
          :style="activeBottomButtonStyle || undefined"
          @pointerenter="handleSharedAddControlsPointerEnter(activeAddControlsNode.id)"
          @pointerleave="handleSharedAddControlsPointerLeave"
        >
          <button
            type="button"
            class="flex size-6 items-center justify-center rounded-full scale-50 bg-accent text-primary shadow-sm transition-transform hover:scale-100"
            tabindex="-1"
            :aria-label="
              activeAddControlsNode.isRootVirtual
                ? $t('labels.contents.canvas.addChild')
                : $t('labels.contents.canvas.addSibling')
            "
            @pointerdown.prevent.stop
            @click.prevent.stop="
              openNodeAddMenu(
                activeAddControlsNode.id,
                activeAddControlsNode.isRootVirtual ? 'child' : 'sibling',
                { focusNode: false }
              )
            "
          >
            <Icon name="lucide:plus" />
          </button>
        </div>

        <div
          v-if="
            props.canMutate &&
            activeAddControlsNode &&
            !activeAddControlsNode.isRootVirtual &&
            activeRightBlocks.length > 0
          "
          data-shared-add-controls
          class="absolute z-20"
          :style="activeRightButtonStyle || undefined"
          @pointerenter="handleSharedAddControlsPointerEnter(activeAddControlsNode.id)"
          @pointerleave="handleSharedAddControlsPointerLeave"
        >
          <button
            type="button"
            class="flex size-6 items-center justify-center rounded-full bg-accent text-primary scale-50 shadow-sm transition-transform hover:scale-100"
            tabindex="-1"
            :aria-label="$t('labels.contents.canvas.addChild')"
            @pointerdown.prevent.stop
            @click.prevent.stop="
              openNodeAddMenu(activeAddControlsNode.id, 'child', { focusNode: false })
            "
          >
            <Icon name="lucide:plus" />
          </button>
        </div>

        <ContentWizardAddMenu
          v-model="sharedAddMenuOpen"
          :blocks="sharedAddMenuBlocks"
          :space-id="spaceId"
          :side="sharedAddMenuSide"
          :anchor-style="sharedAddMenuAnchorStyle"
          @select="handleSharedAddSelect"
        />
      </div>
    </div>
  </div>
</template>
