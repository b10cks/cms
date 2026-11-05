<script setup lang="ts">
import type { DialogRootEmits, DialogRootProps } from 'reka-ui'
import { useForwardPropsEmits } from 'reka-ui'

import { Dialog, DialogContent } from '@/components/ui/dialog'

import Command from './Command.vue'

const props = defineProps<DialogRootProps>()
const emits = defineEmits<DialogRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <Dialog v-bind="forwarded">
    <DialogContent
      class="overflow-hidden !p-0 [--menu-row-ring:transparent] [--overlay-edge:var(--popover-border)] [&_[data-dialog-close]]:top-2.5 [&_[data-dialog-close]]:right-2.5"
      :scroll-body="false"
    >
      <Command
        class="[&_[cmdk-input-wrapper]]:pr-11 [&_[cmdk-input-wrapper]_input]:h-12 [&_[cmdk-input-wrapper]_input]:text-base"
      >
        <slot />
      </Command>
    </DialogContent>
  </Dialog>
</template>
