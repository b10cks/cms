<script setup lang="ts">
import TiptapEditor from '~/components/editor/TiptapEditor.vue'
import { FormField } from '~/components/ui/form'

const props = defineProps<{
  item: RichTextSchema & { key: string }
  originalValue?: Record<string, unknown> | null
  modelValue?: Record<string, unknown> | null
  isMachineTranslated?: boolean
  spaceId?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const htmlClasses = computed(
  () => (props.item.html_classes || []) as Array<{ name: string; className: string; css?: string }>
)
const headingLevels = computed(() => props.item.heading_levels || ['h1', 'h2', 'h3', 'h4', 'p'])
const placeholders = computed(
  () => (props.item.placeholders || []) as Array<{ key: string; label: string }>
)
const emptyDocument = {
  type: 'doc',
  content: [
    {
      type: 'paragraph',
      content: [],
    },
  ],
} satisfies Record<string, unknown>
const normalizedOriginalValue = computed(() => props.originalValue || emptyDocument)
const normalizedModelValue = computed(() => props.modelValue || emptyDocument)

const updateValue = (value: Record<string, unknown>) => {
  emit('update:modelValue', value)
}
</script>

<template>
  <div
    class="grid grid-cols-2 items-start gap-4 py-2"
    :aria-labelledby="`${props.item.key}-label`"
  >
    <FormField
      :name="`${props.item.key}-original`"
      :label="props.item.name || props.item.key"
      hide-label
    >
      <div
        class="pointer-events-none opacity-60 [&_.ProseMirror]:h-auto [&_.ProseMirror]:max-w-none"
      >
        <TiptapEditor
          :model-value="normalizedOriginalValue"
          :html-classes="htmlClasses"
          :heading-levels="headingLevels"
          :placeholders="placeholders"
          :space-id="spaceId"
          disabled
          tabindex="-1"
          @update:model-value="() => {}"
        />
      </div>
    </FormField>
    <FormField
      :name="`${props.item.key}-translation`"
      :label="props.item.name || props.item.key"
      hide-label
    >
      <div
        :class="[
          '[&_.ProseMirror]:h-auto [&_.ProseMirror]:max-w-none',
          isMachineTranslated && 'rounded ring-1 ring-violet-500',
        ]"
      >
        <TiptapEditor
          :model-value="normalizedModelValue"
          :html-classes="htmlClasses"
          :heading-levels="headingLevels"
          :placeholders="placeholders"
          :space-id="spaceId"
          :disabled="disabled"
          @update:model-value="updateValue"
        />
      </div>
    </FormField>
  </div>
</template>
