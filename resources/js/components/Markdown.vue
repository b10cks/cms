<script setup lang="ts">
import { marked } from 'marked'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

const props = defineProps<{ content: string | CleanTranslation }>()
const router = useRouter()

const html = computed(() => {
  return marked.parse(props.content as string)
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
    window.open(url, '_blank')
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
