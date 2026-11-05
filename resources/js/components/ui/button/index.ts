import { cva, type VariantProps } from 'class-variance-authority'

export { default as Button } from './Button.vue'

export const buttonVariants = cva(
  'icon-anim cursor-pointer shrink-0 inline-flex items-center justify-center gap-2 whitespace-nowrap font-semibold transition-colors duration-100 ease-butter focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default:
          'bg-button text-secondary-foreground shadow-xs hover:bg-button-hover active:bg-button-active data-[state=open]:bg-button-hover',
        input:
          'bg-input text-secondary-foreground shadow-xs inset-ring inset-ring-border-strong hover:bg-secondary-hover active:bg-secondary-active data-[state=open]:bg-secondary-hover',
        primary: 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90 active:bg-primary/80',
        accent: 'bg-accent-fill text-white shadow-xs hover:bg-accent-fill-hover active:bg-accent-fill-hover',
        destructive:
          'bg-destructive-background/20 text-destructive-ink shadow-xs hover:bg-destructive-background/30 active:bg-destructive-background/40',
        warning:
          'bg-warning-background/15 text-warning-tint-foreground shadow-xs hover:bg-warning-background/20 active:bg-warning-background/25',
        ai: 'bg-ai-tint text-ai-tint-foreground shadow-xs hover:bg-ai-tint-hover active:bg-ai-tint-hover',
        outline:
          'inset-ring inset-ring-border-strong bg-transparent shadow-xs hover:bg-state-hover active:bg-state-selected data-[state=open]:bg-state-selected',
        ghost: 'hover:bg-state-hover active:bg-state-selected data-[state=open]:bg-state-selected',
        'ghost-destructive':
          'text-muted hover:bg-destructive-background/20 hover:text-destructive-ink active:bg-destructive-background/30',
        link: 'text-primary underline-offset-4 hover:underline !px-0',
      },
      size: {
        default: 'px-4 py-1.75 rounded-lg',
        xs: 'h-7 rounded-md px-2 text-sm',
        sm: 'h-8 rounded-md px-3 text-sm',
        lg: 'h-10 rounded-lg px-8',
        icon: 'h-9 w-9 rounded-lg',
        'icon-sm': 'size-8 rounded-md',
        toolbar: 'size-7 rounded-md px-2 text-sm',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export type ButtonVariants = VariantProps<typeof buttonVariants>
