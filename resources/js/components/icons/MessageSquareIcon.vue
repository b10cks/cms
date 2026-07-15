<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-message"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
  </svg>
</template>

<style>
.icn-message:hover,
.icon-anim:hover .icn-message {
  animation: icn-message-shake 0.6s ease-in-out;
}
@keyframes icn-message-shake {
  0%,
  100% {
    transform: scale(1) rotate(0);
  }
  30% {
    transform: scale(1.1) rotate(-7deg);
  }
  65% {
    transform: scale(1.05) rotate(6deg);
  }
}
</style>
