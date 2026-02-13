<script setup lang="ts">
defineProps<{ value: NumberSchema }>()
</script>

<template>
  <div>
    {{ value }}
  </div>
</template>
