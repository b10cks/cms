import { describe, expect, it } from 'vitest'

import type {
  ContentLanguageVersion,
  ContentResource,
  UpdateContentPayload,
} from '~/types/contents'

import {
  buildContentLanguageQuery,
  buildContentRouteLocation,
  buildMissingLanguageDraft,
  getContentDefaultLanguage,
  normalizeLanguageIso,
  resolveContentLanguage,
  resolveContentRouteName,
  sanitizeContentMutationPayload,
  shouldIncludeLanguageQuery,
  withContentLanguageQuery,
} from '~/lib/content-i18n'

interface Nested {
  nested: { flag: boolean }
}

const version = (
  languageIso: string,
  overrides: Partial<ContentLanguageVersion> = {}
): ContentLanguageVersion =>
  ({
    language_iso: languageIso,
    is_default: false,
    is_current: false,
    ...overrides,
  }) as ContentLanguageVersion

describe('normalizeLanguageIso', () => {
  it('lowercases and trims', () => {
    expect(normalizeLanguageIso('  DE  ')).toBe('de')
  })

  it('returns undefined for empty, whitespace-only and non-string input', () => {
    expect(normalizeLanguageIso('')).toBeUndefined()
    expect(normalizeLanguageIso('   ')).toBeUndefined()
    expect(normalizeLanguageIso(null)).toBeUndefined()
    expect(normalizeLanguageIso(undefined)).toBeUndefined()
  })
})

describe('getContentDefaultLanguage', () => {
  it('prefers the explicit default language', () => {
    expect(getContentDefaultLanguage('DE', [version('en', { is_default: true })], 'fr')).toBe('de')
  })

  it('falls back to the version flagged as default', () => {
    expect(getContentDefaultLanguage(null, [version('en'), version('fr', { is_default: true })], 'de')).toBe(
      'fr'
    )
  })

  it('falls back to the space language, then to en', () => {
    expect(getContentDefaultLanguage(null, [], 'IT')).toBe('it')
    expect(getContentDefaultLanguage(null, null, null)).toBe('en')
  })
})

describe('resolveContentLanguage', () => {
  const versions = [version('en', { is_default: true }), version('de')]

  it('honours a requested language that exists', () => {
    expect(resolveContentLanguage('DE', 'en', versions)).toBe('de')
  })

  it('falls back to the default when the request has no version', () => {
    expect(resolveContentLanguage('fr', 'en', versions)).toBe('en')
  })

  it('falls back to the first version when the default has none either', () => {
    expect(resolveContentLanguage('fr', 'es', [version('de'), version('en')])).toBe('de')
  })

  it('falls back to the space language when there are no versions at all', () => {
    expect(resolveContentLanguage(null, 'en', undefined, 'nl')).toBe('nl')
  })
})

describe('language query building', () => {
  it('omits the query for the default language', () => {
    expect(shouldIncludeLanguageQuery('en', 'en')).toBe(false)
    expect(buildContentLanguageQuery('EN', 'en')).toEqual({})
  })

  it('includes the query for a non-default language', () => {
    expect(shouldIncludeLanguageQuery('de', 'en')).toBe(true)
    expect(buildContentLanguageQuery('DE', 'en')).toEqual({ lang: 'de' })
  })

  it('omits the query when either side is missing', () => {
    expect(buildContentLanguageQuery(null, 'en')).toEqual({})
    expect(buildContentLanguageQuery('de', '')).toEqual({})
  })

  it('strips a stale lang from an existing query', () => {
    expect(withContentLanguageQuery({ page: '2', lang: 'de' }, 'en', 'en')).toEqual({ page: '2' })
  })

  it('sets lang on a copy, leaving the original query untouched', () => {
    const query = { page: '2' }

    expect(withContentLanguageQuery(query, 'de', 'en')).toEqual({ page: '2', lang: 'de' })
    expect(query).toEqual({ page: '2' })
  })
})

describe('resolveContentRouteName', () => {
  it('keeps the versions route regardless of language and mode', () => {
    expect(
      resolveContentRouteName('space-content-contentId-versions', 'overlay', 'de', 'en')
    ).toBe('space-content-contentId-versions')
  })

  it('routes a non-default language to localization in overlay mode', () => {
    expect(resolveContentRouteName('space-content-contentId', 'overlay', 'de', 'en')).toBe(
      'space-content-contentId-localization'
    )
  })

  it('stays on the editor for the default language in overlay mode', () => {
    expect(resolveContentRouteName('space-content-contentId', 'overlay', 'EN', 'en')).toBe(
      'space-content-contentId'
    )
  })

  it('stays on the editor for independent content in any language', () => {
    expect(resolveContentRouteName('space-content-contentId', 'independent', 'de', 'en')).toBe(
      'space-content-contentId'
    )
  })
})

describe('buildContentRouteLocation', () => {
  it('composes name, params, query and hash', () => {
    expect(
      buildContentRouteLocation(
        'space-content-contentId-versions',
        'overlay',
        'content-1',
        'en',
        'en',
        { tab: 'seo' },
        { space: 'space-1' },
        '#field'
      )
    ).toEqual({
      name: 'space-content-contentId-versions',
      params: { space: 'space-1', contentId: 'content-1' },
      query: { tab: 'seo' },
      hash: '#field',
    })
  })

  it('routes a non-default language to localization in overlay mode', () => {
    expect(
      buildContentRouteLocation(
        'space-content-contentId',
        'overlay',
        'content-1',
        ' DE ',
        'en',
        {},
        {}
      )
    ).toMatchObject({
      name: 'space-content-contentId-localization',
      query: { lang: 'de' },
    })
  })

  it('keeps a non-default language on the editor for independent content', () => {
    expect(
      buildContentRouteLocation(
        'space-content-contentId',
        'independent',
        'content-1',
        'de',
        'en',
        {},
        {}
      )
    ).toMatchObject({
      name: 'space-content-contentId',
      query: { lang: 'de' },
    })
  })

  it('falls back to the default language when none is given', () => {
    expect(
      buildContentRouteLocation('space-content-contentId', 'overlay', 'content-1', '  ', 'en', {}, {})
    ).toMatchObject({
      name: 'space-content-contentId',
      query: {},
    })
  })

  it('defaults the hash to empty and drops the lang for the default language', () => {
    expect(
      buildContentRouteLocation(
        'space-content-contentId',
        'overlay',
        'content-1',
        'en',
        'en',
        {},
        {}
      )
    ).toEqual({
      name: 'space-content-contentId',
      params: { contentId: 'content-1' },
      query: {},
      hash: '',
    })
  })
})

describe('buildMissingLanguageDraft', () => {
  const canonical = {
    id: 'canonical-1',
    i18n_canonical_id: 'canonical-1',
    effective_i18n_mode: 'overlay',
    language_versions: [version('en', { is_default: true, is_current: true }), version('de')],
  } as unknown as ContentResource

  const source = {
    id: 'source-1',
    name: 'Homepage',
    slug: 'home',
    language_iso: 'en',
    content: { title: 'Hello' },
    raw_content: { title: 'Hello' },
    current_version_id: 'v-1',
    current_version: { id: 'v-1' },
    published_version_id: 'v-0',
    published_version: { id: 'v-0' },
    published_at: '2026-01-01T00:00:00.000Z',
    first_published_at: '2026-01-01T00:00:00.000Z',
    created_at: '2025-12-01T00:00:00.000Z',
    updated_at: '2026-01-01T00:00:00.000Z',
    settings: { nested: { flag: true } },
  } as unknown as ContentResource

  it('clears identity and version state while keeping the source scaffolding', () => {
    const draft = buildMissingLanguageDraft(canonical, source, 'de')

    expect(draft.id).toBe('')
    expect(draft.language_iso).toBe('de')
    expect(draft.i18n_parent_id).toBe('canonical-1')
    expect(draft.i18n_canonical_id).toBe('canonical-1')
    expect(draft.content).toEqual({})
    expect(draft.raw_content).toEqual({})
    expect(draft.current_version_id).toBeNull()
    expect(draft.current_version).toBeNull()
    expect(draft.published_version_id).toBeNull()
    expect(draft.published_at).toBeNull()
    expect(draft.first_published_at).toBeNull()
    expect(draft.name).toBe('Homepage')
    expect(draft.created_at).toBe('2025-12-01T00:00:00.000Z')
  })

  it('marks the drafted language as the current version', () => {
    const draft = buildMissingLanguageDraft(canonical, source, 'de')

    expect(draft.language_versions.map((entry) => [entry.language_iso, entry.is_current])).toEqual([
      ['en', false],
      ['de', true],
    ])
  })

  it('deep-clones so mutating the draft cannot reach the source', () => {
    const draft = buildMissingLanguageDraft(canonical, source, 'de')

    ;(draft.settings as unknown as Nested).nested.flag = false

    expect((source.settings as unknown as Nested).nested.flag).toBe(true)
  })
})

describe('sanitizeContentMutationPayload', () => {
  const payloadOf = (value: Record<string, unknown>) => value as unknown as UpdateContentPayload

  it('returns the payload untouched for canonical content', () => {
    const payload = payloadOf({
      i18n_parent_id: null,
      settings: { i18n_mode_override: 'overlay', restrict_child_blocks: true },
    })

    expect(sanitizeContentMutationPayload(payload)).toBe(payload)
  })

  it('returns the same reference when a translation has nothing to strip', () => {
    const payload = payloadOf({ i18n_parent_id: 'parent-1', settings: { component: 'page' } })

    expect(sanitizeContentMutationPayload(payload)).toBe(payload)
  })

  it('strips parent-owned settings from a translation without mutating the input', () => {
    const payload = payloadOf({
      i18n_parent_id: 'parent-1',
      settings: {
        i18n_mode_override: 'overlay',
        restrict_child_blocks: true,
        child_block_whitelist: ['page'],
        child_tag_whitelist: ['news'],
        default_child_block: 'block-1',
        child_sort_by: 'name',
        child_sort_direction: 'asc',
        component: 'page',
      },
    })

    const sanitized = sanitizeContentMutationPayload(payload)

    expect(sanitized.settings).toEqual({ component: 'page' })
    expect((payload.settings as Record<string, unknown>).restrict_child_blocks).toBe(true)
  })
})
