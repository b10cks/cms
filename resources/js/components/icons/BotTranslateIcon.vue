<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: '1rem' })
</script>

<template>
  <svg
    class="icn icn-bot icn-bot-translate"
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="currentColor"
  >
    <path
      d="M16.89,27.04l1.39-1.39c-.13-.23-.24-.48-.36-.73-4.04.31-8.13-.35-11.92-2-1.5-4.04-1.49-8.46,0-12.5,6.39-2.78,13.61-2.78,19.99,0,.85,2.3,1.2,4.72,1.08,7.14h1.06l3,3v.48c.5-.33.86-.93.86-1.5v-5.71c0-.74-.56-1.49-1.24-1.72l-1.59-.77c-.29-1.05-.6-1.99-1.01-3l-2.44-1.02V2.71s-2.78-1.32-2.78-1.32l-1.07,4.81c-3.91-.84-7.84-.85-11.75,0l-1.07-4.81-2.78,1.32v4.61s-2.42,1-2.42,1c-.42.99-.74,1.99-1.04,3.03l-1.64.79C.57,12.34,0,13.08,0,13.75v5.78c0,.73.56,1.48,1.24,1.71l1.59.77c.29,1.05.6,2.01,1,2.98,4.14,1.99,8.61,2.9,13.06,2.76v-.71Z"
    />
    <path
      class="icn-bot-eye"
      d="M9.91,12.65c1.57-.3,2.97.74,3.26,2.21s-.71,2.97-2.21,3.26-2.93-.67-3.24-2.14.6-3.02,2.2-3.32Z"
    />
    <path
      class="icn-bot-eye"
      d="M21.04,12.65c1.57-.3,2.97.74,3.26,2.21s-.71,2.97-2.21,3.26-2.93-.67-3.24-2.14.6-3.02,2.2-3.32Z"
    />
    <path
      class="icn-bot-translate-glyph"
      d="M24.9,31.95l2.88-7.6h1.33l2.88,7.6h-1.33l-.68-1.93h-3.07l-.68,1.93h-1.33ZM19.9,30.05l-.89-.89,3.2-3.2c-.37-.37-.7-.79-1.01-1.27s-.58-1.01-.83-1.62h1.33c.21.41.42.77.63,1.08.21.31.46.61.76.92.35-.35.71-.84,1.09-1.47.38-.63.66-1.23.85-1.8h-7.03v-1.27h4.43v-1.27h1.27v1.27h4.43v1.27h-1.84c-.22.76-.55,1.54-1,2.34-.44.8-.88,1.41-1.31,1.84l1.52,1.55-.48,1.3-1.93-1.98-3.2,3.18ZM27.31,28.91h2.28l-1.14-3.23-1.14,3.23Z"
    />
  </svg>
</template>

<style>
.icn-bot-translate-glyph {
  transform-box: fill-box;
  transform-origin: 50% 100%;
}
.icn-bot-translate:hover .icn-bot-translate-glyph,
.icon-anim:hover .icn-bot-translate .icn-bot-translate-glyph {
  animation: icn-bot-translate-swap 0.8s ease-in-out;
}
.icn-bot-translate:hover .icn-bot-eye,
.icon-anim:hover .icn-bot-translate .icn-bot-eye {
  animation: icn-bot-blink 0.8s ease-in-out;
}
@keyframes icn-bot-translate-swap {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  30% {
    transform: translateY(-12%) rotate(-10deg);
  }
  65% {
    transform: translateY(-4%) rotate(8deg);
  }
}
.icn-bot-eye {
  transform-box: fill-box;
  transform-origin: center;
}
@keyframes icn-bot-blink {
  0%,
  30%,
  50%,
  100% {
    transform: scaleY(1);
  }
  40% {
    transform: scaleY(0.1);
  }
}
</style>
