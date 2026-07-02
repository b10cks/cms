<script setup lang="ts">
import Icon from '~/components/Icon.vue'

const handlebars = useHandlebars()

const props = defineProps<{
  content: any
  block: BlockResource
}>()

const blockTitle = computed(() => {
  if (!props.block) return 'Untitled'
  return props.block.name || props.block.slug || 'Untitled'
})

// guessedTitle is rendered with v-html because the handlebars preview template
// may legitimately emit markup (e.g. the {{image}} helper). The template
// renderer already HTML-escapes every interpolated content value, but the
// non-template fallbacks below use raw content/block strings, so they must be
// escaped here to avoid stored XSS.
const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')

const guessedTitle = computed(() => {
  if (!props.content) return 'Untitled'

  if (props.block?.preview_template) {
    try {
      const rendered = handlebars.render(props.block.preview_template, props.content)?.trim()
      if (rendered) {
        return rendered
      }
    } catch (_) {
      /* empty */
    }
  }

  const keys = Object.keys(props.content).filter((k) => !['id', 'block', 'hidden'].includes(k))
  const firstStringValue = keys.find((key) => typeof props.content[key] === 'string')

  if (firstStringValue) {
    const value = String(props.content[firstStringValue]).trim()
    if (value) {
      return escapeHtml(value)
    }
  }

  if (props.content.block) {
    return escapeHtml(props.block?.name || props.block?.slug || 'Untitled Block')
  }

  return escapeHtml(props.block?.name || props.block?.slug || 'Untitled')
})
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
      class="font-semibold text-primary"
      v-html="guessedTitle"
    ></h4>
    <div class="flex text-sm text-muted">{{ blockTitle }}</div>
  </div>
</template>
