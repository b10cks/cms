<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-rocket"
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
      class="icn-rocket-flame"
      d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"
    />
    <path
      d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"
    />
    <path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0" />
    <path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5" />
  </svg>
</template>

<style>
.icn-rocket:hover,
.icon-anim:hover .icn-rocket {
  animation: icn-rocket-shake 3s ease-in-out infinite;
}
.icn-rocket-flame {
  transform-box: fill-box;
  transform-origin: 100% 0;
}
.icn-rocket:hover .icn-rocket-flame,
.icon-anim:hover .icn-rocket-flame {
  animation: icn-rocket-burn 1s ease-in-out infinite;
}
@keyframes icn-rocket-shake {
  0%,
  100% {
    transform: translate(0, 0);
  }
  15% {
    transform: translate(0, -2px);
  }
  30% {
    transform: translate(-2px, 0);
  }
  45% {
    transform: translate(1px, -1px);
  }
  60% {
    transform: translate(-1px, -2px);
  }
  80% {
    transform: translate(1px, -1px);
  }
}
@keyframes icn-rocket-burn {
  0%,
  100% {
    transform: scale(1);
  }
  30% {
    transform: scale(1.2);
  }
  60% {
    transform: scale(0.85);
  }
  80% {
    transform: scale(1.1);
  }
}
</style>
