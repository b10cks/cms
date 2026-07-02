<script setup lang="ts">
import { marked } from 'marked'
import { sanitizeHtml } from '~/lib/sanitize'

const props = defineProps<{ content: string }>()
const router = useRouter()

const html = computed(() => {
  // marked passes raw HTML through untouched, so sanitize before v-html.
  return sanitizeHtml(marked.parse(props.content as string) as string)
})

function onClick(e: MouseEvent) {
  if (!((e.target as HTMLElement).tagName === 'A')) {
    return
  }

  const url = new URL((e.target as HTMLLinkElement).href, window.location.origin)
  if (url.protocol === 'mailto:') {
    return
  }

  e.preventDefault()
  if (url.host !== location.host) {
    // open href in new window
    window.open(url, '_blank', 'noopener,noreferrer')
    return
  }

  router.push(url.pathname)
}
</script>

<template>
  <div
    class="prose"
    @click.capture="onClick"
    v-html="html"
  />
</template>
