<script setup lang="ts">
import { InputField, TextField } from '~/components/ui/form'

interface MetaValue {
  title?: string
  description?: string
  canonical?: string
  robots?: string
  ogTitle?: string
  ogDescription?: string
}

const props = defineProps<{
  item: MetaSchema & { key: string }
  originalValue: MetaValue | null
  modelValue: MetaValue
  isMachineTranslated?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: MetaValue]
}>()

const updateValue = (key: keyof MetaValue, value: string | number) => {
  emit('update:modelValue', {
    ...props.modelValue,
    [key]: value,
  })
}
</script>

<template>
  <div
    class="grid grid-cols-2 gap-4 py-2"
    :aria-labelledby="`${item.key}-label`"
  >
    <InputField
      :name="`${item.key}-title-original`"
      :model-value="originalValue?.title"
      :actions="['copy']"
      action-tabindex="-1"
      readonly
      tabindex="-1"
    />
    <InputField
      :name="`${item.key}-title-translation`"
      :model-value="modelValue.title"
      :disabled="disabled"
      :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
      :placeholder="originalValue?.title"
      hide-label
      @update:model-value="updateValue('title', $event)"
    />
    <TextField
      :name="`${item.key}-description-original`"
      :model-value="originalValue?.description"
      :auto-size="600"
      readonly
      tabindex="-1"
    />
    <TextField
      :name="`${item.key}-description-translation`"
      :model-value="modelValue.description"
      :auto-size="600"
      :disabled="disabled"
      :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
      :placeholder="originalValue?.description"
      @update:model-value="updateValue('description', $event)"
    />
    <template v-if="item.has_og_tags">
      <TextField
        :name="`${item.key}-ogTitle-original`"
        :model-value="originalValue?.ogTitle"
        readonly
        tabindex="-1"
      />
      <TextField
        :name="`${item.key}-ogTitle-translation`"
        :model-value="modelValue.ogTitle"
        :disabled="disabled"
        :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
        :placeholder="originalValue?.ogTitle"
        @update:model-value="updateValue('ogTitle', $event)"
      />
      <TextField
        :name="`${item.key}-ogDescription-original`"
        :model-value="originalValue?.ogDescription"
        :auto-size="600"
        readonly
        tabindex="-1"
      />
      <TextField
        :name="`${item.key}-ogDescription-translation`"
        :model-value="modelValue.ogDescription"
        :auto-size="600"
        :disabled="disabled"
        :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
        :placeholder="originalValue?.ogDescription"
        @update:model-value="updateValue('ogDescription', $event)"
      />
    </template>
  </div>
</template>
