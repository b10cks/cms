<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-home"
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
      d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
    />
    <path
      class="icn-home-door"
      pathLength="1"
      d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"
    />
  </svg>
</template>

<style>
.icn-home-door {
  stroke-dasharray: 1;
}
.icn-home:hover .icn-home-door,
.icon-anim:hover .icn-home-door {
  animation: icn-home-draw 0.6s ease forwards;
}
@keyframes icn-home-draw {
  from {
    stroke-dashoffset: 1;
    opacity: 0;
  }
  20% {
    opacity: 1;
  }
  to {
    stroke-dashoffset: 0;
    opacity: 1;
  }
}
</style>
