<script setup lang="ts">
useSeoMeta({
  titleTemplate: (title) => {
    return [title, 'b10cks'].filter((e) => e).join(' – ')
  },
})
</script>

<template>
  <div class="flex min-h-svh w-full flex-col">
    <slot />
  </div>
</template>
