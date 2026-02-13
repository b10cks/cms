import type { App } from 'vue'

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

export function useI18n() {
  const global = i18n.global as any
  return {
    t: global.t.bind(global),
    $t: global.t.bind(global),
    locale: global.locale,
    locales,
    setLocale: (locale: 'en' | 'de') => {
      global.locale.value = locale
      document.querySelector('html').setAttribute('lang', locale)
    },
    getLocale: () => global.locale.value,
  }
}

export function setLocale(locale: 'en' | 'de') {
  ;(i18n.global.locale as any).value = locale
}

export function getLocale() {
  return (i18n.global.locale as any).value
}

export type LocaleCode = (typeof locales)[number]['code']
