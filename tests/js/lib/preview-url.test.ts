import { describe, expect, it } from 'vitest'

import { buildPreviewUrl, resolveLocaleSegment, resolveLocaleSegments } from '~/lib/preview-url'

const settings = (overrides: Partial<SpaceSettings> = {}): SpaceSettings =>
  ({ default_language: 'en', ...overrides }) as SpaceSettings

describe('resolveLocaleSegments', () => {
  it('returns the empty segment without a language or settings', () => {
    expect(resolveLocaleSegments(null, settings())).toEqual([''])
    expect(resolveLocaleSegments('de', null)).toEqual([''])
  })

  describe('with site_locales', () => {
    const withLocales = settings({
      site_locales: [
        { language: 'de', segment: '/at-de/' },
        { language: 'DE', segment: 'ch-de' },
        { language: 'de', segment: 'at-de' },
        { language: 'en', segment: 'us' },
      ],
    } as Partial<SpaceSettings>)

    it('collects every segment for the language, trimmed and deduplicated', () => {
      expect(resolveLocaleSegments('de', withLocales)).toEqual(['at-de', 'ch-de'])
    })

    it('matches the language case-insensitively', () => {
      expect(resolveLocaleSegments('DE', withLocales)).toEqual(['at-de', 'ch-de'])
    })

    it('falls back to slug_strategy for a language with no site locale', () => {
      const mixed = settings({
        site_locales: [{ language: 'de', segment: 'at-de' }],
        slug_strategy: 'always_prepend',
      } as Partial<SpaceSettings>)

      expect(resolveLocaleSegments('fr', mixed)).toEqual(['fr'])
    })
  })

  describe('with slug_strategy only', () => {
    it('never prepends by default', () => {
      expect(resolveLocaleSegments('de', settings())).toEqual([''])
    })

    it('always_prepend prefixes every language, including the default', () => {
      const config = settings({ slug_strategy: 'always_prepend' } as Partial<SpaceSettings>)

      expect(resolveLocaleSegments('de', config)).toEqual(['de'])
      expect(resolveLocaleSegments('en', config)).toEqual(['en'])
    })

    it('prepend_translations prefixes everything but the default language', () => {
      const config = settings({ slug_strategy: 'prepend_translations' } as Partial<SpaceSettings>)

      expect(resolveLocaleSegments('de', config)).toEqual(['de'])
      expect(resolveLocaleSegments('EN', config)).toEqual([''])
    })
  })
})

describe('resolveLocaleSegment', () => {
  const config = settings({
    site_locales: [
      { language: 'de', segment: 'at-de' },
      { language: 'de', segment: 'ch-de' },
    ],
  } as Partial<SpaceSettings>)

  it('honours a valid preferred segment', () => {
    expect(resolveLocaleSegment('de', config, 'ch-de')).toBe('ch-de')
    expect(resolveLocaleSegment('de', config, '/ch-de/')).toBe('ch-de')
  })

  it('falls back to the first segment for an invalid preference', () => {
    expect(resolveLocaleSegment('de', config, 'fr-fr')).toBe('at-de')
    expect(resolveLocaleSegment('de', config, null)).toBe('at-de')
  })
})

describe('buildPreviewUrl', () => {
  const config = settings({ slug_strategy: 'prepend_translations' } as Partial<SpaceSettings>)

  it('joins base, segment and slug', () => {
    expect(buildPreviewUrl('https://example.com', config, 'de', '/about')).toBe(
      'https://example.com/de/about'
    )
  })

  it('omits the prefix for the default language', () => {
    expect(buildPreviewUrl('https://example.com', config, 'en', '/about')).toBe(
      'https://example.com/about'
    )
  })

  it('strips a trailing slash from the base URL', () => {
    expect(buildPreviewUrl('https://example.com/', config, 'en', '/about')).toBe(
      'https://example.com/about'
    )
  })

  it('uses the preferred segment when it is valid for the language', () => {
    const withLocales = settings({
      site_locales: [
        { language: 'de', segment: 'at-de' },
        { language: 'de', segment: 'ch-de' },
      ],
    } as Partial<SpaceSettings>)

    expect(buildPreviewUrl('https://example.com', withLocales, 'de', '/ueber', 'ch-de')).toBe(
      'https://example.com/ch-de/ueber'
    )
  })

  it('returns null when any required input is missing', () => {
    expect(buildPreviewUrl(null, config, 'de', '/about')).toBeNull()
    expect(buildPreviewUrl('https://example.com', config, null, '/about')).toBeNull()
    expect(buildPreviewUrl('https://example.com', config, 'de', null)).toBeNull()
  })

  it('rejects a base URL that is not http(s)', () => {
    expect(buildPreviewUrl('javascript:alert(1)', config, 'de', '/about')).toBeNull()
    expect(buildPreviewUrl('data:text/html,<h1>x', config, 'de', '/about')).toBeNull()
    expect(buildPreviewUrl('ftp://example.com', config, 'de', '/about')).toBeNull()
  })

  it('accepts plain http', () => {
    expect(buildPreviewUrl('http://localhost:3000', config, 'en', '/')).toBe('http://localhost:3000/')
  })
})
