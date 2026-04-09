<script setup lang="ts">
import { InputField, TextField } from '~/components/ui/form'

interface MetaValue {
  title?: string
  description?: string
  canonical?: string | Record<string, unknown> | null
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

const { t } = useI18n()
</script>

<template>
  <div class="space-y-4 py-2">
    <div class="grid grid-cols-2 gap-4 text-sm font-semibold text-muted-foreground">
      <div>{{ t('labels.contents.localization.sourceColumn') }}</div>
      <div>{{ t('labels.contents.localization.translationColumn') }}</div>
    </div>
    <div
      class="grid grid-cols-2 gap-4"
      :aria-labelledby="`${item.key}-label`"
    >
      <InputField
        :name="`${item.key}-title-original`"
        :model-value="originalValue?.title"
        :label="t('labels.contents.localization.meta.originalTitle')"
        :actions="['copy']"
        action-tabindex="-1"
        readonly
        tabindex="-1"
      />
      <InputField
        :name="`${item.key}-title-translation`"
        :model-value="modelValue.title"
        :label="t('labels.contents.localization.meta.translationTitle')"
        :disabled="disabled"
        :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
        :placeholder="originalValue?.title"
        @update:model-value="updateValue('title', $event)"
      />
      <TextField
        :name="`${item.key}-description-original`"
        :model-value="originalValue?.description"
        :label="t('labels.contents.localization.meta.originalDescription')"
        :auto-size="600"
        readonly
        tabindex="-1"
      />
      <TextField
        :name="`${item.key}-description-translation`"
        :model-value="modelValue.description"
        :label="t('labels.contents.localization.meta.translationDescription')"
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
          :label="t('labels.contents.localization.meta.originalOgTitle')"
          readonly
          tabindex="-1"
        />
        <TextField
          :name="`${item.key}-ogTitle-translation`"
          :model-value="modelValue.ogTitle"
          :label="t('labels.contents.localization.meta.translationOgTitle')"
          :disabled="disabled"
          :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
          :placeholder="originalValue?.ogTitle"
          @update:model-value="updateValue('ogTitle', $event)"
        />
        <TextField
          :name="`${item.key}-ogDescription-original`"
          :model-value="originalValue?.ogDescription"
          :label="t('labels.contents.localization.meta.originalOgDescription')"
          :auto-size="600"
          readonly
          tabindex="-1"
        />
        <TextField
          :name="`${item.key}-ogDescription-translation`"
          :model-value="modelValue.ogDescription"
          :label="t('labels.contents.localization.meta.translationOgDescription')"
          :auto-size="600"
          :disabled="disabled"
          :input-class="[isMachineTranslated && 'ring-1 ring-ai']"
          :placeholder="originalValue?.ogDescription"
          @update:model-value="updateValue('ogDescription', $event)"
        />
      </template>
    </div>
  </div>
</template>
