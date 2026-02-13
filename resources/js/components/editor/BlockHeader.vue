<script setup lang="ts">
import Icon from '~/components/Icon.vue'

const handlebars = useHandlebars()

const props = defineProps<{
  content: any
  block: BlockResource
}>()

const blockTitle = computed(() => {
  if (!props.block) return 'Untitled'
  return props.block.name || props.block.slug || props.block.key || 'Untitled'
})

const guessedTitle = computed(() => {
  if (!props.content) return 'Untitled'
  if (props.block?.preview_template) {
    try {
      return handlebars.render(props.block.preview_template, props.content)
    } catch (_) {
      /* empty */
    }
  }

  const keys = Object.keys(props.content).filter((k) => k !== 'id')
  if (keys.length > 0 && typeof props.content[keys[0]] === 'string') {
    return props.content[keys[0]]
  }

  if (props.content.block) {
    return props.block?.name || 'Untitled Block'
  }

  return 'Untitled'
})
</script>

<template>
  <div
    :draggable="true"
    class="grid shrink-0 cursor-ns-resize"
  >
    <Icon
      v-if="block.icon"
      :name="`lucide:${block.icon}`"
      :style="{ color: block.color }"
      class="grid-area-stack group-hover:opacity-0"
    />
    <Icon
      name="lucide:grip-vertical"
      class="grid-area-stack opacity-0 group-hover:opacity-100"
    />
  </div>
  <div class="grid grow text-left leading-none">
    <h4 class="font-semibold text-primary">{{ guessedTitle }}</h4>
    <div class="flex text-sm text-muted">{{ blockTitle }}</div>
  </div>
</template>
