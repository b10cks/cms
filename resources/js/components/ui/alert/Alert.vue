<script lang="ts" setup>
import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'vue'
import { cn } from '~/lib/utils'

const alertVariants = cva('relative w-full p-3', {
  variants: {
    color: {
      default: 'border-border bg-background text-foreground',
      info: 'border-info-border text-info bg-info-background/10',
      destructive: 'border-destructive-border text-destructive bg-destructive-background/10',
      success: 'border-success-border text-success bg-success-background/10',
      warning: 'border-warning-border text-warning bg-warning-background/10',
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
    <div class="items-top flex gap-2">
      <Icon
        v-if="icon"
        :name="icon"
        class="mt-1 shrink-0"
      />
      <div>
        <slot />
      </div>
    </div>
  </div>
</template>
