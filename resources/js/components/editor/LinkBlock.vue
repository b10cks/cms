<script setup lang="ts">
import LinkEditor from './LinkEditor.vue'

interface UrlLink {
  type: 'url'
  url: string
  target?: '_self' | '_blank' | '_parent' | '_top'
  rel?: string
}

interface EmailLink {
  type: 'email'
  email?: string
}

interface InternalLink {
  type: 'internal'
  content: string
  anchor?: string
  target?: '_self' | '_blank' | '_parent' | '_top'
}

type LinkValue = UrlLink | EmailLink | InternalLink

const value = defineModel<LinkValue | null>()

defineProps<{
  item: LinkSchema & { key: string }
  spaceId: string
}>()
</script>

<template>
  <div class="space-y-3">
    <div class="space-y-1">
      <label class="font-semibold text-primary">
        {{ item.name || item.key }}
      </label>
      <p
        v-if="item.description"
        class="text-muted-foreground text-xs"
      >
        {{ item.description }}
      </p>
    </div>

    <LinkEditor
      v-model="value"
      :space-id="spaceId"
    />
  </div>
</template>
