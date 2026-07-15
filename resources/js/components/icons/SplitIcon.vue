<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-split"
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
      class="icn-split-draw icn-split-head"
      pathLength="1"
      d="M16 3h5v5"
    />
    <path
      class="icn-split-draw icn-split-head"
      pathLength="1"
      d="M8 3H3v5"
    />
    <path
      class="icn-split-draw icn-split-trunk"
      pathLength="1"
      d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"
    />
    <path
      class="icn-split-draw icn-split-branch"
      pathLength="1"
      d="m15 9 6-6"
    />
  </svg>
</template>

<style>
.icn-split-draw {
  stroke-dasharray: 1;
}
.icn-split:hover .icn-split-trunk,
.icon-anim:hover .icn-split-trunk {
  animation: icn-split-trace 0.5s ease-in-out both;
}
.icn-split:hover .icn-split-branch,
.icon-anim:hover .icn-split-branch {
  animation: icn-split-trace 0.35s ease-out 0.2s both;
}
.icn-split:hover .icn-split-head,
.icon-anim:hover .icn-split-head {
  animation: icn-split-trace 0.25s ease-out 0.42s both;
}
@keyframes icn-split-trace {
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
