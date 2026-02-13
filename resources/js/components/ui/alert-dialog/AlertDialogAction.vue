<script setup lang="ts">
import { buttonVariants, type ButtonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { AlertDialogActionProps } from 'reka-ui'
import { AlertDialogAction } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

const props = defineProps<
  AlertDialogActionProps & { variant?: ButtonVariants['variant']; class?: HTMLAttributes['class'] }
>()

const delegatedProps = computed(() => {
  const { variant: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <AlertDialogAction
    v-bind="delegatedProps"
    :class="cn(buttonVariants({ variant: props.variant }), 'mt-2 sm:mt-0', props.class)"
  >
    <slot />
  </AlertDialogAction>
</template>
