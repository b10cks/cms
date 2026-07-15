<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-help"
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
    <g class="icn-help-mark">
      <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
      <path d="M12 17h.01" />
    </g>
  </svg>
</template>

<style>
.icn-help-mark {
  transform-box: fill-box;
  transform-origin: center;
}
.icn-help:hover .icn-help-mark,
.icon-anim:hover .icn-help-mark {
  animation: icn-help-wiggle 0.5s ease-in-out;
}
@keyframes icn-help-wiggle {
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
