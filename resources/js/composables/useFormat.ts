import dayjs from 'dayjs'
import 'dayjs/locale/de'
import Calendar from 'dayjs/plugin/calendar'
import LocalizedFormat from 'dayjs/plugin/localizedFormat'
import RelativeTime from 'dayjs/plugin/relativeTime'
import UpdateLocale from 'dayjs/plugin/updateLocale'

import { getLocale } from '~/plugins/i18n'

dayjs.extend(LocalizedFormat)
dayjs.extend(RelativeTime)
dayjs.extend(Calendar)
dayjs.extend(UpdateLocale)

// dayjs ships no per-locale `calendar` block, so the relative-day labels are
// declared here — once per locale at module load. Writing them inside
// useFormat() stamped the English strings onto whichever locale happened to be
// active, which is global state shared with every other dayjs consumer.
const CALENDAR_FORMATS: Record<string, Record<string, string>> = {
  en: {
    lastDay: '[Yesterday at] LT',
    sameDay: '[Today at] LT',
    nextDay: '[Tomorrow at] LT',
    lastWeek: '[Last] dddd [at] LT',
    nextWeek: 'dddd [at] LT',
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

const FILE_SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

export default function useFormat() {
  // Computed, not a snapshot: an instance created before a language switch has
  // to follow the switcher for the rest of its life.
  const locale = computed(() => getLocale())

  function formatDateTime(date: string | Date | number, format: string = 'LLL') {
    return dayjs(date).locale(locale.value).format(format)
  }

  function formatRelativeTime(date: string | Date | number) {
    const parsed = dayjs(date).locale(locale.value)
    // dayjs pushes a NaN diff through its thresholds and renders "a month ago",
    // hiding bad data behind plausible copy. Surface it like formatDateTime does.
    if (!parsed.isValid()) return parsed.format()
    return parsed.fromNow()
  }

  function formatTime(date: string | Date | number) {
    return dayjs(date).locale(locale.value).format('LT')
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
    return dayjs(date).locale(locale.value).calendar()
  }

  function formatVersionTime(
    date: string | Date | number,
    group: 'today' | 'yesterday' | 'thisWeek' | 'lastWeek' | 'older'
  ) {
    switch (group) {
      case 'today':
      case 'yesterday':
        return dayjs(date).locale(locale.value).format('HH:mm')
      case 'thisWeek':
      case 'lastWeek':
        return dayjs(date).locale(locale.value).format('ddd HH:mm')
      case 'older':
        return dayjs(date).locale(locale.value).format('MMM D, YYYY')
      default:
        return dayjs(date).locale(locale.value).format('LLL')
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
