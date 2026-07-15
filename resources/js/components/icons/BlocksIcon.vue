<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-blocks"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path
      d="M10 21V8a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-5a1 1 0 0 0-1-1H3"
    />
    <path
      class="icn-blocks-brick"
      d="M14 3h7v7h-7z"
    />
  </svg>
</template>

<style>
.icn-blocks-brick {
  transition: transform 0.3s ease;
}
.icn-blocks:hover .icn-blocks-brick,
.icon-anim:hover .icn-blocks-brick {
  transform: translate(-4px, 4px);
}
</style>
