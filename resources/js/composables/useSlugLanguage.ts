/**
 * The language a space's identifier-style slugs should transliterate for.
 *
 * Mirrors `App\Services\Slug\SlugLanguage::current()`: block slugs, data source
 * slugs and icon keys are space-level names, so they follow the space's default
 * language rather than any one entry's. Content slugs do not use this — they
 * carry their own `language_iso`.
 *
 * Reads through the cached space query, so this costs nothing on a page that
 * already loaded the space.
 */
export function useSlugLanguage(spaceId: MaybeRefOrGetter<string | null | undefined>) {
  const { useSpaceQuery } = useSpaces()
  const { data: space } = useSpaceQuery(spaceId)

  return computed(() => space.value?.settings?.default_language ?? null)
}
