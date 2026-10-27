<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import { Primitive } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

import { cn } from '~/lib/utils'
import { useI18n } from '~/plugins/i18n'

import { injectQuestionnaireRootContext } from './useQuestionnaire'

const props = withDefaults(
  defineProps<
    PrimitiveProps & {
      class?: HTMLAttributes['class']
    }
  >(),
  {
    as: 'div',
  }
)

const { t } = useI18n()
const root = injectQuestionnaireRootContext()

const label = computed(() =>
  root.total.value
    ? t('components.questionnaire.progress', {
        current: root.current.value,
        total: root.total.value,
      })
    : undefined
)
</script>

<template>
  <Primitive
    :aria-label="t('components.questionnaire.progressLabel')"
    aria-live="polite"
    data-slot="questionnaire-progress"
    role="progressbar"
    :aria-valuemax="root.total.value || undefined"
    :aria-valuemin="root.total.value ? 1 : undefined"
    :aria-valuenow="root.total.value ? root.current.value : undefined"
    :aria-valuetext="label"
    :as="props.as"
    :as-child="props.asChild"
    :data-current="root.current.value"
    :data-first="root.first.value ? '' : undefined"
    :data-last="root.last.value ? '' : undefined"
    :data-total="root.total.value"
    :class="
      cn('text-xs min-h-[1lh] w-fit min-w-[14ch] font-medium text-muted tabular-nums', props.class)
    "
  >
    <slot
      :current="root.current.value"
      :first="root.first.value"
      :last="root.last.value"
      :total="root.total.value"
    >
      {{ label }}
    </slot>
  </Primitive>
</template>
