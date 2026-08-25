import type { App } from 'vue'
import type { Composer } from 'vue-i18n'
import { createI18n } from 'vue-i18n'

import de from '~/i18n/de.json'
import en from '~/i18n/en.json'

export type MessageSchema = typeof en

export const locales = [
  { code: 'de', name: 'Deutsch', iso: 'de', flag: '🇦🇹' },
  { code: 'en', name: 'English', iso: 'en', flag: '🇺🇸' },
] as const

export type LocaleCode = (typeof locales)[number]['code']

const FALLBACK_LOCALE: LocaleCode = 'en'

const isSupportedLocale = (code: string): code is LocaleCode =>
  locales.some((locale) => locale.code === code)

/**
 * First supported language the browser asks for, region ignored — the client-side
 * twin of the AcceptHeader middleware's negotiation. Only decides the *initial*
 * locale: a stored or saved user preference outranks it.
 */
export function detectBrowserLocale(): LocaleCode {
  if (typeof navigator === 'undefined') return FALLBACK_LOCALE

  const preferred = navigator.languages?.length ? navigator.languages : [navigator.language]

  for (const tag of preferred) {
    const code = tag?.toLowerCase().split('-')[0]
    if (code && isSupportedLocale(code)) return code
  }

  return FALLBACK_LOCALE
}

export const i18n = createI18n<[MessageSchema], LocaleCode>({
  legacy: false,
  locale: detectBrowserLocale(),
  fallbackLocale: FALLBACK_LOCALE,
  messages: {
    en,
    de,
  },
})

const composer = i18n.global as unknown as Composer<{ en: MessageSchema; de: MessageSchema }>

/**
 * Blade renders <html lang> from the server's own negotiation, so every locale
 * change has to carry the document along or the two drift apart.
 */
export function setLocale(locale: LocaleCode) {
  composer.locale.value = locale

  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('lang', locale)
  }
}

export function installI18n(app: App) {
  app.use(i18n)
  setLocale(composer.locale.value as LocaleCode)
}

export function useI18n() {
  const t = composer.t.bind(composer)
  return {
    t,
    $t: t,
    locale: composer.locale,
    locales,
    setLocale,
    getLocale: () => composer.locale.value,
  }
}

export function getLocale() {
  return composer.locale.value
}
