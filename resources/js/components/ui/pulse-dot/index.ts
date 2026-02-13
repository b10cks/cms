import { cva, type VariantProps } from 'class-variance-authority'

export { default as PulseDot } from './PulseDot.vue'

export const pulseDotVariant = cva('', {
  variants: {
    variant: {
      default: 'bg-input',
      primary: 'bg-primary',
      destructive: 'bg-destructive',
      success: 'bg-success',
    },
    size: {
      xs: 'size-1.5',
      sm: 'size-2',
      default: 'size-3',
      lg: 'size-6',
    },
  },
  defaultVariants: {
    variant: 'default',
    size: 'default',
  },
})

export type PulseDotVariants = VariantProps<typeof pulseDotVariant>
