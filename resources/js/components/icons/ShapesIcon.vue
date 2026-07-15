<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-shapes"
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
      class="icn-shapes-shape icn-shapes-triangle"
      d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.198-.043L16.3 8.9a.7.7 0 0 1-.572 1.1Z"
    />
    <rect
      class="icn-shapes-shape icn-shapes-square"
      x="3"
      y="14"
      width="7"
      height="7"
      rx="1"
    />
    <circle
      class="icn-shapes-shape icn-shapes-circle"
      cx="17.5"
      cy="17.5"
      r="3.5"
    />
  </svg>
</template>

<style>
.icn-shapes-shape {
  transform-box: fill-box;
  transform-origin: center;
}
.icn-shapes:hover .icn-shapes-triangle,
.icon-anim:hover .icn-shapes-triangle {
  animation: icn-shapes-rock 0.55s ease-in-out;
}
.icn-shapes:hover .icn-shapes-square,
.icon-anim:hover .icn-shapes-square {
  animation: icn-shapes-tilt 0.55s ease-in-out 0.1s;
}
.icn-shapes:hover .icn-shapes-circle,
.icon-anim:hover .icn-shapes-circle {
  animation: icn-shapes-bounce 0.6s cubic-bezier(0.28, 0.84, 0.42, 1) 0.2s;
}
@keyframes icn-shapes-rock {
  35% {
    transform: scale(1.15) rotate(-8deg);
  }
  70% {
    transform: scale(0.96) rotate(5deg);
  }
}
@keyframes icn-shapes-tilt {
  35% {
    transform: scale(1.12) rotate(8deg);
  }
  70% {
    transform: scale(0.96) rotate(-4deg);
  }
}
@keyframes icn-shapes-bounce {
  30% {
    transform: translateY(-3px);
  }
  50% {
    transform: translateY(0) scale(1.15, 0.85);
  }
  70% {
    transform: translateY(-1.5px);
  }
  85% {
    transform: translateY(0) scale(1.05, 0.95);
  }
}
</style>
