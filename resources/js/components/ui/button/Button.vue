<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import { Primitive } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import { Spinner } from '~/components/ui/spinner'

import type { ButtonVariants } from '.'
import { buttonVariants } from '.'

interface Props extends PrimitiveProps {
  variant?: ButtonVariants['variant']
  size?: ButtonVariants['size']
  class?: HTMLAttributes['class']
  loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  as: 'button',
})

const root = ref<InstanceType<typeof Primitive>>()
const showSpinner = ref(false)
const lockedWidth = ref<string>()
let spinnerTimer: ReturnType<typeof setTimeout> | undefined

watch(
  () => props.loading,
  (loading) => {
    clearTimeout(spinnerTimer)
    if (loading) {
      // Lock the width before the spinner appears so the button doesn't jump
      const el = root.value?.$el as HTMLElement | undefined
      lockedWidth.value = el?.offsetWidth ? `${el.offsetWidth}px` : undefined
      spinnerTimer = setTimeout(() => {
        showSpinner.value = true
      }, 250)
    } else {
      showSpinner.value = false
      lockedWidth.value = undefined
    }
  }
)

onUnmounted(() => clearTimeout(spinnerTimer))
</script>

<template>
  <Primitive
    ref="root"
    :as="as"
    :as-child="asChild"
    :disabled="loading || undefined"
    :aria-busy="loading || undefined"
    :class="cn(buttonVariants({ variant, size }), props.class)"
    :style="lockedWidth ? { minWidth: lockedWidth } : undefined"
  >
    <Spinner v-if="showSpinner" />
    <slot />
  </Primitive>
</template>
