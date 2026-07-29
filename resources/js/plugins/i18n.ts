import type { App } from 'vue'
import type { Composer } from 'vue-i18n'
import { createI18n } from 'vue-i18n'

import de from '~/i18n/de.json'
import en from '~/i18n/en.json'

export type MessageSchema = typeof en

export const i18n = createI18n<[MessageSchema], 'en' | 'de'>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: {
    en,
    de,
  },
})

export function installI18n(app: App) {
  app.use(i18n)
}

export const locales = [
  { code: 'de', name: 'Deutsch', iso: 'de', flag: '🇦🇹' },
  { code: 'en', name: 'English', iso: 'en', flag: '🇺🇸' },
] as const

const composer = i18n.global as unknown as Composer<{ en: MessageSchema; de: MessageSchema }>

export function useI18n() {
  const t = composer.t.bind(composer)
  return {
    t,
    $t: t,
    locale: composer.locale,
    locales,
    setLocale: (locale: LocaleCode) => {
      composer.locale.value = locale
      document.documentElement.setAttribute('lang', locale)
    },
    getLocale: () => composer.locale.value,
  }
}

export function setLocale(locale: LocaleCode) {
  composer.locale.value = locale
}

export function getLocale() {
  return composer.locale.value
}

export type LocaleCode = (typeof locales)[number]['code']
