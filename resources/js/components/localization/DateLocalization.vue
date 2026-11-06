<script setup lang="ts">
import SourceCopyButton from '~/components/localization/SourceCopyButton.vue'
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
    <div class="relative">
      <DateTimeField
        :name="`${item.key}-original`"
        :model-value="originalValue || ''"
        :type="item.format || 'date'"
        input-class="pr-10"
        readonly
        tabindex="-1"
      />
      <SourceCopyButton
        v-if="originalValue"
        :text="originalValue"
        class="absolute top-1 right-1.5"
      />
    </div>
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
