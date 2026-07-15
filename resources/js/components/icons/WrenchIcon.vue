<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-wrench"
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
      d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"
    />
  </svg>
</template>

<style>
.icn-wrench {
  transform-origin: 90% 10%;
}
.icn-wrench:hover,
.icon-anim:hover .icn-wrench {
  animation: icn-wrench-turn 1.05s ease-in-out;
}
@keyframes icn-wrench-turn {
  0%,
  100% {
    transform: rotate(0);
  }
  42% {
    transform: rotate(12deg);
  }
  68% {
    transform: rotate(-14deg);
  }
  88% {
    transform: rotate(4deg);
  }
}
</style>
