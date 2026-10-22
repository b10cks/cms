<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import FieldMessages from '~/components/ui/form/FieldMessages.vue'
import Label from '~/components/ui/form/Label.vue'
import { TooltipIcon } from '~/components/ui/tooltip'
import { useFieldA11y } from '~/composables/useFieldA11y'

const props = defineProps<{
  id?: string
  label?: string
  required?: boolean
  hideLabel?: boolean
  tooltip?: string
  description?: string
  name: string
  error?: string | null
  class?: HTMLAttributes['class']
}>()

const { fieldId: uniqueId, hasError, describedBy, invalid } = useFieldA11y(props)

/** Hints sit behind an info icon by the label, so a form reads as labels and fields. */
const hint = computed(() => [props.tooltip, props.description].filter(Boolean).join('\n\n'))
const descriptionInTooltip = computed(() => !!props.label && !props.hideLabel)
</script>

<template>
  <div :class="['grid w-full items-center gap-2', props.class]">
    <div
      v-if="label"
      class="flex items-center gap-2"
    >
      <Label
        :label="label"
        :hide-label="hideLabel"
        :required="required"
        :for="uniqueId"
        :has-error="hasError"
      />
      <TooltipIcon v-if="hint && !hideLabel">
        <span class="block max-w-xs whitespace-pre-line">{{ hint }}</span>
      </TooltipIcon>
    </div>
    <slot
      v-bind="{
        id: uniqueId,
        name: props.name,
        'aria-describedby': describedBy,
        'aria-invalid': invalid,
      }"
      :has-error="hasError"
    />
    <FieldMessages
      :id="uniqueId"
      :error="error"
      :description="description"
      :description-in-tooltip="descriptionInTooltip"
    />
  </div>
</template>
