<script setup lang="ts">
import { emojiToChar, valueToEmoji } from './utils'
const { tone, tones } = useTone()

const props = defineProps<{
  code: string
}>()
const emoji = computed(() => {
  return valueToEmoji(props.code)
})
</script>

<template>
  <span class="emoji">{{ emojiToChar(emoji, tone) }}</span>
</template>
