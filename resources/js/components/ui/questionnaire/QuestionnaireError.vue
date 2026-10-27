<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import { Primitive } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed, onBeforeUnmount, useId } from 'vue'

import { cn } from '~/lib/utils'
import { useI18n } from '~/plugins/i18n'

import { injectQuestionnaireItemContext } from './useQuestionnaire'

const props = withDefaults(
  defineProps<
    PrimitiveProps & {
      class?: HTMLAttributes['class']
      id?: string
    }
  >(),
  {
    as: 'p',
  }
)

const { t } = useI18n()
const item = injectQuestionnaireItemContext()

const errorId = props.id ?? useId()
const unregisterError = item.registerError(errorId)

const fallback = computed(() =>
  item.required.value
    ? t('components.questionnaire.requiredError')
    : t('components.questionnaire.optionalError')
)

onBeforeUnmount(unregisterError)
</script>

<template>
  <Primitive
    :id="errorId"
    data-slot="questionnaire-error"
    :as="props.as"
    :as-child="props.asChild"
    :data-invalid="item.invalid.value ? '' : undefined"
    :hidden="!item.invalid.value"
    :role="item.invalid.value ? 'alert' : undefined"
    :class="cn('text-sm text-destructive', props.class)"
  >
    <slot :invalid="item.invalid.value">
      {{ fallback }}
    </slot>
  </Primitive>
</template>
