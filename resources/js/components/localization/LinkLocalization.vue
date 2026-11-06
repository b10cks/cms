<script setup lang="ts">
import { computed } from 'vue'

import LinkEditor from '~/components/editor/LinkEditor.vue'
import SourceCopyButton from '~/components/localization/SourceCopyButton.vue'
import { FormField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'

const props = defineProps<{
  item: LinkSchema & { key: string }
  originalValue?: LinkValue | null
  modelValue?: LinkValue | null
  isMachineTranslated?: boolean
  spaceId: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: LinkValue | null]
}>()

const { t } = useI18n()

const displayedTranslationValue = computed<LinkValue | null>(
  () => props.modelValue || props.originalValue || null
)

/** The source link's text parts, each read-only with its own copy button. An entry link has none. */
const sourceParts = computed(() => {
  const link = props.originalValue
  const parts =
    link?.type === 'url'
      ? [{ key: 'url', label: t('labels.link.url'), value: link.url }]
      : link?.type === 'email'
        ? [
            { key: 'email', label: t('labels.link.email'), value: link.email },
            { key: 'subject', label: t('labels.link.subject'), value: link.subject },
            { key: 'body', label: t('labels.link.body'), value: link.body },
          ]
        : []

  return parts.filter((part): part is typeof part & { value: string } => !!part.value)
})

const updateValue = (value: LinkValue | null) => {
  emit('update:modelValue', value)
}
</script>

<template>
  <div
    class="grid grid-cols-2 items-start gap-4 py-2"
    :aria-labelledby="`${props.item.key}-label`"
  >
    <div
      v-if="sourceParts.length"
      class="grid gap-2"
    >
      <FormField
        v-for="part in sourceParts"
        :key="part.key"
        :name="`${props.item.key}-${part.key}-original`"
        :label="part.label"
      >
        <template #default="{ id }">
          <div class="relative">
            <Input
              :id="id"
              :model-value="part.value"
              readonly
              class="pr-10"
              tabindex="-1"
            />
            <SourceCopyButton
              :text="part.value"
              class="absolute top-1 right-1.5"
            />
          </div>
        </template>
      </FormField>
    </div>
    <div
      v-else
      class="pointer-events-none opacity-60"
    >
      <LinkEditor
        :model-value="props.originalValue || null"
        :space-id="spaceId"
        :allow-email="item.email_link_type !== false"
        disabled
      />
    </div>
    <div :class="isMachineTranslated ? 'rounded-md ring-1 ring-ai p-1' : undefined">
      <LinkEditor
        :model-value="displayedTranslationValue"
        :space-id="spaceId"
        :allow-email="item.email_link_type !== false"
        :disabled="disabled"
        @update:model-value="updateValue"
      />
    </div>
  </div>
</template>
