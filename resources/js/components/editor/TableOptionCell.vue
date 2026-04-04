<script setup lang="ts">
import { SelectField } from '~/components/ui/form'
import { useFieldOptionChoices } from '~/composables/useFieldOptionChoices'

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{
  column: Extract<TableColumn, { type: 'option' }>
  name: string
  spaceId: string
  modelValue: string | null
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
  keydown: [event: KeyboardEvent]
}>()

const attrs = useAttrs()

const { choices, isLoading } = useFieldOptionChoices(
  computed(() => props.spaceId),
  computed(() => props.column)
)
</script>

<template>
  <SelectField
    v-bind="attrs"
    :name="name"
    :model-value="modelValue"
    :disabled="readOnly"
    :clearable="true"
    :placeholder="isLoading ? 'labels.blocks.fields.optionPreviewLoading' : 'common.select'"
    :empty-text="
      isLoading
        ? 'labels.blocks.fields.optionPreviewLoading'
        : 'labels.blocks.fields.optionPreviewEmpty'
    "
    :options="choices.map((choice) => ({ label: choice.label, value: choice.value }))"
    @update:model-value="emit('update:modelValue', $event ? String($event) : null)"
    @keydown="emit('keydown', $event)"
  />
</template>
