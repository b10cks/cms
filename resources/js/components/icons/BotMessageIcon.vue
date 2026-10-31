<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: '1rem' })
</script>

<template>
  <svg
    class="icn icn-bot icn-bot-message"
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="currentColor"
  >
    <path
      d="M16.73,24.98c-3.65.11-7.31-.58-10.72-2.06-1.5-4.04-1.49-8.46,0-12.5,6.39-2.78,13.61-2.78,19.99,0,.97,2.65,1.3,5.44,1,8.21h3.14c.66,0,1.3.13,1.87.37v-5.17c0-.74-.56-1.49-1.24-1.72l-1.59-.77c-.29-1.05-.6-1.99-1.01-3l-2.44-1.02V2.71s-2.78-1.32-2.78-1.32l-1.07,4.81c-3.91-.84-7.84-.85-11.75,0l-1.07-4.81-2.78,1.32v4.61s-2.42,1-2.42,1c-.42.99-.74,1.99-1.04,3.03l-1.64.79C.57,12.34,0,13.08,0,13.75v5.78c0,.73.56,1.48,1.24,1.71l1.59.77c.29,1.05.6,2.01,1,2.98,4.09,1.97,8.5,2.88,12.89,2.77v-2.78Z"
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
      class="icn-bot-message-bubble"
      d="M30.11,30.24h-5.94s-3.84,1.76-3.84,1.76c-.13,0-.33,0-.42-.03s-.2-.24-.2-.36v-8.24c.02-.99.94-1.74,1.88-1.74h8.53c.95,0,1.86.75,1.86,1.73v5.02c.02,1.02-.81,1.86-1.87,1.86Z"
    />
  </svg>
</template>

<style>
.icn-bot-message-bubble {
  transform-box: fill-box;
  transform-origin: 0% 100%;
}
.icn-bot-message:hover .icn-bot-message-bubble,
.icon-anim:hover .icn-bot-message .icn-bot-message-bubble {
  animation: icn-bot-message-pop 0.7s ease-in-out;
}
.icn-bot-message:hover .icn-bot-eye,
.icon-anim:hover .icn-bot-message .icn-bot-eye {
  animation: icn-bot-blink 0.7s ease-in-out;
}
@keyframes icn-bot-message-pop {
  0%,
  100% {
    transform: scale(1);
  }
  25% {
    transform: scale(0.6);
  }
  60% {
    transform: scale(1.15);
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
