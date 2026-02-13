<script setup lang="ts">
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

const props = defineProps<{
  content: string | CleanTranslation
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
