<script setup lang="ts">
import { InputField } from '~/components/ui/form'

defineProps<{
  item: TextSchema & { key: string }
  originalValue?: string | null
  modelValue: string
  isMachineTranslated?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const updateValue = (value: string | number) => {
  emit('update:modelValue', String(value ?? ''))
}
</script>

<template>
  <div
    class="grid grid-cols-2 gap-4 py-2"
    :aria-labelledby="`${item.key}-label`"
  >
    <InputField
      :name="`${item.key}-original`"
      :label="item.name || item.key"
      :model-value="originalValue || ''"
      :actions="['copy']"
      action-tabindex="-1"
      readonly
      tabindex="-1"
      hide-label
    />
    <InputField
      :name="`${item.key}-translation`"
      :label="item.name || item.key"
      :model-value="modelValue"
      :disabled="disabled"
      :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
      :placeholder="originalValue || ''"
      hide-label
      @update:model-value="updateValue"
    />
  </div>
</template>
