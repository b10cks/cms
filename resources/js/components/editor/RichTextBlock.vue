<script setup lang="ts">
import TiptapEditor from '~/components/editor/TiptapEditor.vue'
import { FormField } from '~/components/ui/form'

const value = defineModel<Record<string, unknown>>({ default: {} })

const props = defineProps<{
  item: RichTextSchema & { key: string }
  spaceId: string
}>()

const htmlClasses = computed(
  () => (props.item.html_classes || []) as Array<{ name: string; className: string; css?: string }>
)

const headingLevels = computed<Array<'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'>>(
  () => props.item.heading_levels || ['h1', 'h2', 'h3', 'h4', 'p']
)

const placeholders = computed(
  () => (props.item.placeholders || []) as Array<{ key: string; label: string }>
)

const features = computed(() => props.item.features || {})

const listStyles = computed(() => props.item.list_styles || [])
</script>

<template>
  <FormField
    :name="props.item.key"
    :label="props.item.name || props.item.key"
    :description="props.item.description || undefined"
    :required="props.item.required"
  >
    <TiptapEditor
      :model-value="value"
      :html-classes="htmlClasses"
      :heading-levels="headingLevels"
      :placeholders="placeholders"
      :features="features"
      :list-styles="listStyles"
      :space-id="spaceId"
      @update:model-value="(v) => (value = v)"
    />
  </FormField>
</template>
