<script lang="ts" setup>
import type { DialogContentEmits, DialogContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import { Button } from '~/components/ui/button'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import { SimpleTooltip } from '~/components/ui/tooltip'

import { unobstrusiveSheetContentVariants, type UnobstrusiveSheetVariants } from '.'
import { unobstrusiveSheetVariants } from '.'

interface SheetContentProps extends DialogContentProps {
  class?: HTMLAttributes['class']
  side?: UnobstrusiveSheetVariants['side']
}

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<SheetContentProps>()

// `close` is this wrapper's own signal; the rest are forwarded dialog events.
const emits = defineEmits<DialogContentEmits & { close: [open: boolean] }>()
</script>

<template>
  <ResizablePanelGroup
    :class="cn(unobstrusiveSheetVariants({ side }), props.class)"
    direction="horizontal"
  >
    <ResizablePanel class="pointer-events-none" />
    <ResizableHandle />
    <ResizablePanel
      :class="cn(unobstrusiveSheetContentVariants({ side }))"
      :min-size="480"
      size-unit="px"
    >
      <div class="relative flex flex-1">
        <slot />
        <SimpleTooltip
          :tooltip="'Close sheet'"
          class="absolute left-1 top-1 z-20"
          shortcut="Esc"
        >
          <Button
            size="icon"
            variant="ghost"
            :aria-label="$t('actions.close')"
            @click="emits('close', false)"
          >
            <Icon
              :size="20"
              name="lucide:chevrons-right"
            />
          </Button>
        </SimpleTooltip>
      </div>
    </ResizablePanel>
  </ResizablePanelGroup>
</template>
