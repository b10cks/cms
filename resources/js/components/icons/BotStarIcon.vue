<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: '1rem' })
</script>

<template>
  <svg
    class="icn icn-bot icn-bot-star"
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="currentColor"
  >
    <path
      d="M16.1,25.83c-.02-.28,0-.56.06-.84-3.45.02-6.91-.67-10.15-2.07-1.5-4.04-1.49-8.46,0-12.5,6.39-2.78,13.61-2.78,19.99,0,.73,1.98,1.1,4.04,1.11,6.12.48.27.81.61.98.8l.51.85,1.09,2.76c.14.29.33.4.47.45l.16.06.46-.23c.68-.24,1.21-.99,1.21-1.7v-5.71c0-.74-.56-1.49-1.24-1.72l-1.59-.77c-.29-1.05-.6-1.99-1.01-3l-2.44-1.02V2.71s-2.78-1.32-2.78-1.32l-1.07,4.81c-3.91-.84-7.84-.85-11.75,0l-1.07-4.81-2.78,1.32v4.61s-2.42,1-2.42,1c-.42.99-.74,1.99-1.04,3.03l-1.64.79C.57,12.34,0,13.08,0,13.75v5.78c0,.73.56,1.48,1.24,1.71l1.59.77c.29,1.05.6,2.01,1,2.98,4.16,2,8.66,2.92,13.13,2.76-.48-.54-.8-1.21-.87-1.92Z"
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
      class="icn-bot-star-spark"
      d="M25.83,31.77c-.04.11-.21.24-.3.23s-.25-.13-.29-.21l-1.06-2.67c-.39-.99-1.14-1.79-2.14-2.19l-2.72-1.08c-.1-.04-.23-.19-.23-.28s.11-.28.21-.32l2.61-1.03c1.03-.41,1.82-1.14,2.25-2.16l1.1-2.77c.06-.08.2-.19.28-.19s.22.11.29.19l1.12,2.83c.42.95,1.15,1.68,2.12,2.06l2.76,1.09c.08.03.19.22.19.3s-.13.23-.21.26l-2.78,1.1c-.98.39-1.73,1.16-2.11,2.13l-1.07,2.69Z"
    />
  </svg>
</template>

<style>
.icn-bot-star-spark {
  transform-box: fill-box;
  transform-origin: center;
}
.icn-bot-star:hover .icn-bot-star-spark,
.icon-anim:hover .icn-bot-star .icn-bot-star-spark {
  animation: icn-bot-star-spin 0.8s ease-in-out;
}
.icn-bot-star:hover .icn-bot-eye,
.icon-anim:hover .icn-bot-star .icn-bot-eye {
  animation: icn-bot-blink 0.8s ease-in-out;
}
/* The spark has 4-fold symmetry, so ending at 180deg lands on the start pose. */
@keyframes icn-bot-star-spin {
  0% {
    transform: rotate(0) scale(1);
  }
  50% {
    transform: rotate(90deg) scale(1.35);
  }
  100% {
    transform: rotate(180deg) scale(1);
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
