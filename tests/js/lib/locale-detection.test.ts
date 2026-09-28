import { afterEach, describe, expect, it, vi } from 'vitest'

import { detectBrowserLocale } from '~/plugins/i18n'

const withLanguages = (languages: string[] | undefined, language = 'xx-XX') => {
  vi.spyOn(navigator, 'languages', 'get').mockReturnValue(languages as readonly string[])
  vi.spyOn(navigator, 'language', 'get').mockReturnValue(language)
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('detectBrowserLocale', () => {
  it('takes the first supported language, ignoring the region', () => {
    withLanguages(['de-AT', 'en-US'])
    expect(detectBrowserLocale()).toBe('de')
  })

  it.each([
    ['es-MX', 'es'],
    ['fr-CA', 'fr'],
    ['ru-RU', 'ru'],
    ['tr-TR', 'tr'],
  ])('recognizes %s as %s', (tag, locale) => {
    withLanguages([tag, 'en-US'])
    expect(detectBrowserLocale()).toBe(locale)
  })

  it('skips languages it has no messages for', () => {
    withLanguages(['ja-JP', 'nl', 'de'])
    expect(detectBrowserLocale()).toBe('de')
  })

  it('falls back to English when nothing matches', () => {
    withLanguages(['nl-NL', 'ja'])
    expect(detectBrowserLocale()).toBe('en')
  })

  it('falls back to navigator.language when the list is empty', () => {
    withLanguages([], 'de-DE')
    expect(detectBrowserLocale()).toBe('de')
  })
})
