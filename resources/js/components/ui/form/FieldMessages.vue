<script setup lang="ts">
defineProps<{
  id: string
  error?: string | null
  description?: string
  /** The field shows the description as a tooltip by its label; keep it here for screen readers only. */
  descriptionInTooltip?: boolean
}>()
</script>

<template>
  <p
    v-if="error"
    :id="`${id}-error`"
    class="mt-1 text-sm leading-tight whitespace-pre-line text-destructive"
  >
    {{ error }}
  </p>
  <p
    v-if="description"
    :id="`${id}-description`"
    :class="descriptionInTooltip ? 'sr-only' : 'text-sm text-muted'"
  >
    {{ description }}
  </p>
</template>
