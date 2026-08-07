import { describe, expect, it } from 'vitest'

import fixture from '../../fixtures/slug-cases.json'

import { CONTENT_SLUG_LENGTH, slugify, slugifyContent, slugifyIdentifier } from '~/lib/slug'

/**
 * The case table is shared with tests/Unit/Services/Slug/SluggerTest.php, so a
 * rule changed on one side and not the other fails here.
 */
describe('slug', () => {
  describe.each(fixture.cases)('$why', ({ value, language, expected }) => {
    it(`turns ${JSON.stringify(value)} (${language ?? 'no language'}) into ${JSON.stringify(expected)}`, () => {
      expect(slugifyContent(value, language)).toBe(expected)
    })
  })

  describe.each(fixture.truncation)('$why', ({ value, maxLength, expected }) => {
    it(`truncates to ${maxLength}`, () => {
      expect(slugify(value, { language: 'en', maxLength })).toBe(expected)
    })
  })

  describe.each(fixture.identifiers)('$why', ({ value, language, expected }) => {
    it(`turns ${JSON.stringify(value)} into ${JSON.stringify(expected)}`, () => {
      expect(slugifyIdentifier(value, language)).toBe(expected)
    })
  })

  it('has no transliteration table, so non-Latin input yields nothing', () => {
    // The documented divergence from the backend, which produces "privet-mir".
    // The field stays empty and the server fills it in on save.
    expect(slugifyContent('Привет мир', 'ru')).toBe('')
    expect(slugifyContent('日本語', 'ja')).toBe('')
  })

  it('keeps content slugs within the column limit', () => {
    const slug = slugifyContent('wort '.repeat(40), 'de')

    expect(slug.length).toBeLessThanOrEqual(CONTENT_SLUG_LENGTH)
    expect(slug.endsWith('wort')).toBe(true)
  })

  it('is idempotent', () => {
    for (const value of ['Über Größe', 'Bed & Breakfast', 'my_page', '{lang}/about']) {
      const once = slugifyContent(value, 'de')

      expect(slugifyContent(once, 'de')).toBe(once)
    }
  })

  it('honours a custom separator', () => {
    expect(slugify('Feld Größe', { language: 'de', separator: '_' })).toBe('feld_groesse')
  })

  it('produces camelCase for block slugs', () => {
    // The block slug rule is ^[a-z][a-z0-9A-Z]+$, so no separators at all.
    expect(
      slugify('Übersicht Karte', { language: 'de', case: 'camel', allowUnderscore: false })
    ).toBe('uebersichtKarte')
  })
})
