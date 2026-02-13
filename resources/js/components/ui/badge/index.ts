import type { VariantProps } from 'class-variance-authority'

import { cva } from 'class-variance-authority'

export { default as Badge } from './Badge.vue'
export { default as SplitBadge } from './SplitBadge.vue'

export const badgeVariants = cva(
  'inline-flex items-center rounded-md border font-semibold focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-secondary text-secondary-foreground',
        ai: 'border-transparent bg-ai text-ai-foreground',
        destructive: 'border-transparent bg-destructive-background text-destructive-foreground',
        accent: 'border-transparent bg-accent text-accent-foreground',
        warning: 'border-transparent bg-warning-background text-warning-foreground',
        info: 'border-transparent bg-info-background text-info-foreground',
        success: 'border-transparent bg-success-background text-success-foreground',
        surface: 'border-transparent bg-surface text-foreground',
        secondary: 'border-transparent bg-secondary text-secondary-foreground',
        primary: 'border-transparent bg-primary text-primary-foreground',
      },
      variant_style: {
        default: '',
        outline: 'border-border bg-transparent',
      },
      size: {
        dot: 'text-xs px-1 uppercase',
        xs: 'text-xs px-1.5 py-0.5 uppercase',
        sm: 'text-xs px-2 py-0.5',
        default: 'text-sm px-2.5 py-0.5',
        lg: 'text-lg px-2.5 py-0.5',
      },
    },
    compoundVariants: [
      {
        variant: 'ai',
        variant_style: 'outline',
        class: 'border-ai text-ai',
      },
      {
        variant: 'destructive',
        variant_style: 'outline',
        class: 'border-destructive-background text-destructive-foreground',
      },
      {
        variant: 'accent',
        variant_style: 'outline',
        class: 'border-accent text-accent',
      },
      {
        variant: 'warning',
        variant_style: 'outline',
        class: 'border-warning-background text-warning-foreground',
      },
      {
        variant: 'info',
        variant_style: 'outline',
        class: 'border-info-background text-info-foreground',
      },
      {
        variant: 'success',
        variant_style: 'outline',
        class: 'border-success-background text-success-foreground',
      },
      {
        variant: 'surface',
        variant_style: 'outline',
        class: 'border-surface text-foreground',
      },
      {
        variant: 'secondary',
        variant_style: 'outline',
        class: 'border-secondary text-secondary-foreground',
      },
      {
        variant: 'primary',
        variant_style: 'outline',
        class: 'border-primary text-primary',
      },
      {
        variant: 'default',
        variant_style: 'outline',
        class: 'border-secondary text-secondary-foreground',
      },
    ],
    defaultVariants: {
      variant: 'default',
      variant_style: 'default',
      size: 'default',
    },
  }
)

export type BadgeVariants = VariantProps<typeof badgeVariants>
