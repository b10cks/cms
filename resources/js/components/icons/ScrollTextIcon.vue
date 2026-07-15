<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-scroll"
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
      class="icn-scroll-line icn-scroll-l2"
      pathLength="1"
      d="M15 12h-5"
    />
    <path
      class="icn-scroll-line"
      pathLength="1"
      d="M15 8h-5"
    />
    <path d="M19 17V5a2 2 0 0 0-2-2H4" />
    <path
      d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"
    />
  </svg>
</template>

<style>
.icn-scroll-line {
  stroke-dasharray: 1;
}
.icn-scroll:hover .icn-scroll-line,
.icon-anim:hover .icn-scroll-line {
  animation: icn-scroll-write 0.35s ease-out both;
}
.icn-scroll:hover .icn-scroll-l2,
.icon-anim:hover .icn-scroll-l2 {
  animation-delay: 0.28s;
}
@keyframes icn-scroll-write {
  from {
    stroke-dashoffset: -1;
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
