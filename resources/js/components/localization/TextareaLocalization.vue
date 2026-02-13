<script setup lang="ts">
import { FormField } from '~/components/ui/form'
import { Textarea } from '~/components/ui/textarea'

defineProps<{
  item: TextareaSchema & { key: string }
  originalValue: string
  modelValue: string
  isMachineTranslated?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const updateValue = (e: Event) => {
  const target = e.target as HTMLTextAreaElement
  emit('update:modelValue', target.value)
}
</script>

<template>
  <div
    class="grid grid-cols-2 gap-4 py-2"
    :aria-labelledby="`${item.key}-label`"
  >
    <FormField
      :name="`${item.key}-original`"
      :label="item.name || item.key"
      hide-label
    >
      <Textarea
        :model-value="originalValue"
        disabled
        rows="4"
        class="resize-none"
        :aria-label="`Original ${item.name || item.key}`"
      />
    </FormField>
    <FormField
      :name="`${item.key}-translation`"
      :label="item.name || item.key"
      hide-label
    >
      <Textarea
        :model-value="modelValue"
        rows="4"
        :class="['resize-none', isMachineTranslated && 'ring-1 ring-violet-500']"
        :placeholder="originalValue"
        @input="updateValue"
      />
    </FormField>
  </div>
</template>
