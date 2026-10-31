import type { App } from 'vue'
import type { Composer } from 'vue-i18n'
import { createI18n } from 'vue-i18n'

import en from '~/i18n/en.json'

export type MessageSchema = typeof en

export const locales = [
  { code: 'de', name: 'Deutsch', iso: 'de', flag: '🇦🇹' },
  { code: 'en', name: 'English', iso: 'en', flag: '🇺🇸' },
  { code: 'tr', name: 'Türkçe', iso: 'tr', flag: '🇹🇷' },
  { code: 'ru', name: 'Русский', iso: 'ru', flag: '🇷🇺' },
  { code: 'fr', name: 'Français', iso: 'fr', flag: '🇫🇷' },
  { code: 'es', name: 'Español', iso: 'es', flag: '🇪🇸' },
] as const

export type LocaleCode = (typeof locales)[number]['code']

const FALLBACK_LOCALE: LocaleCode = 'en'
const russianPluralRules = new Intl.PluralRules('ru')

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

export const i18n = createI18n({
  legacy: false,
  // Starts on the bundled fallback; installI18n switches once the active catalog is in.
  locale: FALLBACK_LOCALE,
  fallbackLocale: FALLBACK_LOCALE,
  pluralRules: {
    ru: (choice, choicesLength, defaultRule) => {
      if (choicesLength < 3) return defaultRule!(choice, choicesLength)
      if (choicesLength === 4 && choice === 0) return 0

      const category = russianPluralRules.select(choice)
      const index = category === 'one' ? 0 : category === 'few' ? 1 : 2
      return choicesLength === 4 ? index + 1 : index
    },
  },
  // Only the fallback ships in the entry chunk. Every other catalog is its own
  // chunk, loaded by setLocale before it switches.
  messages: { en },
})

const composer = i18n.global as unknown as Composer<{
  en: MessageSchema
  de: MessageSchema
  tr: MessageSchema
  ru: MessageSchema
  fr: MessageSchema
  es: MessageSchema
}>

// Each catalog is ~200 KB raw. The record keeps the loaders in step with `locales`.
const catalogLoaders: Record<Exclude<LocaleCode, 'en'>, () => Promise<{ default: MessageSchema }>> = {
  de: () => import('~/i18n/de.json'),
  tr: () => import('~/i18n/tr.json'),
  ru: () => import('~/i18n/ru.json'),
  fr: () => import('~/i18n/fr.json'),
  es: () => import('~/i18n/es.json'),
}

const pendingCatalogs = new Map<LocaleCode, Promise<void>>([['en', Promise.resolve()]])
const isCatalogReady = (locale: LocaleCode) => composer.availableLocales.includes(locale)

/** Fetches a catalog once and registers it with the composer. */
export function loadLocaleMessages(locale: LocaleCode): Promise<void> {
  let pending = pendingCatalogs.get(locale)

  if (!pending) {
    pending = catalogLoaders[locale as Exclude<LocaleCode, 'en'>]().then(
      ({ default: messages }) => {
        composer.setLocaleMessage(locale, messages)
      },
      (error: unknown) => {
        // Forget the failure so a later switch can retry the chunk.
        pendingCatalogs.delete(locale)
        throw error
      }
    )
    pendingCatalogs.set(locale, pending)
  }

  return pending
}

let requestedLocale: LocaleCode | undefined

/**
 * Blade renders <html lang> from the server's own negotiation, so every locale
 * change has to carry the document along or the two drift apart.
 */
function applyLocale(locale: LocaleCode) {
  composer.locale.value = locale

  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('lang', locale)
  }
}

/**
 * Switches synchronously when the catalog is already loaded. Otherwise the
 * current locale stays until the catalog arrives, so no raw keys or fallback
 * copy flash. The last call wins, a slower earlier load never overrides it.
 * A failed load keeps the current locale and never rejects.
 */
export function setLocale(locale: LocaleCode): Promise<void> {
  requestedLocale = locale

  if (isCatalogReady(locale)) {
    applyLocale(locale)
    return Promise.resolve()
  }

  return loadLocaleMessages(locale).then(
    () => {
      if (requestedLocale === locale) applyLocale(locale)
    },
    (error: unknown) => {
      console.error(`[i18n] Failed to load the "${locale}" messages:`, error)
    }
  )
}

/**
 * The locale the first paint should use: the cached user setting (same
 * `user-settings` key useUserSettings writes), else the browser's language.
 */
function initialLocale(): LocaleCode {
  try {
    const stored: unknown = JSON.parse(window.localStorage.getItem('user-settings') || '{}')
    const languageIso =
      stored && typeof stored === 'object' && 'languageIso' in stored ? stored.languageIso : undefined
    if (typeof languageIso === 'string' && isSupportedLocale(languageIso)) return languageIso
  } catch {
    /** unreadable storage falls through to the browser */
  }

  return detectBrowserLocale()
}

/** Resolves once the initial catalog is loaded. Await it before mounting. */
export function installI18n(app: App): Promise<void> {
  app.use(i18n)
  return setLocale(initialLocale())
}

export function useI18n() {
  const t = composer.t.bind(composer)
  return {
    t,
    $t: t,
    te: composer.te.bind(composer),
    locale: composer.locale,
    locales,
    setLocale,
    getLocale: () => composer.locale.value,
  }
}

export function getLocale() {
  return composer.locale.value
}
