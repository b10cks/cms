<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-images"
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
      class="icn-images-back"
      d="M4 8a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2"
    />
    <g class="icn-images-front">
      <path
        class="icn-images-mountain"
        pathLength="1"
        d="m22 11-1.296-1.296a2.4 2.4 0 0 0-3.408 0L11 16"
      />
      <circle
        class="icn-images-sun"
        cx="13"
        cy="7"
        r="1"
        fill="currentColor"
      />
      <rect
        x="8"
        y="2"
        width="14"
        height="14"
        rx="2"
      />
    </g>
  </svg>
</template>

<style>
.icn-images-back,
.icn-images-front {
  transition: transform 0.35s cubic-bezier(0.34, 1.4, 0.64, 1);
}
.icn-images:hover .icn-images-back,
.icon-anim:hover .icn-images-back {
  transform: translate(-1.5px, 1.5px);
}
.icn-images:hover .icn-images-front,
.icon-anim:hover .icn-images-front {
  transform: translate(1px, -1px);
}
.icn-images-mountain {
  stroke-dasharray: 1;
}
.icn-images:hover .icn-images-mountain,
.icon-anim:hover .icn-images-mountain {
  animation: icn-images-draw 0.45s ease 0.12s both;
}
.icn-images-sun {
  transform-box: fill-box;
  transform-origin: center;
}
.icn-images:hover .icn-images-sun,
.icon-anim:hover .icn-images-sun {
  animation: icn-images-shine 0.55s ease 0.25s;
}
@keyframes icn-images-draw {
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
@keyframes icn-images-shine {
  40% {
    transform: scale(1.7);
  }
  70% {
    transform: scale(0.9);
  }
}
</style>
