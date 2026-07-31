<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    content: string
    colors?: Array<string>
  }>(),
  {
    colors: () => ['#000000', '#000000'],
  }
)

const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')

const processedContent = computed(() => {
  const regex = /\*\*([^*]+)\*\*/g
  // Escaped like the content: the colours end up inside a quoted attribute of a
  // `v-html` string, so an unescaped quote would break out of it.
  const colors = escapeHtml(props.colors.join(', '))

  return escapeHtml(props.content).replace(
    regex,
    `<span style="background-image: linear-gradient(to right, ${colors})" class="bg-clip-text text-transparent">$1</span>`
  )
})
</script>

<template>
  <div v-html="processedContent" />
</template>
