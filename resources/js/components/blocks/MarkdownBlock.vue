<script setup lang="ts">
defineProps<{ value: MarkdownSchema }>()
</script>

<template>
  <div>
    {{ value }}
  </div>
</template>
