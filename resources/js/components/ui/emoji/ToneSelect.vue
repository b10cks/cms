<script setup lang="ts">
import { SelectRoot, SelectTrigger } from 'reka-ui'

import { SelectContent, SelectItem } from '~/components/ui/select'

import { type Tone } from './utils'
const { tone, tones } = useTone()

const props = defineProps({
  class: String,
})

const emojiSkinToneColors = {
  neutral: '🟨',
  '1f3fb': '🏻',
  '1f3fc': '🏼',
  '1f3fd': '🏽',
  '1f3fe': '🏾',
  '1f3ff': '🏿',
}
function select(v: Tone) {
  tone.value = v
}
</script>

<template>
  <SelectRoot>
    <SelectTrigger :class="props.class">{{ emojiSkinToneColors[tone] }}</SelectTrigger>
    <SelectContent>
      <SelectItem
        v-for="tone in tones"
        :key="tone"
        :value="tone"
        @select="() => select(tone)"
      >
        {{ emojiSkinToneColors[tone] }}
        {{ $t(`labels.emojis.skinTones.${tone}`) }}
      </SelectItem>
    </SelectContent>
  </SelectRoot>
</template>
