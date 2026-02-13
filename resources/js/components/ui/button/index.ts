import { cva, type VariantProps } from 'class-variance-authority'

export { default as Button } from './Button.vue'

export const buttonVariants = cva(
  'cursor-pointer inline-flex items-center justify-center gap-2 whitespace-nowrap font-semibold transition-colors duration-300 ease-butter focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default: 'bg-secondary text-secondary-foreground shadow-sm hover:bg-secondary/80',
        primary: 'bg-primary text-secondary shadow hover:bg-primary/80',
        accent: 'bg-accent text-white shadow-sm hover:bg-accent-hover shadow-lg shadow-accent/20',
        destructive:
          'bg-destructive-background/20 text-destructive shadow-sm hover:bbg-destructive-background/80',
        warning: 'bg-warning-background/20 text-warning shadow-sm hover:bg-warning-background/80',
        outline: 'border border-border bg-transparent shadow-sm hover:bg-input/80',
        ghost: 'hover:bg-secondary/80',
        link: 'text-primary underline-offset-4 hover:underline !px-0',
      },
      size: {
        default: 'px-4 py-1.5 rounded-lg',
        xs: 'h-7 rounded-md px-2 text-xs',
        sm: 'h-8 rounded-md px-3 text-sm',
        lg: 'h-10 rounded-lg px-8',
        icon: 'h-9 w-9 rounded-lg',
        toolbar: 'size-7 rounded-md px-2 text-xs',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export type ButtonVariants = VariantProps<typeof buttonVariants>
