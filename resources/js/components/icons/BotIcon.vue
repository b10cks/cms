<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: '1rem' })
</script>

<template>
  <svg
    class="icn icn-bot icn-bot-plain"
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="currentColor"
  >
    <path
      class="icn-bot-head"
      d="M30.78,21.23l-1.62.79c-.28,1.04-.6,2.01-1,2.98-7.72,3.7-16.61,3.71-24.33,0-.4-.96-.72-1.93-1-2.98l-1.59-.77C.57,21.01,0,20.26,0,19.53v-5.78c0-.67.57-1.41,1.18-1.62l1.64-.79c.3-1.04.62-2.04,1.04-3.03l2.42-1V2.71s2.78-1.32,2.78-1.32l1.07,4.81c3.91-.85,7.84-.84,11.75,0l1.07-4.81,2.78,1.32v4.6s2.44,1.02,2.44,1.02c.4,1.01.72,1.95,1.01,3l1.59.77c.67.23,1.24.99,1.24,1.72v5.71c0,.7-.54,1.46-1.22,1.7ZM26,22.93c1.5-4.11,1.48-8.48,0-12.51-6.38-2.77-13.61-2.77-19.99,0-1.49,4.04-1.49,8.46,0,12.5,6.37,2.77,13.61,2.77,19.99,0Z"
    />
    <path
      class="icn-bot-eye"
      d="M9.91,12.65c1.57-.3,2.97.74,3.26,2.21s-.71,2.97-2.21,3.26-2.93-.67-3.24-2.14.6-3.02,2.2-3.32Z"
    />
    <path
      class="icn-bot-eye"
      d="M21.04,12.65c1.57-.3,2.97.74,3.26,2.21s-.71,2.97-2.21,3.26-2.93-.67-3.24-2.14.6-3.02,2.2-3.32Z"
    />
  </svg>
</template>

<style>
.icn-bot-plain {
  transform-origin: 50% 70%;
}
.icn-bot-plain:hover,
.icon-anim:hover .icn-bot-plain {
  animation: icn-bot-tilt 0.8s ease-in-out;
}
.icn-bot-plain:hover .icn-bot-eye,
.icon-anim:hover .icn-bot-plain .icn-bot-eye {
  animation: icn-bot-blink 0.8s ease-in-out;
}
@keyframes icn-bot-tilt {
  0%,
  100% {
    transform: rotate(0);
  }
  30% {
    transform: rotate(-10deg);
  }
  65% {
    transform: rotate(7deg);
  }
}
.icn-bot-eye {
  transform-box: fill-box;
  transform-origin: center;
}
@keyframes icn-bot-blink {
  0%,
  30%,
  50%,
  100% {
    transform: scaleY(1);
  }
  40% {
    transform: scaleY(0.1);
  }
}
</style>
