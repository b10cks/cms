<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { HTMLAttributes } from 'vue'
import { type BadgeVariants, badgeVariants } from '.'

const props = withDefaults(
  defineProps<{
    label: string
    value?: string
    variant?: BadgeVariants['variant']
    labelVariant?: BadgeVariants['variant']
    size?: BadgeVariants['size']
    removable?: boolean
    labelClass?: HTMLAttributes['class']
    valueClass?: HTMLAttributes['class']
  }>(),
  {
    variant: 'primary',
    labelVariant: 'surface',
    size: 'default',
    removable: false,
  }
)

const emit = defineEmits<{
  (e: 'remove'): void
}>()

const handleRemove = (event: Event) => {
  event.stopPropagation()
  emit('remove')
}

const iconSize = computed(
  () =>
    ({
      sm: '0.75rem',
      default: '0.875rem',
      lg: '1.125rem',
    })[props.size as 'sm' | 'default' | 'lg']
)
</script>

<template>
  <div
    class="inline-flex items-center font-semibold"
    :class="[`text-${props.size}`]"
  >
    <!-- Label part -->
    <div
      :class="[
        badgeVariants({ variant: labelVariant, size }),
        labelClass,
        'rounded-r-none',
        'border-r-0',
      ]"
    >
      {{ label }}
    </div>

    <!-- Value part -->
    <div
      v-if="value || $slots.default || removable"
      :class="[badgeVariants({ variant, size }), valueClass, 'rounded-l-none', 'flex items-center']"
    >
      <slot>{{ value }}</slot>
      <button
        v-if="removable"
        type="button"
        class="-mr-1 ml-1 inline-flex items-center justify-center rounded-sm hover:bg-current/10 focus:ring-1 focus:ring-current/30 focus:outline-none"
        aria-label="Remove"
        @click="handleRemove"
      >
        <Icon
          name="lucide:x"
          :size="iconSize"
        />
      </button>
    </div>
  </div>
</template>
