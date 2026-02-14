<script setup lang="ts">
import { nodeViewProps, NodeViewWrapper } from '@tiptap/vue-3'
import IconName from '../ui/IconName.vue'

const props = defineProps(nodeViewProps)

const icon = computed(() => {
  const storedIcon = props.node.attrs.icon
  if (storedIcon) return storedIcon
  return props.node.attrs.mentionType === 'content' ? 'file' : 'box'
})
</script>

<template>
  <NodeViewWrapper
    as="span"
    :class="['bg-muted/10 inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium']"
    contenteditable="false"
    :data-id="node.attrs.id"
    :data-mention-type="node.attrs.mentionType"
  >
    <IconName
      :name="node.attrs.label"
      :color="node.attrs.color"
      :icon="icon"
    />
  </NodeViewWrapper>
</template>
