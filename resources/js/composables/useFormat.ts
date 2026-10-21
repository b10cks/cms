import dayjs from 'dayjs'
import 'dayjs/locale/de'
import 'dayjs/locale/es'
import 'dayjs/locale/fr'
import 'dayjs/locale/ru'
import 'dayjs/locale/tr'
import Calendar from 'dayjs/plugin/calendar'
import LocalizedFormat from 'dayjs/plugin/localizedFormat'
import RelativeTime from 'dayjs/plugin/relativeTime'
import Timezone from 'dayjs/plugin/timezone'
import UpdateLocale from 'dayjs/plugin/updateLocale'
import Utc from 'dayjs/plugin/utc'

import type { DisplayFormats } from '~/lib/display-formats'
import { displayFormats } from '~/lib/display-formats'
import type { LocaleCode } from '~/plugins/i18n'
import { getLocale } from '~/plugins/i18n'

dayjs.extend(LocalizedFormat)
dayjs.extend(RelativeTime)
dayjs.extend(Calendar)
dayjs.extend(UpdateLocale)
dayjs.extend(Utc)
dayjs.extend(Timezone)

// dayjs ships no per-locale `calendar` block, so the relative-day labels are
// declared here — once per locale at module load. Writing them inside
// useFormat() stamped the English strings onto whichever locale happened to be
// active, which is global state shared with every other dayjs consumer.
const CALENDAR_FORMATS: Record<LocaleCode, Record<string, string>> = {
  en: {
    lastDay: '[Yesterday at] LT',
    sameDay: '[Today at] LT',
    nextDay: '[Tomorrow at] LT',
    lastWeek: '[Last] dddd [at] LT',
    nextWeek: 'dddd [at] LT',
    sameElse: 'LL',
  },
  es: {
    lastDay: '[Ayer a las] LT',
    sameDay: '[Hoy a las] LT',
    nextDay: '[Mañana a las] LT',
    lastWeek: '[El pasado] dddd [a las] LT',
    nextWeek: 'dddd [a las] LT',
    sameElse: 'LL',
  },
  fr: {
    lastDay: '[Hier à] LT',
    sameDay: "[Aujourd'hui à] LT",
    nextDay: '[Demain à] LT',
    lastWeek: 'dddd [dernier à] LT',
    nextWeek: 'dddd [à] LT',
    sameElse: 'LL',
  },
  ru: {
    lastDay: '[Вчера в] LT',
    sameDay: '[Сегодня в] LT',
    nextDay: '[Завтра в] LT',
    lastWeek: 'dddd[, на прошлой неделе в] LT',
    nextWeek: 'dddd [в] LT',
    sameElse: 'LL',
  },
  tr: {
    lastDay: '[Dün saat] LT',
    sameDay: '[Bugün saat] LT',
    nextDay: '[Yarın saat] LT',
    lastWeek: '[Geçen] dddd [saat] LT',
    nextWeek: 'dddd [saat] LT',
    sameElse: 'LL',
  },
  de: {
    lastDay: '[Gestern um] LT',
    sameDay: '[Heute um] LT',
    nextDay: '[Morgen um] LT',
    lastWeek: '[Letzten] dddd [um] LT',
    nextWeek: 'dddd [um] LT',
    sameElse: 'LL',
  },
}

for (const [code, calendar] of Object.entries(CALENDAR_FORMATS)) {
  dayjs.updateLocale(code, { calendar })
}

type LocalizedToken = 'LT' | 'LTS' | 'L' | 'LL' | 'LLL' | 'LLLL'

// dayjs keeps English in the localizedFormat plugin, not on the locale object.
const ENGLISH_FORMATS: Record<LocalizedToken, string> = {
  LTS: 'h:mm:ss A',
  LT: 'h:mm A',
  L: 'MM/DD/YYYY',
  LL: 'MMMM D, YYYY',
  LLL: 'MMMM D, YYYY h:mm A',
  LLLL: 'dddd, MMMM D, YYYY h:mm A',
}

const DATE_ORDER_FORMATS: Record<Exclude<DisplayFormats['dateOrder'], 'auto'>, string> = {
  dmy: 'DD.MM.YYYY',
  mdy: 'MM/DD/YYYY',
  ymd: 'YYYY-MM-DD',
}

// `[...]` is an escaped literal in dayjs formats and must pass through untouched.
const LOCALIZED_TOKENS = /(\[[^\]]*])|(LTS?|l{1,4}|L{1,4})/g
const CLOCK_TOKENS = /(\[[^\]]*])|(\s?[aA])|([Hh]{1,2})(:mm(?::ss)?)?/g
const LITERALS = /\[[^\]]*]/g

// The lowercase `l` tokens are their uppercase twins with short month and day names.
const shorten = (format: string) =>
  format.replace(/(\[[^\]]*])|(MMMM|MM|DD|dddd)/g, (_, literal?: string, token?: string) =>
    literal ? literal : (token ?? '').slice(1)
  )

/**
 * Rewrites a dayjs format so it follows the user's date order and clock.
 * Localized tokens (`L`, `LL`, `LLL`, `LT`, …) are expanded first, so the
 * order replaces their date part; the clock then applies to every hour token,
 * including those of fixed formats like `HH:mm`. Fixed date parts stay as asked.
 */
function resolveFormat(format: string, locale: string, formats: DisplayFormats): string {
  if (formats.dateOrder === 'auto' && formats.clock === 'auto') return format

  const own: Record<LocalizedToken, string> = { ...ENGLISH_FORMATS, ...dayjs.Ls[locale]?.formats }
  const date = formats.dateOrder === 'auto' ? null : DATE_ORDER_FORMATS[formats.dateOrder]

  const expand = (token: LocalizedToken): string => {
    if (!date || token === 'LT' || token === 'LTS') return own[token]
    if (token === 'LLL') return `${date} ${own.LT}`
    if (token === 'LLLL') return `dddd, ${date} ${own.LT}`
    return date
  }

  const expanded = format.replace(
    LOCALIZED_TOKENS,
    (match, literal: string | undefined, token: string) => {
      if (literal) return match
      const upper = token.toUpperCase() as LocalizedToken
      // A chosen order is numeric already; shortening it would drop the zero padding.
      return token === upper || date ? expand(upper) : shorten(expand(upper))
    }
  )

  if (formats.clock === 'auto') return expanded

  const hasMeridiem = /[aA]/.test(expanded.replace(LITERALS, ''))
  return expanded.replace(
    CLOCK_TOKENS,
    (match, literal?: string, meridiem?: string, _hour?: string, rest = '') => {
      if (literal) return match
      if (meridiem) return formats.clock === '12h' ? match : ''
      return formats.clock === '24h' ? `HH${rest}` : `h${rest}${hasMeridiem ? '' : ' A'}`
    }
  )
}

// A bare calendar day has no zone; shifting it would move it to a neighbouring day.
const isCalendarDay = (date: string | Date | number) =>
  typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date)

const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

export default function useFormat() {
  // Computed, not a snapshot: an instance created before a language switch has
  // to follow the switcher for the rest of its life.
  const locale = computed(() => getLocale())

  // `formats` defaults to the user's choices; the settings page passes others to preview them.
  function zoned(date: string | Date | number, formats: DisplayFormats) {
    const parsed = dayjs(date).locale(locale.value)
    if (formats.timeZone === 'auto' || isCalendarDay(date)) return parsed
    return parsed.tz(formats.timeZone)
  }

  function formatDateTime(
    date: string | Date | number,
    format: string = 'LLL',
    formats: DisplayFormats = displayFormats
  ) {
    return zoned(date, formats).format(resolveFormat(format, locale.value, formats))
  }

  function formatDate(date: string | Date | number, formats: DisplayFormats = displayFormats) {
    return formatDateTime(date, 'll', formats)
  }

  function formatRelativeTime(date: string | Date | number) {
    const parsed = dayjs(date).locale(locale.value)
    // dayjs pushes a NaN diff through its thresholds and renders "a month ago",
    // hiding bad data behind plausible copy. Surface it like formatDateTime does.
    if (!parsed.isValid()) return parsed.format()
    return parsed.fromNow()
  }

  function formatTime(date: string | Date | number, formats: DisplayFormats = displayFormats) {
    return formatDateTime(date, 'LT', formats)
  }

  function formatDateTimeDynamically(date: string | Date | number, relativeCutOff: number = 1) {
    const now = dayjs()
    const diff = Math.abs(now.diff(dayjs(date), 'days'))

    if (diff > relativeCutOff) {
      return formatDateTime(date)
    }
    return formatRelativeTime(date)
  }

  function formatCalendarTime(date: string | Date | number) {
    const calendar = CALENDAR_FORMATS[locale.value as LocaleCode] ?? CALENDAR_FORMATS.en
    const resolved = Object.fromEntries(
      Object.entries(calendar).map(([key, format]) => [
        key,
        resolveFormat(format, locale.value, displayFormats),
      ])
    )
    // "Today" is today in the display zone, so the reference moves with it.
    return zoned(date, displayFormats).calendar(zoned(Date.now(), displayFormats), resolved)
  }

  function formatVersionTime(
    date: string | Date | number,
    group: 'today' | 'yesterday' | 'thisWeek' | 'lastWeek' | 'older'
  ) {
    switch (group) {
      case 'today':
      case 'yesterday':
        return formatDateTime(date, 'LT')
      case 'thisWeek':
      case 'lastWeek':
        return formatDateTime(date, 'ddd LT')
      case 'older':
        return formatDateTime(date, 'll')
      default:
        return formatDateTime(date)
    }
  }

  function formatCurrency(value: number, currency: string, options: Intl.NumberFormatOptions = {}) {
    try {
      return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency,
        ...options,
      }).format(value)
    } catch {
      // An unexpected currency code from a plan payload makes Intl throw a
      // RangeError; degrade to "12.00 XYZ" rather than breaking the render.
      return `${formatNumber(value, 2)} ${currency}`.trim()
    }
  }

  function formatDuration(
    milliseconds: number,
    decimals: number = 0,
    unit: 'ms' | 's' = 'ms'
  ): string {
    if (milliseconds === 0) return `0 ${unit}`

    // If unit is seconds, convert milliseconds to seconds
    const value = unit === 's' ? milliseconds / 1000 : milliseconds

    return (
      new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: decimals,
        minimumFractionDigits: decimals,
      }).format(value) + ` ${unit}`
    )
  }

  function formatNumber(
    value: number,
    decimals: number = 0,
    options: Intl.NumberFormatOptions = {}
  ): string {
    return new Intl.NumberFormat(locale.value, {
      maximumFractionDigits: decimals,
      minimumFractionDigits: decimals,
      ...options,
    }).format(value)
  }

  function formatTrafficSize(bytes: number, unit: 'B' | 'KB' | 'MB' | 'GB' | 'TB' = 'KB'): string {
    if (bytes === 0) return `0 ${unit}`

    let value: number
    switch (unit) {
      case 'B':
        value = bytes
        break
      case 'MB':
        value = bytes / 1024 ** 2
        break
      case 'GB':
        value = bytes / 1024 ** 3
        break
      case 'TB':
        value = bytes / 1024 ** 4
        break
      default:
        value = bytes / 1024
    }

    return (
      new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: unit === 'B' ? 0 : 2,
        minimumFractionDigits: unit === 'B' ? 0 : 2,
      }).format(value) + ` ${unit}`
    )
  }

  function formatFileSize(bytes: number): string {
    // NaN/Infinity reach here from an absent `size`; anything unrepresentable
    // is reported as 0 rather than as the literal string "undefined".
    if (!Number.isFinite(bytes) || bytes === 0) return '0 B'

    const magnitude = Math.abs(bytes)
    // Clamped: sub-byte values give a negative index and >= 1 EiB overruns the
    // unit list — both used to render "undefined" as the unit.
    let i = Math.min(
      Math.max(Math.floor(Math.log(magnitude) / Math.log(1024)), 0),
      FILE_SIZE_UNITS.length - 1
    )

    const digits = (index: number) => (index === 0 ? 0 : 1)
    // The unit came from the raw byte count while the mantissa rounded
    // independently, so 1023.6 rendered as "1,024 B". Step up instead.
    if (
      i < FILE_SIZE_UNITS.length - 1 &&
      Number(Math.abs(bytes / 1024 ** i).toFixed(digits(i))) >= 1024
    ) {
      i += 1
    }

    return (
      new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: digits(i),
        minimumFractionDigits: digits(i),
      }).format(bytes / 1024 ** i) +
      ' ' +
      FILE_SIZE_UNITS[i]
    )
  }

  return {
    formatDateTime,
    formatDate,
    formatFileSize,
    formatCurrency,
    formatVersionTime,
    formatTrafficSize,
    formatRelativeTime,
    formatCalendarTime,
    formatDateTimeDynamically,
    formatDuration,
    formatNumber,
    formatTime,
  }
}
