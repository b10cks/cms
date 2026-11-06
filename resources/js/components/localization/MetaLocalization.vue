<script setup lang="ts">
import SourceCopyButton from '~/components/localization/SourceCopyButton.vue'
import TranslationClearButton from '~/components/localization/TranslationClearButton.vue'
import { FormField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { Textarea } from '~/components/ui/textarea'

interface MetaValue {
  title?: string
  description?: string
  canonical?: string | Record<string, unknown> | null
  robots?: string
  ogTitle?: string
  ogDescription?: string
}

type MetaTextKey = 'title' | 'description' | 'ogTitle' | 'ogDescription'

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

const updateValue = (key: MetaTextKey, value: string | number) => {
  emit('update:modelValue', {
    ...props.modelValue,
    [key]: value,
  })
}

const { t } = useI18n()

const metaParts = [
  {
    key: 'title',
    multiline: false,
    og: false,
    sourceLabel: 'labels.contents.localization.meta.originalTitle',
    translationLabel: 'labels.contents.localization.meta.translationTitle',
  },
  {
    key: 'description',
    multiline: true,
    og: false,
    sourceLabel: 'labels.contents.localization.meta.originalDescription',
    translationLabel: 'labels.contents.localization.meta.translationDescription',
  },
  {
    key: 'ogTitle',
    multiline: true,
    og: true,
    sourceLabel: 'labels.contents.localization.meta.originalOgTitle',
    translationLabel: 'labels.contents.localization.meta.translationOgTitle',
  },
  {
    key: 'ogDescription',
    multiline: true,
    og: true,
    sourceLabel: 'labels.contents.localization.meta.originalOgDescription',
    translationLabel: 'labels.contents.localization.meta.translationOgDescription',
  },
] as const satisfies ReadonlyArray<{ key: MetaTextKey } & Record<string, unknown>>

const parts = computed(() =>
  metaParts
    .filter((part) => !part.og || props.item.has_og_tags)
    .map((part) => ({
      ...part,
      source: props.originalValue?.[part.key] ?? '',
      translation: props.modelValue[part.key] ?? '',
    }))
)
</script>

<template>
  <div class="space-y-4 py-2">
    <div class="grid grid-cols-2 gap-4 text-sm font-semibold text-muted">
      <div>{{ t('labels.contents.localization.sourceColumn') }}</div>
      <div>{{ t('labels.contents.localization.translationColumn') }}</div>
    </div>
    <div
      class="grid grid-cols-2 items-start gap-4"
      :aria-labelledby="`${item.key}-label`"
    >
      <template
        v-for="part in parts"
        :key="part.key"
      >
        <FormField
          :name="`${item.key}-${part.key}-original`"
          :label="t(part.sourceLabel)"
        >
          <template #default="{ id }">
            <div class="relative">
              <Textarea
                v-if="part.multiline"
                :id="id"
                :model-value="part.source"
                :auto-size="600"
                readonly
                rows="2"
                class="resize-none pr-10"
                tabindex="-1"
              />
              <Input
                v-else
                :id="id"
                :model-value="part.source"
                readonly
                class="pr-10"
                tabindex="-1"
              />
              <SourceCopyButton
                v-if="part.source"
                :text="part.source"
                class="absolute top-1 right-1.5"
              />
            </div>
          </template>
        </FormField>
        <FormField
          :name="`${item.key}-${part.key}-translation`"
          :label="t(part.translationLabel)"
        >
          <template #default="{ id }">
            <div class="relative">
              <Textarea
                v-if="part.multiline"
                :id="id"
                :model-value="part.translation"
                :auto-size="600"
                :disabled="disabled"
                rows="2"
                :class="['resize-none pr-10', isMachineTranslated && 'ring-1 ring-ai']"
                @update:model-value="updateValue(part.key, $event)"
              />
              <Input
                v-else
                :id="id"
                :model-value="part.translation"
                :disabled="disabled"
                :class="['pr-10', isMachineTranslated && 'ring-1 ring-ai']"
                @update:model-value="updateValue(part.key, $event)"
              />
              <TranslationClearButton
                v-if="part.translation && !disabled"
                class="absolute top-1 right-1.5"
                @clear="updateValue(part.key, '')"
              />
            </div>
          </template>
        </FormField>
      </template>
    </div>
  </div>
</template>
