import type { VariantProps } from 'class-variance-authority'

import { cva } from 'class-variance-authority'

export { default as Card } from './Card.vue'
export { default as CardContent } from './CardContent.vue'
export { default as CardDescription } from './CardDescription.vue'
export { default as CardFooter } from './CardFooter.vue'
export { default as CardHeader } from './CardHeader.vue'
export { default as CardHeaderCombined } from './CardHeaderCombined.vue'
export { default as CardTitle } from './CardTitle.vue'

export const cardVariants = cva('rounded-xl', {
  variants: {
    variant: {
      default: 'bg-card text-card-foreground shadow-soft',
      outline: 'border border-border',
      accent: 'bg-accent text-white shadow-soft',
      warning: 'bg-warning-background/20 text-warning shadow-sm',
      destructive: 'bg-destructive-background/20 text-destructive shadow-sm',
      destructiveOutline: 'border border-destructive text-destructive',
    },
  },
  defaultVariants: {
    variant: 'default',
  },
})

export type CardVariants = VariantProps<typeof cardVariants>
