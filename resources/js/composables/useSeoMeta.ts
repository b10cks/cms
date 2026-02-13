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

function updateMetaTag(name: string, content: string | undefined) {
  if (!isClient) return

  let meta = document.querySelector(`meta[name="${name}"]`) as HTMLMetaElement | null
  if (content) {
    if (!meta) {
      meta = document.createElement('meta')
      meta.name = name
      document.head.appendChild(meta)
    }
    meta.content = content
  } else if (meta) {
    meta.remove()
  }
}

function updatePropertyMeta(property: string, content: string | undefined) {
  if (!isClient) return

  let meta = document.querySelector(`meta[property="${property}"]`) as HTMLMetaElement | null
  if (content) {
    if (!meta) {
      meta = document.createElement('meta')
      meta.setAttribute('property', property)
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

  const scopedTitleTemplate = options.titleTemplate

  onScopeDispose(() => {
    if (scopedTitleTemplate && currentTitleTemplate === unref(scopedTitleTemplate)) {
      currentTitleTemplate = null
    }
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
        currentTitleTemplate = titleTemplate
      } else if (typeof titleTemplate === 'string') {
        currentTitleTemplate = (t: string) => titleTemplate.replace('%s', t)
      } else {
        currentTitleTemplate = null
      }
    }

    if (title !== undefined) {
      document.title = applyTitleTemplate(title || '')
    }

    updateMetaTag('description', description)
    updatePropertyMeta('og:title', ogTitle || title)
    updatePropertyMeta('og:description', ogDescription || description)
    updatePropertyMeta('og:image', ogImage)
    updatePropertyMeta('og:url', ogUrl)
  })
}
