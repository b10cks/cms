<script setup lang="ts">
import { FormField } from '~/components/ui/form'
import { Textarea } from '~/components/ui/textarea'

defineProps<{
  item: TextareaSchema & { key: string }
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
    class="grid grid-cols-2 items-start gap-4 py-2"
    :aria-labelledby="`${item.key}-label`"
  >
    <FormField
      :name="`${item.key}-original`"
      :label="item.name || item.key"
      hide-label
    >
      <Textarea
        :model-value="originalValue || ''"
        :auto-size="600"
        disabled
        rows="4"
        class="resize-none"
        tabindex="-1"
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
        :auto-size="600"
        :disabled="disabled"
        rows="4"
        :class="['resize-none', isMachineTranslated && 'ring-1 ring-violet-500']"
        :placeholder="originalValue || ''"
        @update:model-value="updateValue"
      />
    </FormField>
  </div>
</template>
