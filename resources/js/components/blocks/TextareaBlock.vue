<script setup lang="ts">
defineProps<{ value: TextareaSchema }>()
</script>

<template>
  <div>
    {{ value }}
  </div>
</template>
