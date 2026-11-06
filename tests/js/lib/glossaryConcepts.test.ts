import { describe, expect, it } from 'vitest'

import type { AiGlossaryConceptRow } from '~/api/resources/ai-knowledge'
import {
  applyAiFill,
  columnsWithoutWording,
  conceptCell,
  conceptFormError,
  conceptFormOf,
  conceptFormOnto,
  conceptPayload,
  conceptWriteError,
  fillableUse,
  isValidLocaleCode,
  languageMark,
  markedSegments,
  conceptTitle,
  conceptTitleLocale,
  noteTooLong,
  rangeBetween,
} from '~/lib/glossaryConcepts'

const language = (use: string | null, extra: Partial<AiGlossaryConceptRow['languages'][string]> = {}) => ({
  use,
  avoid: [],
  also: [],
  ...extra,
})

const row = (extra: Partial<AiGlossaryConceptRow> = {}): AiGlossaryConceptRow => ({
  id: 'c1',
  key: null,
  keep_exact: false,
  note: null,
  languages: { de: language('Wolkenbett') },
  coverage: { de: 'own', 'de-at': 'fallback', fr: 'missing' },
  ...extra,
})

describe('conceptCell', () => {
  it('tells own wording, a base language and a gap apart', () => {
    const subject = row({ coverage: { de: 'own', 'de-at': 'fallback', fr: 'missing' } })

    expect(conceptCell(subject, 'de')).toEqual({ state: 'own', wording: 'Wolkenbett', ban: false })
    expect(conceptCell(subject, 'de-at')).toEqual({ state: 'fallback', wording: 'Wolkenbett', from: 'de' })
    expect(conceptCell(subject, 'fr')).toEqual({ state: 'missing' })
  })
})

describe('a row name', () => {
  it('says which language it comes from when the default language has no wording', () => {
    const subject = row({ languages: { en: language('Cloud bed'), fr: language('Lit nuage') } })

    expect(conceptTitleLocale(subject, 'de')).toBe('en')
    expect(conceptTitle(subject, 'de')).toBe('Cloud bed')
    expect(conceptTitleLocale(row(), 'de')).toBe('de')
  })
})

describe('a ban', () => {
  it('reads as its first Avoid wording and sends Use as null', () => {
    const ban = row({ languages: { de: language(null, { avoid: ['Billigbett', 'Billig*'] }) } })

    expect(conceptCell(ban, 'de')).toEqual({ state: 'own', wording: 'Billigbett', ban: true })
    expect(conceptFormOf(ban, ['de']).languages.de.use).toBe('')
    expect(conceptFormError(conceptFormOf(ban, ['de']), ban)).toBeNull()

    const used = row()
    const form = conceptFormOf(used, ['de'])
    form.languages.de.use = ''
    form.languages.de.avoid = ['Wolkenbett']
    expect(conceptPayload(form, conceptFormOf(used, ['de']), used)).toEqual({
      languages: { de: { use: null, avoid: ['Wolkenbett'] } },
    })
  })
})

describe('conceptPayload', () => {
  const stored = row({ languages: { de: language('Wolkenbett'), fr: language('Lit nuage') } })
  const codes = ['de', 'fr', 'it']

  it('sends nothing when nothing changed', () => {
    expect(conceptPayload(conceptFormOf(stored, codes), conceptFormOf(stored, codes), stored)).toEqual({})
  })

  it('sends only what changed, and a new language with its wording', () => {
    const form = conceptFormOf(stored, codes)
    form.note = ' Nur Serie X '
    form.keep_exact = true
    form.languages.de.also = ['WB']
    form.languages.it.use = 'Letto nuvola'

    expect(conceptPayload(form, conceptFormOf(stored, codes), stored)).toEqual({
      note: 'Nur Serie X',
      keep_exact: true,
      languages: { de: { also: ['WB'] }, it: { use: 'Letto nuvola' } },
    })
  })

  it('removes a language only when asked', () => {
    const form = conceptFormOf(stored, codes)
    form.removed.push('fr')

    expect(conceptPayload(form, conceptFormOf(stored, codes), stored)).toEqual({ remove_languages: ['fr'] })
    expect(languageMark(form, 'fr', stored)).toBe('removed')
  })
})

describe('conceptFormError', () => {
  it('asks for a wording where a language holds other text or had one', () => {
    const stored = row()
    const form = conceptFormOf(stored, ['de', 'fr'])
    form.languages.fr.also = ['Lits']
    expect(conceptFormError(form, stored)).toEqual({ code: 'wording_required', locale: 'fr' })

    form.languages.fr.use = 'Lit'
    form.languages.de.use = ' '
    expect(conceptFormError(form, stored)).toEqual({ code: 'wording_required', locale: 'de' })
  })

  it('wants one wording on a new concept', () => {
    expect(conceptFormError(conceptFormOf(null, ['de']), null)).toEqual({ code: 'no_language' })
  })
})

describe('AI fill', () => {
  it('sends only what the target lacks and never overwrites typed text', () => {
    const form = conceptFormOf(row({ languages: { de: language('Bett') } }), ['de', 'fr'])

    expect(fillableUse(form.languages.de, form.languages.fr)).toBe('Bett')

    form.languages.fr.use = 'Typed meanwhile'
    expect(applyAiFill(form.languages.fr, { use: 'Lit' })).toBe(false)
    expect(form.languages.fr.use).toBe('Typed meanwhile')
  })
})

describe('noteTooLong', () => {
  it('holds an edited note to the limit and leaves an older long one alone', () => {
    const long = 'x'.repeat(240)
    const baseline = conceptFormOf(row({ note: long }), ['de'])
    const form = conceptFormOf(row({ note: long }), ['de'])

    expect(noteTooLong(form, baseline)).toBe(false)
    expect(conceptPayload(form, baseline, row({ note: long })).note).toBeUndefined()

    form.note = `${long}y`
    expect(noteTooLong(form, baseline)).toBe(true)
  })
})

describe('columnsWithoutWording', () => {
  it('finds a language served or missing in every row, and keeps one with any own wording', () => {
    const served = row({ languages: { de: language('Bett') }, coverage: { de: 'own', 'de-at': 'fallback', 'de-ch': 'fallback', fr: 'missing' } })
    const own = row({ languages: { de: language('Sofa'), 'de-ch': language('Couch') }, coverage: { de: 'own', 'de-at': 'fallback', 'de-ch': 'own', fr: 'missing' } })

    expect(columnsWithoutWording([served, own], ['de-at', 'de-ch', 'fr'])).toEqual(['de-at', 'fr'])
    expect(columnsWithoutWording([], ['de-at'])).toEqual([])
  })
})

describe('markedSegments', () => {
  it('slices by UTF-16 offsets after an emoji and keeps overlapping matches', () => {
    const text = '😀 Wolkenbett Bett'
    const whole = { start: 3, end: 13, text: 'Wolkenbett' }
    const part = { start: 9, end: 13, text: 'bett' }
    const segments = markedSegments(text, [whole, part])

    expect(segments.map((segment) => segment.text)).toEqual(['😀 ', 'Wolken', 'bett', ' Bett'])
    expect(segments.map((segment) => segment.matches.length)).toEqual([0, 1, 2, 0])
    expect(segments.map((segment) => segment.text).join('')).toBe(text)
  })

  it('ignores an offset outside the text', () => {
    expect(markedSegments('abc', [{ start: 2, end: 9, text: 'x' }])).toEqual([
      { text: 'abc', start: 0, matches: [] },
    ])
  })
})

it('rangeBetween reads the same in both directions', () => {
  expect(rangeBetween(['a', 'b', 'c', 'd'], 3, 1)).toEqual(['b', 'c', 'd'])
})

it('isValidLocaleCode takes language tags in either spelling', () => {
  expect(['de', 'de-AT', 'de_AT', 'zh-Hans-CN'].every(isValidLocaleCode)).toBe(true)
  expect(['', 'not a code', 'd', 'de-'].some(isValidLocaleCode)).toBe(false)
})

describe('conceptFormOnto', () => {
  it('keeps what this person changed and takes the rest from the newer row', () => {
    const read = row({ note: 'Alt', languages: { de: language('Wolkenbett'), en: language('Cloud bed') } })
    const baseline = conceptFormOf(read, ['de', 'en', 'fr'])
    const form = conceptFormOf(read, ['de', 'en', 'fr'])
    form.languages.de.use = 'Federbett'
    form.languages.fr.use = 'Lit'
    const newer = row({
      note: 'Neu',
      keep_exact: true,
      languages: { de: language('Traumbett', { avoid: ['Luftbett'] }), en: language('Dream bed') },
    })

    const moved = conceptFormOnto(form, baseline, newer)

    expect(moved.form.languages.de).toEqual({ use: 'Federbett', avoid: ['Luftbett'], also: [] })
    expect(moved.form.languages.en.use).toBe('Dream bed')
    expect(moved.form.note).toBe('Neu')
    expect(moved.form.keep_exact).toBe(true)
    expect(conceptPayload(moved.form, moved.baseline, newer)).toEqual({
      languages: { de: { use: 'Federbett' }, fr: { use: 'Lit' } },
    })
    expect(moved.replaced).toEqual([{ field: 'use', locale: 'de' }])
  })

  it('names only what the next Save would overwrite, a removed language included', () => {
    const read = row({ note: 'Alt', languages: { de: language('Bett'), en: language('Bed'), fr: language('Lit') } })
    const form = conceptFormOf(read, [])
    form.note = 'Neu'
    form.languages.de.use = 'Sofa'
    form.removed = ['en']
    const newer = row({ note: 'Neu', languages: { de: language('Bett'), en: language('Couch'), fr: language('Couchette') } })

    expect(conceptFormOnto(form, conceptFormOf(read, []), newer).replaced).toEqual([{ field: 'use', locale: 'en' }])
  })
})

describe('conceptWriteError', () => {
  const rejected = (data: unknown) => Object.assign(new Error('Request failed'), { data })

  it('treats every writer refusal as a conflict, and leaves validation and other failures apart', () => {
    const refusal = { code: 'language_conflict', message: 'This row already has the language de.', locale: 'de' }

    expect(conceptWriteError(rejected(refusal))).toEqual({ kind: 'conflict', conflict: refusal })
    expect(conceptWriteError(rejected({ message: 'Invalid', errors: { note: ['Too long'] } }))).toEqual({
      kind: 'validation',
      fields: { note: ['Too long'] },
      message: 'Request failed',
    })
    expect(conceptWriteError(new Error('Offline'))).toEqual({ kind: 'other', message: 'Offline' })
  })
})
