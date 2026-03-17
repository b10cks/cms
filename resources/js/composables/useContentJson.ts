export function useContentJson(spaceId: MaybeRef<string>) {
  const { useSpaceQuery } = useSpaces()
  const { useTokensQuery } = useTokens(spaceId)

  const { data: space } = useSpaceQuery(toValue(spaceId))
  const { data: tokens } = useTokensQuery()

  const apiToken = computed(() => tokens.value?.[0]?.token ?? null)
  const rv = computed(() =>
    space.value?.updated_at ? new Date(space.value?.updated_at).getTime() : Date.now()
  )

  const buildContentJsonUrl = (vid: 'draft' | 'published' | string) => {
    if (!apiToken.value || !rv.value) return null

    const params = new URLSearchParams({
      vid,
      rv: rv.value / 1000,
      token: apiToken.value,
    })

    return `https://api.b10cks.com/api/v1/contents/_config?${params.toString()}`
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
