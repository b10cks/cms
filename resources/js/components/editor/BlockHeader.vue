<script setup lang="ts">
import Icon from '~/components/Icon.vue';
import { blockItemTitle, type ItemBlock } from '~/lib/blockItemTitle'

const handlebars = useHandlebars()

const props = defineProps<{
  content: any
  block: ItemBlock
}>()

const blockTitle = computed(() => {
  if (!props.block) return 'Untitled'
  return props.block.name || props.block.slug || 'Untitled'
})

// Rendered with v-html: the preview template may emit markup (e.g. the {{image}} helper).
const guessedTitle = computed(() =>
  blockItemTitle(props.content, props.block, (template, data) => handlebars.render(template, data))
)
</script>

<template>
  <div class="flex shrink-0 items-center gap-2">
    <div
      :draggable="true"
      class="flex cursor-ns-resize items-center text-muted-foreground"
    >
      <Icon name="lucide:grip-vertical" />
    </div>
    <div class="relative flex size-4 items-center justify-center">
      <Icon
        v-if="block.icon"
        :name="`lucide:${block.icon}`"
        :style="{ color: block.color }"
        class="shrink-0 transition-opacity group-hover:opacity-0"
      />
      <Icon
        v-else
        name="lucide:box"
        class="shrink-0 text-muted-foreground transition-opacity group-hover:opacity-0"
      />
    </div>
  </div>
  <div class="grid grow text-left leading-none">
    <h4
      class="font-semibold text-primary line-clamp-3"
      v-html="guessedTitle"
    ></h4>
    <div class="flex text-sm text-muted">{{ blockTitle }}</div>
  </div>
</template>
