<script lang="ts" setup>
import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'vue'

import Icon from '~/components/Icon.vue'
import { cn } from '~/lib/utils'

const alertVariants = cva('relative w-full p-3', {
  variants: {
    color: {
      default: 'border-border-strong bg-background text-foreground',
      info: 'border-info/40 text-info-ink bg-info-background/10',
      destructive: 'border-destructive/40 text-destructive-ink bg-destructive/10',
      success: 'border-success/40 text-success-ink bg-success-background/10',
      warning: 'border-warning/40 text-warning-tint-foreground bg-warning-background/10',
    },
    variant: {
      default: 'rounded-lg border',
      modern: 'border-l-3',
      outline: 'rounded-lg border bg-transparent',
    },
  },
  defaultVariants: {
    variant: 'default',
    color: 'default',
  },
})

type AlertVariants = VariantProps<typeof alertVariants>

interface AlertProps {
  icon?: string
  variant?: AlertVariants['variant']
  color?: AlertVariants['color']
  class?: HTMLAttributes['class']
}

const props = withDefaults(defineProps<AlertProps>(), {
  variant: 'default',
  color: 'default',
})
</script>

<template>
  <div
    :class="cn(alertVariants({ variant, color }), props.class)"
    role="alert"
  >
    <div class="flex items-start gap-2">
      <Icon
        v-if="icon"
        :name="icon"
        class="mt-0.5 shrink-0"
      />
      <div>
        <slot />
      </div>
    </div>
  </div>
</template>
