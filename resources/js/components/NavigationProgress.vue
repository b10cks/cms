<script setup lang="ts">
import { navigationProgress } from '~/lib/navigation-progress'
</script>

<template>
  <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 z-[100] h-0.5">
    <div
      v-if="navigationProgress !== 'idle'"
      class="navigation-progress h-full origin-left bg-accent"
      :data-state="navigationProgress"
    />
  </div>
</template>

<style>
/* Eases towards 90% while loading, then fills and fades once the navigation settles. */
.navigation-progress[data-state='loading'] {
  animation: navigation-progress-trickle 8s cubic-bezier(0.1, 0.7, 0.3, 1) forwards;
}

.navigation-progress[data-state='finishing'] {
  opacity: 0;
  transition:
    transform 200ms ease-out,
    opacity 200ms ease-in 200ms;
}

@keyframes navigation-progress-trickle {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(0.9);
  }
}

@media (prefers-reduced-motion: reduce) {
  .navigation-progress[data-state] {
    animation: none;
    transition: opacity 200ms;
  }
}
</style>
