<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-pencil"
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
      d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"
    />
    <path d="m15 5 4 4" />
  </svg>
</template>

<style>
.icn-pencil {
  transform-origin: 12% 88%;
}
.icn-pencil:hover,
.icon-anim:hover .icn-pencil {
  animation: icn-pencil-write 0.9s ease-in-out;
}
@keyframes icn-pencil-write {
  0%,
  100% {
    transform: rotate(0) translate(0, 0);
  }
  20% {
    transform: rotate(-7deg) translate(-1px, 0.5px);
  }
  45% {
    transform: rotate(4deg) translate(1px, -0.5px);
  }
  70% {
    transform: rotate(-5deg) translate(-0.5px, 0.5px);
  }
}
</style>
