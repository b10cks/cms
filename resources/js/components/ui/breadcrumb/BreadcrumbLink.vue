<script lang="ts" setup>
import { Primitive, type PrimitiveProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(defineProps<PrimitiveProps & { class?: HTMLAttributes['class'] }>(), {
  as: 'a',
})
</script>

<template>
  <Primitive
    :as="as"
    :as-child="asChild"
    :class="cn('rounded-sm transition-colors duration-100 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring', props.class)"
  >
    <slot />
  </Primitive>
</template>
