import { normalizeLanguageIso } from '~/lib/content-i18n'

const trimSegment = (segment: string): string => segment.trim().replace(/^\/+|\/+$/g, '')

/**
 * All URL path segments a content language can be served under.
 *
 * When `site_locales` are configured, a language may map to several segments
 * (e.g. `de` -> `at-de`, `ch-de`, `de-de` for market-style setups). Without
 * site locales, the legacy `slug_strategy` yields exactly one segment: the
 * language code itself, or `''` when no prefix is prepended.
 *
 * Segments are returned WITHOUT surrounding slashes; `''` means "no prefix".
 */
export function resolveLocaleSegments(
  languageIso: string | null | undefined,
  settings: SpaceSettings | null | undefined
): string[] {
  const normalized = normalizeLanguageIso(languageIso)
  if (!normalized || !settings) return ['']

  const segments = (settings.site_locales ?? [])
    .filter((locale) => normalizeLanguageIso(locale.language) === normalized)
    .map((locale) => trimSegment(locale.segment))
    .filter((segment) => segment !== '')

  if (segments.length > 0) {
    return [...new Set(segments)]
  }

  // Legacy slug_strategy behaviour (segment === language code).
  const strategy = settings.slug_strategy
  const needsPrepend =
    strategy === 'always_prepend' ||
    (strategy === 'prepend_translations' &&
      normalized !== normalizeLanguageIso(settings.default_language))

  return [needsPrepend ? normalized : '']
}

/**
 * The segment to use when building a URL: the preferred one if it is valid
 * for the language, otherwise the first (default) segment.
 */
export function resolveLocaleSegment(
  languageIso: string | null | undefined,
  settings: SpaceSettings | null | undefined,
  preferredSegment?: string | null
): string {
  const segments = resolveLocaleSegments(languageIso, settings)

  if (preferredSegment != null && segments.includes(trimSegment(preferredSegment))) {
    return trimSegment(preferredSegment)
  }

  return segments[0] ?? ''
}

/**
 * Build the preview/live URL for a piece of content: the environment base URL,
 * the resolved locale segment and the content's full slug.
 *
 * Returns `null` when the inputs are insufficient to build a URL.
 */
export function buildPreviewUrl(
  baseUrl: string | null | undefined,
  settings: SpaceSettings | null | undefined,
  languageIso: string | null | undefined,
  fullSlug: string | null | undefined,
  preferredSegment?: string | null
): string | null {
  if (!baseUrl || !languageIso || !fullSlug) return null

  const segment = resolveLocaleSegment(languageIso, settings, preferredSegment)
  const base = baseUrl.replace(/\/$/, '')
  const prefix = segment ? `/${segment}` : ''

  return `${base}${prefix}${fullSlug}`
}
