import { cva, type VariantProps } from 'class-variance-authority'

export { default as Avatar } from './Avatar.vue'
export { default as AvatarList } from './AvatarList.vue'

export const avatarVariants = cva(
  'bg-accent overflow-clip flex items-center justify-center text-primary',
  {
    variants: {
      size: {
        sm: 'size-4 rounded-md',
        default: 'size-8 rounded-xl',
        lg: 'size-12 rounded-2xl',
      },
    },
    defaultVariants: {
      size: 'default',
    },
  }
)

export type AvatarVariants = VariantProps<typeof avatarVariants>
