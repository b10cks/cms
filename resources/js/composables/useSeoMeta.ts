import type { MaybeRef } from 'vue'

import { isClient } from '~/lib/env'

export interface SeoMetaOptions {
  title?: MaybeRef<string | undefined>
  titleTemplate?: MaybeRef<string | ((title: string) => string) | undefined>
  description?: MaybeRef<string | undefined>
  ogTitle?: MaybeRef<string | undefined>
  ogDescription?: MaybeRef<string | undefined>
  ogImage?: MaybeRef<string | undefined>
  ogUrl?: MaybeRef<string | undefined>
}

let currentTitleTemplate: ((title: string) => string) | null = null

function applyTitleTemplate(title: string): string {
  if (currentTitleTemplate) {
    try {
      return currentTitleTemplate(title)
    } catch {
      return title
    }
  }
  return title
}

type MetaKind = 'name' | 'property'

function findMetaTag(kind: MetaKind, key: string): HTMLMetaElement | null {
  return document.querySelector(`meta[${kind}="${key}"]`) as HTMLMetaElement | null
}

function updateMeta(kind: MetaKind, key: string, content: string | undefined) {
  if (!isClient) return

  let meta = findMetaTag(kind, key)
  if (content) {
    if (!meta) {
      meta = document.createElement('meta')
      meta.setAttribute(kind, key)
      document.head.appendChild(meta)
    }
    meta.content = content
  } else if (meta) {
    meta.remove()
  }
}

export function useSeoMeta(options: SeoMetaOptions) {
  if (!isClient) {
    return
  }

  // The template *this* scope installed. A string template is wrapped in a
  // closure before it is stored, so comparing against `options.titleTemplate`
  // never matched and the template outlived the page that set it.
  let ownTitleTemplate: ((title: string) => string) | null = null

  // What this scope last wrote per tag, so dispose can drop the tags it still
  // owns without stealing ones a later page has since overwritten.
  const written = new Map<string, { kind: MetaKind; key: string; content: string }>()

  const write = (kind: MetaKind, key: string, content: string | undefined) => {
    updateMeta(kind, key, content)
    const id = `${kind}:${key}`
    if (content) written.set(id, { kind, key, content })
    else written.delete(id)
  }

  onScopeDispose(() => {
    if (ownTitleTemplate && currentTitleTemplate === ownTitleTemplate) {
      currentTitleTemplate = null
    }

    for (const { kind, key, content } of written.values()) {
      const meta = findMetaTag(kind, key)
      if (meta?.content === content) meta.remove()
    }
    written.clear()
  })

  watchEffect(() => {
    const title = unref(options.title)
    const titleTemplate = unref(options.titleTemplate)
    const description = unref(options.description)
    const ogTitle = unref(options.ogTitle)
    const ogDescription = unref(options.ogDescription)
    const ogImage = unref(options.ogImage)
    const ogUrl = unref(options.ogUrl)

    if (titleTemplate !== undefined) {
      if (typeof titleTemplate === 'function') {
        ownTitleTemplate = titleTemplate
      } else if (typeof titleTemplate === 'string') {
        ownTitleTemplate = (t: string) => titleTemplate.replace('%s', t)
      } else {
        ownTitleTemplate = null
      }
      currentTitleTemplate = ownTitleTemplate
    }

    if (title !== undefined) {
      document.title = applyTitleTemplate(title || '')
    }

    write('name', 'description', description)
    write('property', 'og:title', ogTitle || title)
    write('property', 'og:description', ogDescription || description)
    write('property', 'og:image', ogImage)
    write('property', 'og:url', ogUrl)
  })
}
