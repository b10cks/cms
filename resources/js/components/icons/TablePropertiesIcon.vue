<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-table-properties"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <rect
      class="icn-table-properties-draw icn-table-properties-frame"
      pathLength="1"
      width="18"
      height="18"
      x="3"
      y="3"
      rx="2"
    />
    <path
      class="icn-table-properties-draw icn-table-properties-divider"
      pathLength="1"
      d="M15 3v18"
    />
    <path
      class="icn-table-properties-draw icn-table-properties-row"
      pathLength="1"
      d="M21 9H3"
    />
    <path
      class="icn-table-properties-draw icn-table-properties-row"
      pathLength="1"
      d="M21 15H3"
    />
  </svg>
</template>

<style>
.icn-table-properties-draw {
  stroke-dasharray: 1;
}
.icn-table-properties:hover .icn-table-properties-frame,
.icon-anim:hover .icn-table-properties-frame {
  animation: icn-table-properties-trace 0.5s ease-in-out both;
}
.icn-table-properties:hover .icn-table-properties-divider,
.icon-anim:hover .icn-table-properties-divider {
  animation: icn-table-properties-trace 0.3s ease-out 0.28s both;
}
.icn-table-properties:hover .icn-table-properties-row,
.icon-anim:hover .icn-table-properties-row {
  animation: icn-table-properties-trace 0.3s ease-out both;
}
.icn-table-properties:hover .icn-table-properties-row:nth-of-type(3),
.icon-anim:hover .icn-table-properties-row:nth-of-type(3) {
  animation-delay: 0.4s;
}
.icn-table-properties:hover .icn-table-properties-row:nth-of-type(4),
.icon-anim:hover .icn-table-properties-row:nth-of-type(4) {
  animation-delay: 0.5s;
}
@keyframes icn-table-properties-trace {
  from {
    stroke-dashoffset: 1;
    opacity: 0;
  }
  30% {
    opacity: 1;
  }
  to {
    stroke-dashoffset: 0;
    opacity: 1;
  }
}
</style>
