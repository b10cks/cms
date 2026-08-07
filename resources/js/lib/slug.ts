/**
 * The frontend twin of `App\Services\Slug\Slugger`.
 *
 * Every "type a name, watch a slug appear" field in the app goes through here,
 * so what an editor is shown is what the backend will store. Change one side and
 * you must change the other.
 *
 * One documented divergence: the backend runs input through portable-ascii,
 * whose table covers non-Latin scripts ("Привет" -> "privet") and a handful of
 * exotic numeric forms. Shipping that table to the browser is not worth the
 * kilobytes, so here those characters fold via NFKD or drop out entirely — a
 * CJK title previews as "" and the server fills the real value in on save.
 * Latin scripts, the ones a slug preview actually needs to be right about,
 * match exactly; tests/fixtures/slug-cases.json is the contract.
 */

export type SlugCase = 'lower' | 'camel'

export interface SlugOptions {
  /** BCP-47 tag or bare code. Region subtags are dropped, as on the backend. */
  language?: string | null
  separator?: string
  maxLength?: number
  case?: SlugCase
  /**
   * Underscores are a legal, meaningful slug character and survive by default.
   * Pass false for the identifier slugs whose validators restrict them to
   * `^[a-z0-9-]+$` (spaces, data sources, icon keys).
   */
  allowUnderscore?: boolean
}

/** Mirrors Slugger::CONTENT_SLUG_LENGTH and the `max:75` request rule. */
export const CONTENT_SLUG_LENGTH = 75

const LANGUAGE_ALIASES: Record<string, string> = {
  nb: 'no',
  nn: 'no',
  iw: 'he',
  in: 'id',
  ji: 'yi',
}

/**
 * Per-language expansions, applied before the generic fold.
 *
 * These are the cases where folding to a bare letter loses the word: German
 * "Müller" is "mueller", not "muller". Languages absent from this map keep the
 * fold, which is what French and Turkish want.
 */
const LANGUAGE_EXPANSIONS: Record<string, Record<string, string>> = {
  de: { ä: 'ae', ö: 'oe', ü: 'ue', Ä: 'ae', Ö: 'oe', Ü: 'ue' },
  da: { å: 'aa', æ: 'ae', ø: 'oe', Å: 'aa', Æ: 'ae', Ø: 'oe' },
  no: { å: 'aa', æ: 'ae', ø: 'oe', Å: 'aa', Æ: 'ae', Ø: 'oe' },
  pt: { å: 'aa', ð: 'dj', ø: 'oe', Å: 'aa', Ð: 'dj', Ø: 'oe' },
  hr: { đ: 'dj', Đ: 'dj' },
  sr: { đ: 'dj', Đ: 'dj' },
}

/**
 * Latin letters with no Unicode decomposition, which NFKD therefore cannot fold.
 * Without these, "Łódź" loses its Ł and "Blåbær" its æ.
 */
const BASE_EXPANSIONS: Record<string, string> = {
  ß: 'ss',
  ẞ: 'ss',
  æ: 'ae',
  Æ: 'ae',
  œ: 'oe',
  Œ: 'oe',
  ø: 'o',
  Ø: 'o',
  ł: 'l',
  Ł: 'l',
  đ: 'd',
  Đ: 'd',
  ð: 'd',
  Ð: 'd',
  þ: 'th',
  Þ: 'th',
  ı: 'i',
  İ: 'i',
  ħ: 'h',
  Ħ: 'h',
  ŧ: 't',
  Ŧ: 't',
  ŋ: 'n',
  Ŋ: 'n',
}

const AMPERSAND: Record<string, string> = {
  bg: 'i',
  cs: 'a',
  da: 'og',
  de: 'und',
  el: 'kai',
  es: 'y',
  et: 'ja',
  fi: 'ja',
  fr: 'et',
  hr: 'i',
  hu: 'es',
  it: 'e',
  lt: 'ir',
  lv: 'un',
  nl: 'en',
  no: 'og',
  pl: 'i',
  pt: 'e',
  ro: 'si',
  ru: 'i',
  sk: 'a',
  sl: 'in',
  sr: 'i',
  sv: 'och',
  tr: 've',
  uk: 'i',
}

const SYMBOLS: Record<string, string> = {
  '@': 'at',
  '%': 'percent',
  '€': 'eur',
  '£': 'gbp',
  $: 'usd',
  '©': 'c',
  '®': 'r',
  '№': 'no',
}

const escapeRegExp = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

const normalizeLanguage = (language?: string | null): string | null => {
  if (!language) {
    return null
  }

  const primary = language.trim().toLowerCase().split(/[-_]/)[0]

  if (!primary) {
    return null
  }

  return LANGUAGE_ALIASES[primary] ?? primary
}

const expand = (value: string, map: Record<string, string>) =>
  value.replace(/./gu, char => map[char] ?? char)

/**
 * Truncate on a separator boundary so the tail is a whole word. A single word
 * longer than the limit still has to be cut somewhere.
 */
const truncate = (value: string, separator: string, maxLength: number) => {
  if (maxLength <= 0 || value.length <= maxLength) {
    return value
  }

  const cut = value.slice(0, maxLength)
  const boundary = cut.lastIndexOf(separator)

  return (boundary > 0 ? cut.slice(0, boundary) : cut).replace(
    new RegExp(`^${escapeRegExp(separator)}+|${escapeRegExp(separator)}+$`, 'g'),
    ''
  )
}

const toCamel = (value: string, separator: string) => {
  const parts = value.split(separator).filter(Boolean)

  return parts
    .map((part, index) => (index === 0 ? part : part.charAt(0).toUpperCase() + part.slice(1)))
    .join('')
}

export function slugify(value: string, options: SlugOptions = {}): string {
  const {
    separator = '-',
    maxLength,
    case: casing = 'lower',
    allowUnderscore = true,
  } = options
  const language = normalizeLanguage(options.language)

  let result = (value ?? '').trim()

  if (!result) {
    return ''
  }

  const symbols: Record<string, string> = {
    ...SYMBOLS,
    '&': AMPERSAND[language ?? ''] ?? 'and',
  }
  result = result.replace(/[@%€£$©®№&]/g, char => `${separator}${symbols[char]}${separator}`)

  // Language rules first: they must see "ü" before the generic pass folds it
  // to "u".
  const expansions = language ? LANGUAGE_EXPANSIONS[language] : undefined
  if (expansions) {
    result = expand(result, expansions)
  }

  result = expand(result, BASE_EXPANSIONS)
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()

  const quoted = escapeRegExp(separator)
  const keep = allowUnderscore ? '_' : ''
  result = result
    .replace(new RegExp(`[^a-z0-9${keep}${quoted}]+`, 'g'), separator)
    .replace(new RegExp(`${quoted}+`, 'g'), separator)
    .replace(new RegExp(`^${quoted}+|${quoted}+$`, 'g'), '')

  if (maxLength !== undefined) {
    result = truncate(result, separator, maxLength)
  }

  return casing === 'camel' ? toCamel(result, separator) : result
}

/**
 * A content slug, in the language that entry is written in.
 *
 * Content slugs have no character rule beyond `max:75`, so underscores are kept.
 */
export const slugifyContent = (value: string, language?: string | null) =>
  slugify(value, { language, maxLength: CONTENT_SLUG_LENGTH })

/**
 * A space-level identifier — a space, data source or icon key — whose validators
 * allow only `^[a-z0-9-]+$`.
 */
export const slugifyIdentifier = (
  value: string,
  language?: string | null,
  maxLength?: number
) => slugify(value, { language, maxLength, allowUnderscore: false })
