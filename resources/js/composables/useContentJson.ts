import type { ContentResource } from '~/types/contents'

import { runtimeConfig } from '~/lib/runtime-config'

export function useContentJson(
  spaceId: MaybeRef<string>,
  content?: MaybeRef<Pick<ContentResource, 'full_slug' | 'language_iso'> | null | undefined>
) {
  const { useSpaceQuery } = useSpaces()
  const { useTokensQuery } = useTokens(spaceId)

  const { data: space } = useSpaceQuery(spaceId)
  const { data: tokens } = useTokensQuery()

  const apiToken = computed(() => tokens.value?.[0]?.token ?? null)
  const rv = computed(() =>
    space.value?.updated_at ? new Date(space.value.updated_at).getTime() : Date.now()
  )

  const buildContentJsonUrl = (vid: 'draft' | 'published' | string) => {
    const activeContent = content ? toValue(content) : null
    const slug = activeContent?.full_slug?.replace(/^\/+/, '')
    if (!apiToken.value || !slug || !activeContent?.language_iso) return null

    const params = new URLSearchParams({
      vid,
      // Whole seconds only — a fractional cache-buster just splits the cache.
      rv: String(Math.floor(rv.value / 1000)),
      token: apiToken.value,
      language: activeContent.language_iso,
    })

    return `${runtimeConfig.public.apiBaseUrl}/api/v1/contents/${slug}?${params.toString()}`
  }

  const openContentJsonInNewTab = (vid: 'draft' | 'published' | string) => {
    const url = buildContentJsonUrl(vid)
    if (!url) return

    window.open(url, '_blank', 'noopener,noreferrer')
  }

  return {
    apiToken,
    rv,
    buildContentJsonUrl,
    openContentJsonInNewTab,
  }
}
