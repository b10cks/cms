<script setup lang="ts">
defineProps<{ value: any }>()
</script>

<template>
  <div>
    <span
      v-if="value === null"
      class="text-muted italic"
      >null</span
    >
    <span v-else-if="typeof value === 'string'">"{{ value }}"</span>
    <span v-else-if="typeof value === 'number'">{{ value }}</span>
    <span v-else-if="typeof value === 'boolean'">{{ value }}</span>
    <pre
      v-else
      class="whitespace-pre-wrap text-foreground"
      >{{ JSON.stringify(value, null, 2) }}</pre
    >
  </div>
</template>
