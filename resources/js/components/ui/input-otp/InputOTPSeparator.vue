<script setup lang="ts">
import { useForwardProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import Icon from '~/components/Icon.vue'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()

const forwarded = useForwardProps(props)
</script>

<template>
  <div
    data-slot="input-otp-separator"
    role="separator"
    v-bind="forwarded"
  >
    <slot>
      <Icon name="lucide:minus" />
    </slot>
  </div>
</template>
