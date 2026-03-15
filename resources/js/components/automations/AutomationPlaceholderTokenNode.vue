<script lang="ts">
export default {
  name: 'AutomationPlaceholderTokenNode',
}
</script>

<script setup lang="ts">
import { nodeViewProps, NodeViewWrapper } from '@tiptap/vue-3'
import { computed } from 'vue'

const props = defineProps(nodeViewProps)

const displayValue = computed(() => {
  return `{{ ${String(props.node.attrs.label || props.node.attrs.value || '')} }}`
})
</script>

<template>
  <NodeViewWrapper
    as="span"
    class="inline-flex items-center rounded-md border border-border bg-muted px-2 py-0.5 font-mono text-[11px] text-foreground"
    contenteditable="false"
    :data-value="node.attrs.value"
    :data-group="node.attrs.group"
  >
    {{ displayValue }}
  </NodeViewWrapper>
</template>
