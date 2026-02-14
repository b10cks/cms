<script setup lang="ts">

const props = defineProps<{
  content: string
  colors?: Array<string>
}>()

const processedContent = computed(() => {
  const regex = /\*\*([^*]+)\*\*/g
  const colors = props.colors.join(', ')

  return props.content.replace(
    regex,
    `<span style="background-image: linear-gradient(to right, ${colors})" class="bg-clip-text text-transparent">$1</span>`
  )
})
</script>

<template>
  <div v-html="processedContent" />
</template>
