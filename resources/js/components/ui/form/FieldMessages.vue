<script setup lang="ts">
defineProps<{
  id: string
  error?: string | null
  description?: string
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
    class="text-sm text-text-muted"
  >
    {{ description }}
  </p>
</template>
