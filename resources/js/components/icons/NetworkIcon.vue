<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 24 })
</script>

<template>
  <svg
    class="icn icn-network"
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
      class="icn-network-node icn-network-top"
      x="9"
      y="2"
      width="6"
      height="6"
      rx="1"
    />
    <path
      class="icn-network-link icn-network-l1"
      pathLength="1"
      d="M12 12V8"
    />
    <path
      class="icn-network-link icn-network-l2"
      pathLength="1"
      d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"
    />
    <rect
      class="icn-network-node icn-network-n1"
      x="2"
      y="16"
      width="6"
      height="6"
      rx="1"
    />
    <rect
      class="icn-network-node icn-network-n2"
      x="16"
      y="16"
      width="6"
      height="6"
      rx="1"
    />
  </svg>
</template>

<style>
.icn-network-link {
  stroke-dasharray: 1;
}
.icn-network-node {
  transform-box: fill-box;
  transform-origin: center;
}
.icn-network:hover .icn-network-top,
.icon-anim:hover .icn-network-top {
  animation: icn-network-pop 0.35s ease;
}
.icn-network:hover .icn-network-l1,
.icon-anim:hover .icn-network-l1 {
  animation: icn-network-drop 0.3s ease-out 0.08s both;
}
.icn-network:hover .icn-network-l2,
.icon-anim:hover .icn-network-l2 {
  animation: icn-network-spread 0.35s ease-out 0.28s both;
}
.icn-network:hover .icn-network-n1,
.icon-anim:hover .icn-network-n1 {
  animation: icn-network-pop 0.35s ease 0.5s;
}
.icn-network:hover .icn-network-n2,
.icon-anim:hover .icn-network-n2 {
  animation: icn-network-pop 0.35s ease 0.56s;
}
@keyframes icn-network-drop {
  from {
    stroke-dashoffset: -1;
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
@keyframes icn-network-spread {
  from {
    stroke-dasharray: 0 1;
    stroke-dashoffset: -0.5;
    opacity: 0;
  }
  30% {
    opacity: 1;
  }
  to {
    stroke-dasharray: 1 1;
    stroke-dashoffset: 0;
    opacity: 1;
  }
}
@keyframes icn-network-pop {
  50% {
    transform: scale(1.18);
  }
}
</style>
