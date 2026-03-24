<script lang="ts" setup>
import type { HTMLAttributes } from 'vue'

import { cn } from '~/lib/utils'

interface AlertDescriptionProps {
  class?: HTMLAttributes['class']
}

const props = defineProps<AlertDescriptionProps>()
</script>

<template>
  <div :class="cn('text-sm [&_p]:leading-relaxed', props.class)">
    <slot />
  </div>
</template>
