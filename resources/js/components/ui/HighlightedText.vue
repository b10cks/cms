<script setup lang="ts">
const props = defineProps<{
  text: string
  /** Character positions to emphasize, e.g. the indices of a fuzzy match. */
  highlight?: number[]
}>()

// Contiguous runs of highlighted / plain characters, so matched characters can
// be emphasized without one span per character.
const segments = computed(() => {
  if (!props.highlight?.length || !props.text) {
    return [{ text: props.text, hit: false }]
  }

  const hits = new Set(props.highlight)
  const result: { text: string; hit: boolean }[] = []

  for (let index = 0; index < props.text.length; index++) {
    const hit = hits.has(index)
    const last = result[result.length - 1]

    if (last && last.hit === hit) {
      last.text += props.text[index]
    } else {
      result.push({ text: props.text[index], hit })
    }
  }

  return result
})
</script>

<template>
  <span
    v-for="(segment, index) in segments"
    :key="index"
    :class="segment.hit ? 'font-bold text-info' : ''"
  >{{ segment.text }}</span>
</template>
