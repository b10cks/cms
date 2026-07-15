<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-waypoints"
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
      class="icn-waypoints-draw"
      pathLength="1"
      cx="12"
      cy="4.5"
      r="2.5"
    />
    <path
      class="icn-waypoints-draw icn-waypoints-d1"
      pathLength="1"
      d="m10.2 6.3-3.9 3.9"
    />
    <circle
      class="icn-waypoints-draw"
      pathLength="1"
      cx="4.5"
      cy="12"
      r="2.5"
    />
    <path
      class="icn-waypoints-draw icn-waypoints-d2"
      pathLength="1"
      d="M7 12h10"
    />
    <circle
      class="icn-waypoints-draw"
      pathLength="1"
      cx="19.5"
      cy="12"
      r="2.5"
    />
    <path
      class="icn-waypoints-draw icn-waypoints-d3"
      pathLength="1"
      d="m13.8 17.7 3.9-3.9"
    />
    <circle
      class="icn-waypoints-draw"
      pathLength="1"
      cx="12"
      cy="19.5"
      r="2.5"
    />
  </svg>
</template>

<style>
.icn-waypoints-draw {
  stroke-dasharray: 1;
}
.icn-waypoints:hover .icn-waypoints-draw,
.icon-anim:hover .icn-waypoints-draw {
  animation: icn-waypoints-trace 0.5s ease forwards;
}
.icn-waypoints:hover .icn-waypoints-d1,
.icon-anim:hover .icn-waypoints-d1 {
  animation-delay: 0.15s;
}
.icn-waypoints:hover .icn-waypoints-d2,
.icon-anim:hover .icn-waypoints-d2 {
  animation-delay: 0.3s;
}
.icn-waypoints:hover .icn-waypoints-d3,
.icon-anim:hover .icn-waypoints-d3 {
  animation-delay: 0.45s;
}
@keyframes icn-waypoints-trace {
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
