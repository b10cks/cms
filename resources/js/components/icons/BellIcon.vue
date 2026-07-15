<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-bell"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
  </svg>
</template>

<style>
.icn-bell:hover,
.icon-anim:hover .icn-bell {
  animation: icn-bell-ring 0.5s ease-in-out;
}
@keyframes icn-bell-ring {
  0%,
  100% {
    transform: rotate(0);
  }
  25%,
  75% {
    transform: rotate(-10deg);
  }
  50% {
    transform: rotate(10deg);
  }
}
</style>
