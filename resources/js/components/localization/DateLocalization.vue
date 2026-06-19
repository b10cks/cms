<script setup lang="ts">
import { DateTimeField } from '~/components/ui/form'

defineProps<{
  item: DateSchema & { key: string }
  originalValue?: string | null
  modelValue: string
  isMachineTranslated?: boolean
  disabled?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <div
    class="grid grid-cols-2 gap-4 py-2"
    :aria-labelledby="`${item.key}-label`"
  >
    <DateTimeField
      :name="`${item.key}-original`"
      :model-value="originalValue || ''"
      :type="item.format || 'date'"
      readonly
      tabindex="-1"
    />
    <DateTimeField
      :name="`${item.key}-translation`"
      :model-value="modelValue"
      :type="item.format || 'date'"
      :disabled="disabled"
      :error="error || undefined"
      :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
      data-validation-target="true"
      @update:model-value="emit('update:modelValue', $event)"
    />
  </div>
</template>
