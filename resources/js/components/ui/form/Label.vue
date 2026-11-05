<script setup lang="ts">
defineProps<{
  label: string
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
      hasError ? 'text-destructive-ink' : 'text-primary',
    ]"
  >
    <slot>
      {{ label }}
    </slot>
    <span
      v-if="required"
      class="ml-0.5 text-destructive-ink"
      >*</span
    >
  </label>
</template>
