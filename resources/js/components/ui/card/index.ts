import type { VariantProps } from 'class-variance-authority'
import { cva } from 'class-variance-authority'

export { default as Card } from './Card.vue'
export { default as CardContent } from './CardContent.vue'
export { default as CardDescription } from './CardDescription.vue'
export { default as CardFooter } from './CardFooter.vue'
export { default as CardHeader } from './CardHeader.vue'
export { default as CardHeaderCombined } from './CardHeaderCombined.vue'
export { default as CardTitle } from './CardTitle.vue'

export const cardVariants = cva('', {
  variants: {
    variant: {
      none: 'pb-12',
      default: 'rounded-xl p-6 bg-card text-card-foreground shadow-soft',
      surface: 'rounded-xl p-6 bg-surface shadow-soft',
      outline: 'rounded-xl p-6 border border-border',
      accent: 'rounded-xl p-6 bg-accent text-white shadow-soft',
      warning: 'rounded-xl p-6 bg-warning-background/20 text-warning shadow-sm',
      destructive: 'rounded-xl p-6 bg-destructive-background/20 text-destructive shadow-sm',
      destructiveOutline: 'rounded-xl p-6 border border-destructive text-destructive',
    },
  },
  defaultVariants: {
    variant: 'default',
  },
})

export type CardVariants = VariantProps<typeof cardVariants>
