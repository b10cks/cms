<script setup lang="ts">
import TiptapEditor from '~/components/editor/TiptapEditor.vue'
import { FormField } from '~/components/ui/form'

const value = defineModel<Record<string, unknown>>()

const props = defineProps<{
  item: RichTextSchema & { key: string }
  spaceId: string
}>()

const htmlClasses = computed(
  () => (props.item.html_classes || []) as Array<{ name: string; className: string; css?: string }>
)

const headingLevels = computed(() => props.item.heading_levels || ['h1', 'h2', 'h3', 'h4', 'p'])
</script>

<template>
  <FormField
    :name="props.item.key"
    :label="props.item.name || props.item.key"
    :description="props.item.description"
  >
    <TiptapEditor
      :model-value="value"
      :html-classes="htmlClasses"
      :heading-levels="headingLevels"
      :space-id="spaceId"
      @update:model-value="(v) => (value = v)"
    />
  </FormField>
</template>
