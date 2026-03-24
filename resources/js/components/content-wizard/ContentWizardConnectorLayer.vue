<script setup lang="ts">
import {
  CONTENT_WIZARD_CARD_HEIGHT,
  CONTENT_WIZARD_CARD_WIDTH,
  CONTENT_WIZARD_ROOT_ID,
} from '~/types/content-wizard'
import type { ContentWizardDraftNode, ContentWizardPosition } from '~/types/content-wizard'

const props = defineProps<{
  nodes: Record<string, ContentWizardDraftNode>
  positions: Record<string, ContentWizardPosition>
}>()

const connectors = computed(() => {
  return Object.values(props.nodes)
    .filter((node) => !node.isRootVirtual && node.isVisible && node.parentId !== undefined)
    .flatMap((node) => {
      const parentId = node.parentId || CONTENT_WIZARD_ROOT_ID
      const parent = props.positions[parentId]
      const child = props.positions[node.id]

      if (!parent || !child) {
        return []
      }

      if (parentId === CONTENT_WIZARD_ROOT_ID) {
        const startX = parent.x + CONTENT_WIZARD_CARD_WIDTH / 2
        const startY = parent.y + CONTENT_WIZARD_CARD_HEIGHT
        const endX = child.x
        const endY = child.y + CONTENT_WIZARD_CARD_HEIGHT / 2

        return [`M ${startX} ${startY} V ${endY} H ${endX}`]
      }

      const startX = parent.x + CONTENT_WIZARD_CARD_WIDTH
      const startY = parent.y + CONTENT_WIZARD_CARD_HEIGHT / 2
      const endX = child.x
      const endY = child.y + CONTENT_WIZARD_CARD_HEIGHT / 2
      const midX = startX + (endX - startX) / 2

      return [`M ${startX} ${startY} H ${midX} V ${endY} H ${endX}`]
    })
})
</script>

<template>
  <svg class="pointer-events-none absolute inset-0 overflow-visible">
    <path
      v-for="(connector, index) in connectors"
      :key="index"
      :d="connector"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      class="text-border"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
  </svg>
</template>
