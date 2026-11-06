import type {
  AiGlossaryCheckMatch,
  AiGlossaryConceptConflict,
  AiGlossaryConceptLanguagePayload,
  AiGlossaryConceptPayload,
  AiGlossaryConceptRow,
  AiGlossaryConceptRowChange,
  AiGlossaryConceptRowFilters,
  AiGlossaryConceptRowLanguage,
} from '~/api/resources/ai-knowledge'

/** The glossary editor's pure rules. They live in `lib/` so composables can share them. */

const LOCALE_PATTERN = /^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/

/** The form the server stores a locale in, so a new language lines up with what comes back. */
export const normalizedLocale = (code: string): string => code.trim().toLowerCase().replaceAll('_', '-')

/** The same rule as `SaveAiGlossaryConceptRowRequest`, plus what the browser accepts as a language tag. */
export const isValidLocaleCode = (code: string): boolean => {
  if (code.length > 20 || !LOCALE_PATTERN.test(code)) return false
  try {
    return Intl.getCanonicalLocales(code.replace(/_/g, '-')).length === 1
  } catch {
    return false
  }
}

/** How one language of a row reads in the list. */
export type ConceptCell =
  | { state: 'own'; wording: string; ban: boolean }
  | { state: 'fallback'; wording: string; from: string }
  | { state: 'missing' }

/** What a language goes by: its Use, or the first Avoid of a ban. */
const wordingOf = (language: AiGlossaryConceptRowLanguage) => language.use ?? language.avoid[0] ?? ''

const baseOf = (code: string) => code.toLowerCase().split(/[-_]/)[0]

export const conceptCell = (row: AiGlossaryConceptRow, code: string): ConceptCell => {
  const own = row.languages[code]
  if (own) return { state: 'own', wording: wordingOf(own), ban: own.use === null }

  if (row.coverage[code] === 'fallback') {
    const from = Object.keys(row.languages).find((locale) => locale === baseOf(code))
    if (from) return { state: 'fallback', wording: wordingOf(row.languages[from]), from }
  }

  return { state: 'missing' }
}

/** The language a row is named by: the default language, else its first. */
export const conceptTitleLocale = (row: AiGlossaryConceptRow, defaultLocale: string | null): string | null =>
  defaultLocale && row.languages[defaultLocale] ? defaultLocale : (Object.keys(row.languages)[0] ?? null)

const titleLanguage = (row: AiGlossaryConceptRow, defaultLocale: string | null) => {
  const code = conceptTitleLocale(row, defaultLocale)
  return code ? row.languages[code] : undefined
}

/** The row's name: the wording in the default language, else its first language. */
export const conceptTitle = (row: AiGlossaryConceptRow, defaultLocale: string | null): string => {
  const language = titleLanguage(row, defaultLocale)
  return language ? wordingOf(language) : ''
}

/** Whether the row's name is Avoid wording, because that language has no Use. */
export const conceptTitleIsBan = (row: AiGlossaryConceptRow, defaultLocale: string | null): boolean =>
  titleLanguage(row, defaultLocale)?.use === null

/**
 * The languages with no wording of their own in any of these rows. Their column would
 * say the same thing in every row, so the list folds it and coverage carries the gap.
 */
export const columnsWithoutWording = (rows: readonly AiGlossaryConceptRow[], codes: readonly string[]): string[] =>
  rows.length === 0
    ? []
    : codes.filter((code) =>
        rows.every((row) => {
          return conceptCell(row, code).state !== 'own'
        })
      )

export interface ConceptLanguageForm {
  use: string
  avoid: string[]
  also: string[]
}

export interface ConceptForm {
  keep_exact: boolean
  note: string
  languages: Record<string, ConceptLanguageForm>
  removed: string[]
}

const emptyLanguage = (): ConceptLanguageForm => ({ use: '', avoid: [], also: [] })

/** A form for a row, or for a new concept when `row` is null, with a slot per locale. */
export const conceptFormOf = (row: AiGlossaryConceptRow | null, locales: readonly string[]): ConceptForm => {
  const codes = [...new Set([...locales, ...Object.keys(row?.languages ?? {})])]

  return {
    keep_exact: row?.keep_exact ?? false,
    note: row?.note ?? '',
    languages: Object.fromEntries(
      codes.map((code) => {
        const language = row?.languages[code]
        return [
          code,
          language
            ? { use: language.use ?? '', avoid: [...language.avoid], also: [...language.also] }
            : emptyLanguage(),
        ]
      })
    ),
    removed: [],
  }
}

export const addLanguageSlot = (form: ConceptForm, code: string) => {
  form.languages[code] ??= emptyLanguage()
}

const sameList = (a: readonly string[], b: readonly string[]) =>
  a.length === b.length && a.every((value, index) => value === b[index])

/** A language's changed fields. Empty when nothing changed. */
const languageChanges = (current: ConceptLanguageForm, stored: ConceptLanguageForm) => {
  const changes: AiGlossaryConceptLanguagePayload = {}
  const use = current.use.trim()

  if (use !== stored.use) changes.use = use || null
  if (!sameList(current.avoid, stored.avoid)) changes.avoid = current.avoid
  if (!sameList(current.also, stored.also)) changes.also = current.also

  return changes
}

const isBlank = (language: ConceptLanguageForm) =>
  !language.use.trim() && language.avoid.length === 0 && language.also.length === 0

export const NOTE_MAX_LENGTH = 200

const noteChanged = (form: ConceptForm, baseline: ConceptForm) => form.note.trim() !== baseline.note.trim()

/** An older, longer note reads fine until someone edits it. Only then does the limit apply. */
export const noteTooLong = (form: ConceptForm, baseline: ConceptForm) =>
  noteChanged(form, baseline) && form.note.trim().length > NOTE_MAX_LENGTH

/** Only what changed, because an omitted field keeps its value. */
export const conceptPayload = (
  form: ConceptForm,
  baseline: ConceptForm,
  row: AiGlossaryConceptRow | null
): AiGlossaryConceptPayload => {
  const payload: AiGlossaryConceptPayload = {}

  if (form.keep_exact !== baseline.keep_exact) payload.keep_exact = form.keep_exact
  if (noteChanged(form, baseline)) payload.note = form.note.trim() || null

  const languages: Record<string, AiGlossaryConceptLanguagePayload> = {}
  for (const [code, language] of Object.entries(form.languages)) {
    if (form.removed.includes(code)) continue
    if (!row?.languages[code] && isBlank(language)) continue

    const changes = languageChanges(language, baseline.languages[code] ?? emptyLanguage())
    if (Object.keys(changes).length > 0) languages[code] = changes
  }
  if (Object.keys(languages).length > 0) payload.languages = languages
  if (form.removed.length > 0) payload.remove_languages = [...form.removed]

  return payload
}

/**
 * The form moved onto a newer row after someone else saved: what this person changed
 * stays, everything else takes the newer value, and the newer row is the new baseline.
 * `replaced` names the fields where a next Save would overwrite that row's different value.
 */
export const conceptFormOnto = (form: ConceptForm, baseline: ConceptForm, newer: AiGlossaryConceptRow) => {
  const codes = Object.keys(form.languages)
  const moved = conceptFormOf(newer, codes)
  const stored = conceptFormOf(newer, codes)
  const replaced: AiGlossaryConceptRowChange[] = []

  if (form.keep_exact !== baseline.keep_exact) {
    moved.keep_exact = form.keep_exact
    if (stored.keep_exact !== baseline.keep_exact && form.keep_exact !== stored.keep_exact) replaced.push({ field: 'keep_exact', locale: null })
  }
  if (noteChanged(form, baseline)) {
    moved.note = form.note
    if (noteChanged(stored, baseline) && noteChanged(form, stored)) replaced.push({ field: 'note', locale: null })
  }
  moved.removed = form.removed.filter((code) => newer.languages[code])

  for (const code of codes) {
    const before = baseline.languages[code] ?? emptyLanguage()
    const typed = languageChanges(form.languages[code], before)
    const theirs = languageChanges(stored.languages[code], before)
    const apart = languageChanges(form.languages[code], stored.languages[code])
    const removing = moved.removed.includes(code)

    for (const field of ['use', 'avoid', 'also'] as const) {
      if (field in typed) Object.assign(moved.languages[code], { [field]: form.languages[code][field] })
      if (field in theirs && (removing || (field in typed && field in apart))) replaced.push({ field, locale: code })
    }
  }

  return { form: moved, baseline: stored, replaced }
}

export type ConceptFormError = { code: 'wording_required'; locale: string } | { code: 'no_language' }

const hasWording = (language: ConceptLanguageForm) => !!language.use.trim() || language.avoid.length > 0

/** What the server would refuse anyway, found before typing is sent. */
export const conceptFormError = (form: ConceptForm, row: AiGlossaryConceptRow | null): ConceptFormError | null => {
  for (const [code, language] of Object.entries(form.languages)) {
    if (form.removed.includes(code) || hasWording(language)) continue
    if (row?.languages[code] || !isBlank(language)) return { code: 'wording_required', locale: code }
  }

  const any = Object.entries(form.languages).some(([code, language]) => !form.removed.includes(code) && hasWording(language))
  return any ? null : { code: 'no_language' }
}

/** The filled or missing mark on a language tab. */
export type LanguageMark = 'filled' | 'fallback' | 'missing' | 'removed'

export const languageMark = (
  form: ConceptForm,
  code: string,
  row: AiGlossaryConceptRow | null
): LanguageMark => {
  if (form.removed.includes(code)) return 'removed'
  const language = form.languages[code]
  if (language && hasWording(language)) return 'filled'
  return row?.coverage[code] === 'fallback' ? 'fallback' : 'missing'
}

/** The Use to translate: filled in the source language, empty in the target. */
export const fillableUse = (source: ConceptLanguageForm | undefined, target: ConceptLanguageForm | undefined) => {
  const value = source?.use.trim()
  return value && target && !target.use.trim() ? value : null
}

/** Fills Use only while it is still empty, so text typed during the run stays. True when it did. */
export const applyAiFill = (target: ConceptLanguageForm, data: unknown): boolean => {
  if (typeof data !== 'object' || data === null || !('use' in data)) return false
  const { use } = data
  if (typeof use !== 'string' || !use.trim() || target.use.trim()) return false
  target.use = use.trim()
  return true
}

export type ConceptWriteError =
  | { kind: 'conflict'; conflict: AiGlossaryConceptConflict }
  | { kind: 'validation'; fields: Record<string, string[]>; message: string }
  | { kind: 'other'; message: string }

const hasShape = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null

/** Concept writes put a `code` on a body only when the writer refused them. */
const isConceptConflict = (body: unknown): body is AiGlossaryConceptConflict =>
  hasShape(body) && typeof body.code === 'string'

/** Sorts out what the API client rejected with. The client attaches the parsed body as `data`. */
export const conceptWriteError = (error: unknown): ConceptWriteError => {
  const message = error instanceof Error ? error.message : String(error)
  const body = hasShape(error) ? error.data : null

  if (isConceptConflict(body)) return { kind: 'conflict', conflict: body }
  if (hasShape(body) && hasShape(body.errors)) {
    return { kind: 'validation', fields: body.errors as Record<string, string[]>, message }
  }
  return { kind: 'other', message }
}

export interface MarkedSegment {
  text: string
  start: number
  matches: readonly AiGlossaryCheckMatch[]
}

/**
 * Cuts a text at every match boundary. Matches can overlap, so a segment carries
 * all the matches covering it. Offsets are UTF-16 code units, as `slice` takes them.
 */
export const markedSegments = (text: string, matches: readonly AiGlossaryCheckMatch[]): MarkedSegment[] => {
  const cuts = new Set([0, text.length])
  for (const { start, end } of matches) {
    if (start >= 0 && end <= text.length && start < end) {
      cuts.add(start)
      cuts.add(end)
    }
  }

  const points = [...cuts].sort((a, b) => a - b)
  return points.slice(0, -1).map((start, index) => {
    const end = points[index + 1]
    return {
      text: text.slice(start, end),
      start,
      matches: matches.filter((match) => match.start <= start && match.end >= end),
    }
  })
}

/** Inclusive range between two positions, in list order. */
export const rangeBetween = <T>(items: readonly T[], from: number, to: number): T[] =>
  items.slice(Math.min(from, to), Math.max(from, to) + 1)

/** A filter is set when it narrows the list. Paging is not a filter. */
export const hasActiveFilter = (filters: AiGlossaryConceptRowFilters) =>
  !!(filters.search || filters.missing)
