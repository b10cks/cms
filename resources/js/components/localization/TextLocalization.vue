<script setup lang="ts">
import { InputField } from '~/components/ui/form'

defineProps<{
  item: TextSchema & { key: string }
  originalValue: string
  modelValue: string
  isMachineTranslated?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const updateValue = (e: Event) => {
  const target = e.target as HTMLInputElement
  emit('update:modelValue', target.value)
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
      :model-value="originalValue"
      :actions="['copy']"
      readonly
      hide-label
    />
    <InputField
      :name="`${item.key}-translation`"
      :label="item.name || item.key"
      :model-value="modelValue"
      :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
      :placeholder="originalValue"
      hide-label
      @input="updateValue"
    />
  </div>
</template>
