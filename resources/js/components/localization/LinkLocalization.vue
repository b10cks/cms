<script setup lang="ts">
import { computed } from 'vue'

import LinkEditor from '~/components/editor/LinkEditor.vue'

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

const displayedTranslationValue = computed<LinkValue | null>(
  () => props.modelValue || props.originalValue || null
)

const updateValue = (value: LinkValue | null) => {
  emit('update:modelValue', value)
}
</script>

<template>
  <div
    class="grid grid-cols-2 items-start gap-4 py-2"
    :aria-labelledby="`${props.item.key}-label`"
  >
    <div class="pointer-events-none opacity-60">
      <LinkEditor
        :model-value="props.originalValue || null"
        :space-id="spaceId"
        disabled
      />
    </div>
    <div :class="isMachineTranslated ? 'rounded-md ring-1 ring-ai p-1' : undefined">
      <LinkEditor
        :model-value="displayedTranslationValue"
        :space-id="spaceId"
        :disabled="disabled"
        @update:model-value="updateValue"
      />
    </div>
  </div>
</template>
