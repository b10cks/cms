<script setup lang="ts">
import Icon from '~/components/Icon.vue'
</script>

<template>
  <div class="pointer-events-none absolute inset-x-0 bottom-5 z-10 flex justify-center px-4">
    <div
      class="flex w-full max-w-2xl items-center gap-4 rounded-[28px] border border-border bg-background/92 px-5 py-4 shadow-soft-lg backdrop-blur"
    >
      <div class="flex size-12 items-center justify-center rounded-2xl bg-ai-background/25 text-ai">
        <Icon
          name="lucide:wand-sparkles"
          class="size-5"
        />
      </div>
      <div class="min-w-0 flex-1">
        <p class="font-semibold text-primary">
          {{ $t('labels.contents.canvas.aiPlaceholderTitle') }}
        </p>
        <p class="text-sm text-muted">
          {{ $t('labels.contents.canvas.aiPlaceholderDescription') }}
        </p>
      </div>
      <span
        class="rounded-full border border-dashed border-border px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-muted"
      >
        {{ $t('labels.contents.canvas.comingSoon') }}
      </span>
    </div>
  </div>
</template>
