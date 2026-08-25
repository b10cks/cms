<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from 'reka-ui'
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  useForwardPropsEmits,
} from 'reka-ui'
import type { ComponentPublicInstance, HTMLAttributes } from 'vue'
import { computed, ref } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'
import { useI18n } from '~/plugins/i18n'

const props = withDefaults(
  defineProps<
    DialogContentProps & {
      class?: HTMLAttributes['class']
      /** Classes for the scrollable body that wraps the default slot. */
      bodyClass?: HTMLAttributes['class']
      /**
       * Wrap the slot in a scrollable body so tall dialogs stay inside the viewport.
       * Set to false when the dialog has a fixed height and scrolls its own regions.
       */
      scrollBody?: boolean
      /** Opt in to Cmd/Ctrl+Enter for this dialog's primary action. */
      submitShortcut?: boolean
    }
  >(),
  { scrollBody: true }
)
const emits = defineEmits<DialogContentEmits & { submit: [event: KeyboardEvent] }>()

const delegatedProps = computed(() => {
  const {
    class: _class,
    bodyClass: _bodyClass,
    scrollBody: _scrollBody,
    submitShortcut: _submitShortcut,
    ...delegated
  } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)

// Only set once the portal has actually mounted the content, so a closed
// dialog never claims the chord.
const contentRef = ref<ComponentPublicInstance | null>(null)

if (props.submitShortcut) {
  const { t } = useI18n()

  useShortcut({
    keys: 'mod+enter',
    scope: 'dialog',
    description: () => t('shortcuts.dialog.submit'),
    allowInInput: true,
    allowInOverlay: true,
    enabled: () => contentRef.value !== null,
    handler: (event) => emits('submit', event),
  })
}
</script>

<template>
  <DialogPortal>
    <DialogOverlay
      class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-overlay backdrop-blur-xs"
    />
    <DialogContent
      ref="contentRef"
      v-bind="forwarded"
      :class="
        cn(
          'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95  fixed top-1/2 left-1/2 z-50 flex max-h-[calc(100dvh-2rem)] w-full max-w-lg -translate-x-1/2 -translate-y-1/2 flex-col bg-background p-6 shadow-soft-lg duration-200 sm:rounded-lg',
          props.class
        )
      "
    >
      <div
        v-if="props.scrollBody"
        :class="cn('-mx-1 grid min-h-0 flex-1 gap-4 overflow-y-auto px-1', props.bodyClass)"
      >
        <slot />
      </div>
      <slot v-else />
      <DialogClose
        class="absolute top-4 right-4 z-20 flex cursor-pointer items-center rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none disabled:pointer-events-none data-[state=open]:bg-accent data-[state=open]:text-muted"
      >
        <Icon name="lucide:x" />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
