<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-dbzap"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <ellipse
      cx="12"
      cy="5"
      rx="9"
      ry="3"
    />
    <path d="M3 5V19A9 3 0 0 0 15 21.84" />
    <path d="M21 5V8" />
    <path
      class="icn-dbzap-bolt"
      pathLength="1"
      d="M21 12L18 17H22L19 22"
    />
    <path d="M3 12A9 3 0 0 0 14.59 14.87" />
  </svg>
</template>

<style>
.icn-dbzap-bolt {
  stroke-dasharray: 1;
}
.icn-dbzap:hover .icn-dbzap-bolt,
.icon-anim:hover .icn-dbzap-bolt {
  animation: icn-dbzap-strike 0.9s ease-out forwards;
}
@keyframes icn-dbzap-strike {
  0% {
    stroke-dashoffset: 1;
    opacity: 0;
  }
  35% {
    stroke-dashoffset: 0;
    opacity: 1;
  }
  50% {
    opacity: 0.4;
  }
  65% {
    opacity: 1;
  }
  80% {
    opacity: 0.5;
  }
  100% {
    stroke-dashoffset: 0;
    opacity: 1;
  }
}
</style>
