<script setup lang="ts">
import SourceCopyButton from '~/components/localization/SourceCopyButton.vue'
import TranslationClearButton from '~/components/localization/TranslationClearButton.vue'
import { InputField } from '~/components/ui/form'

defineProps<{
  item: TextSchema & { key: string }
  originalValue?: string | null
  modelValue: string
  isMachineTranslated?: boolean
  disabled?: boolean
  error?: string | null
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
      input-class="pr-10"
      readonly
      tabindex="-1"
      hide-label
    >
      <template
        v-if="originalValue"
        #append
      >
        <SourceCopyButton :text="originalValue" />
      </template>
    </InputField>
    <InputField
      :name="`${item.key}-translation`"
      :label="item.name || item.key"
      :model-value="modelValue"
      :disabled="disabled"
      :error="error"
      :input-class="['pr-10', isMachineTranslated && 'ring-1 ring-ai']"
      data-validation-target="true"
      hide-label
      @update:model-value="updateValue"
    >
      <template
        v-if="modelValue && !disabled"
        #append
      >
        <TranslationClearButton @clear="updateValue('')" />
      </template>
    </InputField>
  </div>
</template>
