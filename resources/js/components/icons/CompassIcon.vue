<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-compass"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <circle
      cx="12"
      cy="12"
      r="10"
    />
    <polygon
      class="icn-compass-needle"
      points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"
    />
  </svg>
</template>

<style>
.icn-compass-needle {
  transform-box: fill-box;
  transform-origin: center;
  transition: transform 1s cubic-bezier(0.34, 1.2, 0.64, 1);
}
.icn-compass:hover .icn-compass-needle,
.icon-anim:hover .icn-compass-needle {
  transform: rotate(360deg);
}
</style>
