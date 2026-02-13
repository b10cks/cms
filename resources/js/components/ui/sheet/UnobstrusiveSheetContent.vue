<script lang="ts" setup>
import type { DialogContentEmits, DialogContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import {
  unobstrusiveSheetContentVariants,
  type UnobstrusiveSheetVariants
} from '.'
import { cn } from '@/lib/utils'
import { unobstrusiveSheetVariants } from '.'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { Button } from '~/components/ui/button'


interface SheetContentProps extends DialogContentProps {
  class?: HTMLAttributes['class']
  side?: UnobstrusiveSheetVariants
}

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<SheetContentProps>()

const emits = defineEmits<DialogContentEmits>()

</script>

<template>
  <ResizablePanelGroup
    :class="cn(unobstrusiveSheetVariants({ side }), props.class)"
    direction="horizontal"
  >
    <ResizablePanel class="pointer-events-none"/>
    <ResizableHandle/>
    <ResizablePanel
      :class="cn(unobstrusiveSheetContentVariants({ side }))"
      :min-size="480"
      size-unit="px"
    >
      <div class="relative flex flex-1">
        <slot/>
        <SimpleTooltip
          :tooltip-markdown="'Close sheet'"
          class="absolute left-1 top-1 z-20"
          shortcut="Esc"
        >
          <Button
            size="icon"
            variant="ghost"
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
