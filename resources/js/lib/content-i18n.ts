import type {
  ContentI18nMode,
  ContentResource,
  CreateContentPayload,
  UpdateContentPayload,
} from '~/types/contents'

export function resolveContentRouteName(
  currentRouteName: string | undefined,
  effectiveMode: ContentI18nMode | undefined,
  languageIso: string,
  defaultLanguage: string
): 'space-content-contentId' | 'space-content-contentId-localization' | 'space-content-contentId-versions' {
  if (currentRouteName === 'space-content-contentId-versions') {
    return 'space-content-contentId-versions'
  }

  if (effectiveMode === 'overlay' && languageIso !== defaultLanguage) {
    return 'space-content-contentId-localization'
  }

  return 'space-content-contentId'
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
  if (payload.i18n_parent_id == null || !payload.settings?.i18n_mode_override) {
    return payload
  }

  const settings = { ...payload.settings }
  delete settings.i18n_mode_override

  return {
    ...payload,
    settings,
  }
}
