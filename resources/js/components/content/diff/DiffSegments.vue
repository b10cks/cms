<script setup lang="ts">
import type { DiffSegment } from '~/utils/text-diff'

defineProps<{ segments: DiffSegment[] }>()
</script>

<template>
  <span class="whitespace-pre-wrap">
    <template
      v-for="(segment, index) in segments"
      :key="index"
    >
      <ins
        v-if="segment.type === 'added'"
        class="rounded-sm bg-success/25 text-success no-underline"
        >{{ segment.text }}</ins
      >
      <del
        v-else-if="segment.type === 'removed'"
        class="rounded-sm bg-destructive/25 text-destructive line-through decoration-destructive/60"
        >{{ segment.text }}</del
      >
      <span v-else>{{ segment.text }}</span>
    </template>
  </span>
</template>
