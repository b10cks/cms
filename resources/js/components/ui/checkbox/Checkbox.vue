<script setup lang="ts">
import type { CheckboxRootEmits, CheckboxRootProps } from 'reka-ui'
import { CheckboxIndicator, CheckboxRoot, useForwardPropsEmits } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

const props = defineProps<CheckboxRootProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<CheckboxRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <CheckboxRoot
    v-slot="{ state }"
    v-bind="forwarded"
    :class="
      cn(
        'peer h-4 w-4 shrink-0 cursor-pointer rounded-sm border border-choice-border bg-input shadow-xs transition-colors duration-100 hover:border-choice-border-hover focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring aria-invalid:border-destructive disabled:cursor-not-allowed disabled:border-choice-border disabled:opacity-50 disabled:shadow-none data-[state=checked]:border-accent-fill data-[state=checked]:bg-accent-fill data-[state=checked]:text-white data-[state=indeterminate]:border-accent-fill data-[state=indeterminate]:bg-accent-fill data-[state=indeterminate]:text-white',
        props.class
      )
    "
  >
    <CheckboxIndicator class="flex h-full w-full items-center justify-center text-current">
      <slot>
        <Icon
          :name="state === 'indeterminate' ? 'lucide:minus' : 'lucide:check'"
          size="14"
          class="[&_*]:stroke-[3]"
        />
      </slot>
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
