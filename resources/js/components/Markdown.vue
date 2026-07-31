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
  // The click can land on a child of the link (`[*deep*](/docs)`), so walk up.
  const anchor = (e.target as HTMLElement | null)?.closest('a')
  if (!anchor) {
    return
  }

  const url = new URL(anchor.href, window.location.origin)
  if (url.protocol === 'mailto:') {
    return
  }

  e.preventDefault()
  if (url.host !== location.host) {
    // open href in new window
    window.open(url, '_blank', 'noopener,noreferrer')
    return
  }

  router.push(`${url.pathname}${url.search}${url.hash}`)
}
</script>

<template>
  <div
    class="prose"
    @click.capture="onClick"
    v-html="html"
  />
</template>
