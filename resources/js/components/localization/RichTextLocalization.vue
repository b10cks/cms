<script setup lang="ts">
import TiptapEditor from '~/components/editor/TiptapEditor.vue'
import { FormField } from '~/components/ui/form'

const props = defineProps<{
  item: RichTextSchema & { key: string }
  originalValue: Record<string, unknown>
  modelValue: Record<string, unknown>
  isMachineTranslated?: boolean
  spaceId?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const htmlClasses = computed(
  () => (props.item.html_classes || []) as Array<{ name: string; className: string; css?: string }>
)

const updateValue = (value: Record<string, unknown>) => {
  emit('update:modelValue', value)
}
</script>

<template>
  <div
    class="grid grid-cols-2 gap-4 py-2"
    :aria-labelledby="`${props.item.key}-label`"
  >
    <FormField
      :name="`${props.item.key}-original`"
      :label="props.item.name || props.item.key"
      hide-label
    >
      <div class="pointer-events-none opacity-60">
        <TiptapEditor
          :model-value="originalValue"
          :html-classes="htmlClasses"
          :space-id="spaceId"
          disabled
          @update:model-value="() => {}"
        />
      </div>
    </FormField>
    <FormField
      :name="`${props.item.key}-translation`"
      :label="props.item.name || props.item.key"
      hide-label
    >
      <div :class="[isMachineTranslated && 'rounded ring-1 ring-violet-500']">
        <TiptapEditor
          :model-value="modelValue"
          :html-classes="htmlClasses"
          :space-id="spaceId"
          @update:model-value="updateValue"
        />
      </div>
    </FormField>
  </div>
</template>
