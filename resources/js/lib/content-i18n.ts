import type {
  ContentI18nMode,
  ContentLanguageVersion,
  ContentResource,
  ContentSettings,
  CreateContentPayload,
  UpdateContentPayload,
} from '~/types/contents'

export type ContentEditorRouteName =
  | 'space-content-contentId'
  | 'space-content-contentId-localization'
  | 'space-content-contentId-versions'

export function normalizeLanguageIso(languageIso: string | null | undefined): string | undefined {
  if (typeof languageIso !== 'string') {
    return undefined
  }

  const normalized = languageIso.trim().toLowerCase()
  return normalized.length > 0 ? normalized : undefined
}

export function getContentDefaultLanguage(
  defaultLanguage: string | null | undefined,
  languageVersions: ContentLanguageVersion[] | null | undefined,
  fallbackLanguage: string | null | undefined
): string {
  return (
    normalizeLanguageIso(defaultLanguage) ||
    languageVersions?.find((version) => version.is_default)?.language_iso ||
    normalizeLanguageIso(fallbackLanguage) ||
    'en'
  )
}

export function resolveContentLanguage(
  requestedLanguage: string | null | undefined,
  defaultLanguage: string,
  languageVersions: ContentLanguageVersion[] | null | undefined,
  fallbackLanguage?: string | null | undefined
): string {
  const normalizedDefaultLanguage = getContentDefaultLanguage(
    defaultLanguage,
    languageVersions,
    fallbackLanguage
  )
  const normalizedRequestedLanguage = normalizeLanguageIso(requestedLanguage)

  if (
    normalizedRequestedLanguage &&
    languageVersions?.some((version) => version.language_iso === normalizedRequestedLanguage)
  ) {
    return normalizedRequestedLanguage
  }

  if (languageVersions?.some((version) => version.language_iso === normalizedDefaultLanguage)) {
    return normalizedDefaultLanguage
  }

  return (
    languageVersions?.[0]?.language_iso ||
    normalizeLanguageIso(fallbackLanguage) ||
    normalizedDefaultLanguage
  )
}

export function shouldIncludeLanguageQuery(
  languageIso: string | null | undefined,
  defaultLanguage: string
) {
  const normalizedLanguageIso = normalizeLanguageIso(languageIso)
  const normalizedDefaultLanguage = normalizeLanguageIso(defaultLanguage)

  return (
    !!normalizedLanguageIso &&
    !!normalizedDefaultLanguage &&
    normalizedLanguageIso !== normalizedDefaultLanguage
  )
}

export function buildContentLanguageQuery(
  languageIso: string | null | undefined,
  defaultLanguage: string
): Record<string, string> {
  const normalizedLanguageIso = normalizeLanguageIso(languageIso)

  return shouldIncludeLanguageQuery(normalizedLanguageIso, defaultLanguage)
    ? { lang: normalizedLanguageIso as string }
    : {}
}

export function withContentLanguageQuery<TQuery extends Record<string, unknown>>(
  query: TQuery,
  languageIso: string | null | undefined,
  defaultLanguage: string
): TQuery & {
  lang?: string | undefined
} {
  const nextQuery = { ...query } as TQuery & {
    lang?: string | undefined
  }
  const normalizedLanguageIso = normalizeLanguageIso(languageIso)

  if (shouldIncludeLanguageQuery(normalizedLanguageIso, defaultLanguage)) {
    nextQuery.lang = normalizedLanguageIso
    return nextQuery
  }

  delete nextQuery.lang
  return nextQuery
}

export function resolveContentRouteName(
  currentRouteName: string | undefined,
  effectiveMode: ContentI18nMode | undefined,
  languageIso: string,
  defaultLanguage: string
): ContentEditorRouteName {
  const normalizedLanguageIso = normalizeLanguageIso(languageIso) || defaultLanguage
  const normalizedDefaultLanguage = normalizeLanguageIso(defaultLanguage) || languageIso

  if (currentRouteName === 'space-content-contentId-versions') {
    return 'space-content-contentId-versions'
  }

  if (effectiveMode === 'overlay' && normalizedLanguageIso !== normalizedDefaultLanguage) {
    return 'space-content-contentId-localization'
  }

  return 'space-content-contentId'
}

export function buildContentRouteLocation<TQuery extends Record<string, unknown>>(
  currentRouteName: string | undefined,
  effectiveMode: ContentI18nMode | undefined,
  contentId: string,
  languageIso: string | null | undefined,
  defaultLanguage: string,
  query: TQuery,
  params: Record<string, unknown>,
  hash = ''
): {
  name: ContentEditorRouteName
  params: Record<string, unknown>
  query: TQuery & { lang?: string | undefined }
  hash: string
} {
  const resolvedLanguage = resolveContentLanguage(languageIso, defaultLanguage, undefined)
  return {
    name: resolveContentRouteName(
      currentRouteName,
      effectiveMode,
      resolvedLanguage,
      defaultLanguage
    ),
    params: {
      ...params,
      contentId,
    },
    query: withContentLanguageQuery(query, resolvedLanguage, defaultLanguage),
    hash,
  }
}

export function buildMissingLanguageDraft(
  canonicalContent: ContentResource,
  sourceContent: ContentResource,
  languageIso: string
): ContentResource {
  const cloned = JSON.parse(JSON.stringify(sourceContent)) as ContentResource

  return {
    ...cloned,
    id: '',
    language_iso: languageIso,
    i18n_parent_id: canonicalContent.id,
    i18n_canonical_id: canonicalContent.i18n_canonical_id,
    effective_i18n_mode: canonicalContent.effective_i18n_mode,
    language_versions: canonicalContent.language_versions.map((version) => ({
      ...version,
      is_current: version.language_iso === languageIso,
    })),
    content: {},
    raw_content: {},
    current_version_id: null,
    current_version: null,
    published_version_id: null,
    published_version: null,
    published_at: null,
    first_published_at: null,
    created_at: cloned.created_at || new Date().toISOString(),
    updated_at: new Date().toISOString(),
  }
}

export function sanitizeContentMutationPayload<
  T extends CreateContentPayload | UpdateContentPayload | ContentResource,
>(payload: T): T {
  if (payload.i18n_parent_id == null) {
    return payload
  }

  const settings = { ...payload.settings } as Partial<ContentSettings>
  let changed = false

  if (settings.i18n_mode_override) {
    delete settings.i18n_mode_override
    changed = true
  }

  for (const key of [
    'restrict_child_blocks',
    'child_block_whitelist',
    'child_tag_whitelist',
    'default_child_block',
    'child_sort_by',
    'child_sort_direction',
  ] as const) {
    if (key in settings) {
      delete settings[key]
      changed = true
    }
  }

  if (!changed) {
    return payload
  }

  return {
    ...payload,
    settings,
  }
}
