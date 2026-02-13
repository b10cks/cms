<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

defineProps<{
  label: CleanTranslation | string
  hideLabel?: boolean
  hasError?: boolean
  required?: boolean
}>()
</script>

<template>
  <label
    :class="[
      'relative z-0 text-sm font-semibold transition-colors duration-200',
      hideLabel && 'sr-only',
      hasError ? 'text-destructive' : 'text-primary',
    ]"
  >
    <slot>
      {{ label }}
    </slot>
    <span
      v-if="required"
      class="ml-1 text-destructive"
      >*</span
    >
  </label>
</template>
