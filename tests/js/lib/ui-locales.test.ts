import { baseCompile } from '@intlify/message-compiler'
import { afterEach, describe, expect, it } from 'vitest'

import en from '~/i18n/en.json'
import es from '~/i18n/es.json'
import fr from '~/i18n/fr.json'
import ru from '~/i18n/ru.json'
import tr from '~/i18n/tr.json'
import { setLocale, useI18n } from '~/plugins/i18n'

type Messages = { [key: string]: string | Messages }

function flatten(messages: Messages, prefix = ''): Record<string, string> {
  return Object.fromEntries(
    Object.entries(messages).flatMap(([key, value]) => {
      const path = prefix ? `${prefix}.${key}` : key
      return typeof value === 'string' ? [[path, value]] : Object.entries(flatten(value, path))
    })
  )
}

const english = flatten(en)
const placeholders = (message: string) => [...new Set(message.match(/\{[^}]+\}/g))].sort()

afterEach(() => setLocale('en'))

describe('UI locale catalogs', () => {
  it.each(Object.entries({ es, fr, ru, tr }))(
    '%s matches English keys and placeholders and compiles',
    (_locale, messages) => {
      const translated = flatten(messages)
      expect(Object.keys(translated).sort()).toEqual(Object.keys(english).sort())

      for (const [key, source] of Object.entries(english)) {
        if (source.trim()) expect(translated[key].trim(), key).not.toBe('')
        expect(placeholders(translated[key]), key).toEqual(placeholders(source))
        const errors: string[] = []
        baseCompile(translated[key], { onError: (error) => errors.push(error.message) })
        expect(errors, key).toEqual([])
      }
    }
  )
})

describe('Russian plural selection', () => {
  it.each([
    [0, 'файлов'],
    [1, 'файл'],
    [2, 'файла'],
    [5, 'файлов'],
    [11, 'файлов'],
    [12, 'файлов'],
    [21, 'файл'],
    [22, 'файла'],
    [25, 'файлов'],
    [101, 'файл'],
    [111, 'файлов'],
  ])('renders %s files', (count, noun) => {
    setLocale('ru')
    expect(useI18n().t('labels.publicShare.assetCount', { count })).toBe(`${count} ${noun}`)
  })

  it('keeps the explicit empty release message', () => {
    setLocale('ru')
    const { t } = useI18n()
    expect(t('labels.releases.fields.versionsCount', 0)).toBe('Нет элементов')
    expect(t('labels.releases.fields.versionsCount', 21)).toBe('21 элемент')
    expect(t('labels.releases.fields.versionsCount', 22)).toBe('22 элемента')
    expect(t('labels.releases.fields.versionsCount', 25)).toBe('25 элементов')
  })
})
