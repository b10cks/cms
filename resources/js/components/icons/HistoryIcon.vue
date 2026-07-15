<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-history"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <g class="icn-history-arrow">
      <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
      <path d="M3 3v5h5" />
    </g>
    <line
      class="icn-history-hour"
      x1="12"
      x2="12"
      y1="12"
      y2="7"
    />
    <line
      class="icn-history-minute"
      x1="12"
      x2="16"
      y1="12"
      y2="14"
    />
  </svg>
</template>

<style>
.icn-history-arrow,
.icn-history-hour,
.icn-history-minute {
  transform-box: view-box;
  transform-origin: 12px 12px;
}
.icn-history-arrow {
  transition: transform 0.5s cubic-bezier(0.34, 1.4, 0.64, 1);
}
.icn-history-hour {
  transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}
.icn-history-minute {
  transition: transform 0.5s ease-in-out;
}
.icn-history:hover .icn-history-arrow,
.icon-anim:hover .icn-history-arrow {
  transform: rotate(-50deg);
}
.icn-history:hover .icn-history-hour,
.icon-anim:hover .icn-history-hour {
  transform: rotate(-360deg);
}
.icn-history:hover .icn-history-minute,
.icon-anim:hover .icn-history-minute {
  transform: rotate(-45deg);
}
</style>
