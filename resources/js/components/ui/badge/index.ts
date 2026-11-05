import type { VariantProps } from 'class-variance-authority'
import { cva } from 'class-variance-authority'

export { default as Badge } from './Badge.vue'
export { default as SplitBadge } from './SplitBadge.vue'

export const badgeVariants = cva(
  'inline-flex items-center rounded-md border font-semibold focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-badge text-secondary-foreground',
        ai: 'border-transparent bg-ai-background text-ai-foreground',
        destructive: 'border-transparent bg-destructive-background text-destructive-foreground',
        accent: 'border-transparent bg-accent-fill text-accent-foreground',
        warning: 'border-transparent bg-warning-background text-warning-foreground',
        info: 'border-transparent bg-info-background text-info-foreground',
        success: 'border-transparent bg-success-background text-success-foreground',
        surface: 'border-transparent bg-surface text-foreground',
        secondary: 'border-transparent bg-badge text-secondary-foreground',
        primary: 'border-transparent bg-primary text-primary-foreground',
      },
      type: {
        default: '',
        outline: 'border-border-strong bg-transparent',
      },
      size: {
        indicator: 'size-2 rounded-full!',
        dot: 'text-[0.5rem] leading-none px-1 py-0.5 uppercase',
        '2xs': 'text-2xs leading-none px-1.5 py-[3px] tracking-wider uppercase',
        xs: 'text-xs px-1.5 py-0.5 uppercase',
        sm: 'text-xs px-2 py-0.5',
        default: 'text-sm px-2.5 py-0.5',
        lg: 'text-lg px-2.5 py-0.5',
      },
    },
    compoundVariants: [
      {
        variant: 'ai',
        type: 'outline',
        class: '!border-ai !text-ai-tint-foreground',
      },
      {
        variant: 'destructive',
        type: 'outline',
        class: '!border-destructive !text-destructive-ink',
      },
      {
        variant: 'accent',
        type: 'outline',
        class: '!border-accent !text-info-ink',
      },
      {
        variant: 'warning',
        type: 'outline',
        class: '!border-warning-background !bg-transparent !text-warning-ink',
      },
      {
        variant: 'info',
        type: 'outline',
        class: '!border-info-background !text-info-ink',
      },
      {
        variant: 'success',
        type: 'outline',
        class: '!border-success-background !text-success-ink',
      },
      {
        variant: 'surface',
        type: 'outline',
        class: '!border-border-strong !bg-transparent !text-foreground',
      },
      {
        variant: 'secondary',
        type: 'outline',
        class: '!border-border-strong !bg-transparent !text-foreground',
      },
      {
        variant: 'primary',
        type: 'outline',
        class: '!border-primary !text-primary',
      },
      {
        variant: 'default',
        type: 'outline',
        class: '!border-border-strong !bg-transparent !text-foreground',
      },
    ],
    defaultVariants: {
      variant: 'default',
      type: 'default',
      size: 'default',
    },
  }
)

export type BadgeVariants = VariantProps<typeof badgeVariants>
