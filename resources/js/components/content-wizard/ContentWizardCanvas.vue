<script setup lang="ts">
import type { ComponentPublicInstance } from 'vue'

import { useContentWizardViewport } from '~/composables/useContentWizardViewport'
import {
  CONTENT_WIZARD_CARD_HEIGHT,
  CONTENT_WIZARD_CARD_WIDTH,
  type ContentWizardAddPosition,
  type ContentWizardBounds,
  type ContentWizardDraftNode,
  type ContentWizardEditableField,
} from '~/types/content-wizard'

import ContentWizardConnectorLayer from './ContentWizardConnectorLayer.vue'
import ContentWizardNodeCard from './ContentWizardNodeCard.vue'

const props = defineProps<{
  nodes: Record<string, ContentWizardDraftNode>
  bounds: ContentWizardBounds
  rootTitle?: string
  focusedNodeId: string | null
  editingField: ContentWizardEditableField | null
  editingNodeId: string | null
  dropTargetId: string | null
  rootDropActive: boolean
  getBottomBlocks: (nodeId: string) => BlockResource[]
  getRightBlocks: (nodeId: string) => BlockResource[]
  getBlockOptions: (nodeId: string) => BlockResource[]
}>()


const emit = defineEmits<{
  (event: 'focus-node', nodeId: string): void
  (event: 'node-keydown', payload: { nodeId: string; event: KeyboardEvent }): void
  (
    event: 'start-edit',
    payload: { nodeId: string; field: ContentWizardEditableField; initialChar?: string }
  ): void
  (event: 'commit-title', payload: { nodeId: string; value: string }): void
  (event: 'commit-slug', payload: { nodeId: string; value: string }): void
  (event: 'update-block', payload: { nodeId: string; blockId: string }): void
  (event: 'toggle-delete', nodeId: string): void
  (
    event: 'add-node',
    payload: { nodeId: string; position: ContentWizardAddPosition; block: BlockResource }
  ): void
  (event: 'dragstart', payload: { nodeId: string; event: DragEvent }): void
  (event: 'dragend'): void
  (event: 'dragenter', nodeId: string): void
  (event: 'dragleave', nodeId: string): void
  (event: 'drop-on-node', payload: { nodeId: string; event: DragEvent }): void
  (event: 'drop-on-root', dragEvent: DragEvent): void
}>()


const {
  canvasOrigin,
  canvasSize,
  containerRef,
  fitToView,
  handlePointerDown,
  handlePointerLeave,
  handlePointerMove,
  handlePointerUp,
  resetView,
  viewport,
  zoomIn,
  zoomOut,
  zoomPercent,
} = useContentWizardViewport(toRef(props, 'bounds'))


const hasFittedInitially = ref(false)
const nodeRefs = new Map<string, InstanceType<typeof ContentWizardNodeCard>>()


const sortedNodes = computed(() =>
  Object.values(props.nodes).sort((left, right) => {
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

    element.scrollLeft = Math.max(0, centerX - element.clientWidth / 2)
    element.scrollTop = Math.max(0, centerY - element.clientHeight / 2)
  })
}


const openNodeAddMenu = (nodeId: string, position: ContentWizardAddPosition) => {
  const nodeRef = nodeRefs.get(nodeId)
  if (!nodeRef) {
    return false
  }


  nodeRef.focusCard()
  nodeRef.openAddMenu(position)
  return true
}

const handleCanvasDragOver = (event: DragEvent) => {
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect =
      event.altKey || event.ctrlKey || event.metaKey ? 'copy' : 'move'
  }
}


watch(
  () => props.focusedNodeId,
  (nodeId) => {
    if (nodeId) {
      focusNodeCard(nodeId)
    }
  }
)


watch(
  () => [props.bounds.width, props.bounds.height],
  ([width, height]) => {
    if ((width > 0 || height > 0) && !hasFittedInitially.value) {
      hasFittedInitially.value = true
      nextTick(() => fitToView())
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
  zoomIn,
  zoomOut,
  zoomPercent,
})
</script>

<template>
  <div
    ref="containerRef"
    class="absolute inset-0 overflow-auto"
    @pointerdown="handlePointerDown"
    @pointermove="handlePointerMove"
    @pointerup="handlePointerUp"
    @pointerleave="handlePointerLeave"
  >
    <div
      class="relative min-h-full min-w-full"
      :style="{
        width: `${scaledCanvasSize.width}px`,
        height: `${scaledCanvasSize.height}px`,
      }"
      @dragover.prevent="handleCanvasDragOver"
      @drop.prevent="emit('drop-on-root', $event)"
    >
      <div
        class="absolute left-0 top-0 origin-top-left"
        :style="{
          width: `${canvasSize.width}px`,
          height: `${canvasSize.height}px`,
          transform: `scale(${viewport.scale})`,
        }"
      >
        <div
          class="absolute inset-0 transition-colors"
          :class="props.rootDropActive ? 'bg-info-background/10' : ''"
        />

        <ContentWizardConnectorLayer
          :nodes="props.nodes"
          :positions="canvasPositions"
        />

        <ContentWizardNodeCard
          v-for="node in sortedNodes"
          :ref="setNodeRef(node.id)"
          :key="node.id"
          :node="node"
          :root-title="props.rootTitle"
          :focused="props.focusedNodeId === node.id"
          :editing-field="props.editingNodeId === node.id ? props.editingField : null"
          :drop-active="props.dropTargetId === node.id"
          :block-options="props.getBlockOptions(node.id)"
          :blocks-for-bottom="props.getBottomBlocks(node.id)"
          :blocks-for-right="props.getRightBlocks(node.id)"
          :style="{
            width: `${CONTENT_WIZARD_CARD_WIDTH}px`,
            height: `${CONTENT_WIZARD_CARD_HEIGHT}px`,
            transform: `translate(${canvasPositions[node.id]?.x || 0}px, ${canvasPositions[node.id]?.y || 0}px)`,
          }"
          @focus="emit('focus-node', node.id)"
          @keydown="emit('node-keydown', { nodeId: node.id, event: $event })"
          @start-edit="
            emit('start-edit', {
              nodeId: node.id,
              field: $event.field,
              initialChar: $event.initialChar,
            })
          "
          @commit-title="emit('commit-title', { nodeId: node.id, value: $event })"
          @commit-slug="emit('commit-slug', { nodeId: node.id, value: $event })"
          @update-block="emit('update-block', { nodeId: node.id, blockId: $event })"
          @toggle-delete="emit('toggle-delete', node.id)"
          @add="
            emit('add-node', { nodeId: node.id, position: $event.position, block: $event.block })
          "
          @dragstart="emit('dragstart', { nodeId: node.id, event: $event })"
          @dragend="emit('dragend')"
          @dragenter="emit('dragenter', node.id)"
          @dragleave="emit('dragleave', node.id)"
          @drop="emit('drop-on-node', { nodeId: node.id, event: $event })"
        />
      </div>
    </div>
  </div>
</template>
