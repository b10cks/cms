import { cva, type VariantProps } from 'class-variance-authority'

export { default as Toggle } from './Toggle.vue'

export const toggleVariants = cva(
  'inline-flex items-center justify-center gap-2 cursor-pointer text-sm font-medium transition-colors duration-100 hover:bg-state-hover hover:text-primary active:bg-state-selected focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 data-[state=on]:bg-accent-fill data-[state=on]:text-white data-[state=on]:hover:bg-accent-fill-hover [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default: 'bg-transparent',
        outline:
          'border border-choice-border bg-transparent shadow-xs data-[state=on]:border-accent-fill',
      },
      size: {
        default: 'h-9 px-2 min-w-9 rounded-lg',
        sm: 'h-8 px-1.5 min-w-8 rounded-md',
        lg: 'h-10 px-2.5 min-w-10 rounded-lg',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export type ToggleVariants = VariantProps<typeof toggleVariants>
