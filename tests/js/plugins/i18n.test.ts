import { beforeEach, describe, expect, it, vi } from 'vitest'

type I18nModule = typeof import('~/plugins/i18n')

let i18n: I18nModule

// A fresh module, so no catalog besides the bundled `en` is registered yet.
beforeEach(async () => {
  vi.resetModules()
  i18n = await import('~/plugins/i18n')
})

describe('setLocale', () => {
  it('keeps the current locale until the catalog has loaded', async () => {
    const switching = i18n.setLocale('de')

    expect(i18n.getLocale()).toBe('en')

    await switching

    expect(i18n.getLocale()).toBe('de')
    expect(document.documentElement.lang).toBe('de')
    expect(i18n.useI18n().t('actions.cancel')).toBe('Abbrechen')
  })

  it('lets the last call win over a slower earlier load', async () => {
    const switching = i18n.setLocale('de')
    i18n.setLocale('en')

    await switching

    expect(i18n.getLocale()).toBe('en')
  })
})
