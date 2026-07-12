<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import { Primitive } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import Icon from '~/components/Icon.vue'

import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '../dropdown-menu'
import { Spinner } from '../spinner'
import type { ButtonVariants } from './index'
import { buttonVariants } from './index'

interface Props extends PrimitiveProps {
  variant?: ButtonVariants['variant']
  size?: ButtonVariants['size']
  class?: HTMLAttributes['class']
  primaryAction?: () => void
  disabled?: boolean
  menuDisabled?: boolean
  hasMenu?: boolean
  loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  as: 'div',
  variant: 'default',
  size: 'default',
  disabled: false,
  menuDisabled: false,
  hasMenu: true,
})

const primaryButtonClasses = computed(() => {
  return [
    buttonVariants({ variant: props.variant, size: props.size }),
    props.hasMenu ? 'rounded-r-none' : null,
    props.class,
  ].filter(Boolean)
})

const triggerButtonClasses = computed(() => {
  return [
    buttonVariants({ variant: props.variant, size: props.size }),
    'rounded-l-none border-l border-l-surface !px-2',
  ].filter(Boolean)
})
</script>

<template>
  <Primitive
    :as="as"
    :as-child="asChild"
    class="flex"
  >
    <DropdownMenu>
      <button
        :class="primaryButtonClasses"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        @click="primaryAction && primaryAction()"
      >
        <Spinner v-if="loading" />
        <slot />
      </button>
      <DropdownMenuTrigger
        v-if="hasMenu"
        :disabled="menuDisabled || loading"
        :class="triggerButtonClasses"
      >
        <Icon name="lucide:chevron-down" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <slot name="menu" />
      </DropdownMenuContent>
    </DropdownMenu>
  </Primitive>
</template>
