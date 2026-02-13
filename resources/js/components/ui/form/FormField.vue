<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import type { HTMLAttributes } from 'vue'
import Label from '~/components/ui/form/Label.vue'
import { TooltipIcon } from '~/components/ui/tooltip'

const props = defineProps<{
  id?: string
  label?: string | CleanTranslation
  required?: boolean
  hideLabel?: boolean
  tooltip?: string | CleanTranslation
  description?: string | CleanTranslation
  name: string
  error?: string
  class?: HTMLAttributes['class']
}>()

const uniqueId = computed(
  () => props.id || `${props.name}-${Math.random().toString(36).substring(2, 9)}`
)
const hasError = computed(() => !!props.error)
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
      <TooltipIcon v-if="tooltip">{{ tooltip }}</TooltipIcon>
    </div>
    <slot
      v-bind="{
        id: uniqueId,
        name: props.name,
      }"
      :has-error="hasError"
    />
    <p
      v-if="hasError"
      class="mt-1 text-sm leading-tight whitespace-pre-line text-destructive"
    >
      {{ error }}
    </p>
    <p
      v-if="description"
      class="text-sm text-text-muted"
    >
      {{ description }}
    </p>
  </div>
</template>
