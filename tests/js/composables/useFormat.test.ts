import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import useFormat from '~/composables/useFormat'
import { setLocale } from '~/plugins/i18n'

type Format = ReturnType<typeof useFormat>
type VersionGroup = Parameters<Format['formatVersionTime']>[1]
type TrafficUnit = Parameters<Format['formatTrafficSize']>[1]

// Local-time constructor: every date assertion below must hold in any timezone,
// so fixtures are built in the runner's zone rather than parsed from UTC ISO.
const local = (
  year: number,
  month: number,
  day: number,
  hour = 0,
  minute = 0
) => new Date(year, month, day, hour, minute, 0, 0)

// Sunday, 15 March 2026, 12:00 local.
const NOW = local(2026, 2, 15, 12, 0)

let format: Format

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  format = useFormat()
})

afterEach(() => {
  vi.useRealTimers()
  setLocale('en')
})

describe('formatNumber', () => {
  it('groups thousands and rounds to no decimals by default', () => {
    expect(format.formatNumber(1234.5)).toBe('1,235')
    expect(format.formatNumber(1234567)).toBe('1,234,567')
  })

  it('pads to the requested number of decimals', () => {
    expect(format.formatNumber(1234.5, 2)).toBe('1,234.50')
    expect(format.formatNumber(1, 3)).toBe('1.000')
  })

  it('formats zero and negative values', () => {
    expect(format.formatNumber(0)).toBe('0')
    expect(format.formatNumber(-1234.5, 1)).toBe('-1,234.5')
  })

  it('rounds half away from zero', () => {
    expect(format.formatNumber(1.5)).toBe('2')
    expect(format.formatNumber(2.5)).toBe('3')
  })

  it('keeps the sign when rounding a tiny negative to zero', () => {
    expect(format.formatNumber(-0.004, 2)).toBe('-0.00')
  })

  it('formats very large numbers without switching to exponent notation', () => {
    expect(format.formatNumber(1e21)).toBe('1,000,000,000,000,000,000,000')
    expect(format.formatNumber(Number.MAX_SAFE_INTEGER)).toBe('9,007,199,254,740,991')
  })

  it('renders NaN and Infinity as Intl does', () => {
    expect(format.formatNumber(Number.NaN)).toBe('NaN')
    expect(format.formatNumber(Number.POSITIVE_INFINITY)).toBe('∞')
  })

  it('lets options override the decimal settings', () => {
    expect(format.formatNumber(0.256, 1, { style: 'percent' })).toBe('25.6%')
    expect(format.formatNumber(1.239, 0, { maximumFractionDigits: 2 })).toBe('1.24')
  })
})

describe('formatCurrency', () => {
  it('formats with the currency symbol and two decimals', () => {
    expect(format.formatCurrency(1234.5, 'USD')).toBe('$1,234.50')
    expect(format.formatCurrency(0, 'USD')).toBe('$0.00')
  })

  it('puts the minus sign in front of the symbol', () => {
    expect(format.formatCurrency(-0.5, 'EUR')).toBe('-€0.50')
  })

  it('lets options override the fraction digits', () => {
    expect(format.formatCurrency(1234.5, 'USD', { maximumFractionDigits: 0 })).toBe('$1,235')
  })

  // An unexpected code from a plan payload must not break the render.
  it('degrades to a plain amount plus the code for an unknown currency', () => {
    expect(format.formatCurrency(1234.5, 'NOT_A_CURRENCY')).toBe('1,234.50 NOT_A_CURRENCY')
  })
})

describe('formatDuration', () => {
  it('short-circuits zero with the requested unit', () => {
    expect(format.formatDuration(0)).toBe('0 ms')
    expect(format.formatDuration(0, 2, 's')).toBe('0 s')
  })

  it('formats milliseconds by default', () => {
    expect(format.formatDuration(1500)).toBe('1,500 ms')
    expect(format.formatDuration(1500, 2)).toBe('1,500.00 ms')
  })

  it('converts to seconds when asked', () => {
    expect(format.formatDuration(1500, 2, 's')).toBe('1.50 s')
    expect(format.formatDuration(250, 3, 's')).toBe('0.250 s')
  })

  it('rounds to whole seconds with no decimals', () => {
    expect(format.formatDuration(1500, 0, 's')).toBe('2 s')
  })

  it('formats negative durations', () => {
    expect(format.formatDuration(-500)).toBe('-500 ms')
  })
})

describe('formatTrafficSize', () => {
  it('short-circuits zero with the requested unit', () => {
    expect(format.formatTrafficSize(0)).toBe('0 KB')
    expect(format.formatTrafficSize(0, 'GB')).toBe('0 GB')
  })

  it('defaults to KB with two decimals', () => {
    expect(format.formatTrafficSize(1024)).toBe('1.00 KB')
    expect(format.formatTrafficSize(1536)).toBe('1.50 KB')
  })

  it('formats bytes without decimals', () => {
    expect(format.formatTrafficSize(512, 'B')).toBe('512 B')
    expect(format.formatTrafficSize(1536.7, 'B')).toBe('1,537 B')
  })

  it.each([
    [1024 ** 2, 'MB' as const],
    [1024 ** 3, 'GB' as const],
    [1024 ** 4, 'TB' as const],
  ])('formats %s bytes as one %s', (bytes, unit) => {
    expect(format.formatTrafficSize(bytes, unit)).toBe(`1.00 ${unit}`)
  })

  it('rounds 1023 B up to a full KB — the unit is caller-chosen, never rescaled', () => {
    expect(format.formatTrafficSize(1023)).toBe('1.00 KB')
  })

  it('collapses a value far below the chosen unit to zero', () => {
    expect(format.formatTrafficSize(1, 'TB')).toBe('0.00 TB')
  })

  it('formats negative traffic', () => {
    expect(format.formatTrafficSize(-1024)).toBe('-1.00 KB')
  })

  it('falls back to KB for an unrecognised unit', () => {
    expect(format.formatTrafficSize(2048, 'PB' as unknown as TrafficUnit)).toBe('2.00 PB')
  })
})

describe('formatFileSize', () => {
  it('renders zero as bytes', () => {
    expect(format.formatFileSize(0)).toBe('0 B')
  })

  it('keeps values below 1 KB in whole bytes', () => {
    expect(format.formatFileSize(1)).toBe('1 B')
    expect(format.formatFileSize(1023)).toBe('1,023 B')
  })

  it('switches unit exactly at 1024', () => {
    expect(format.formatFileSize(1024)).toBe('1.0 KB')
    expect(format.formatFileSize(1536)).toBe('1.5 KB')
  })

  it.each([
    [1024 ** 2, '1.0 MB'],
    [1024 ** 3, '1.0 GB'],
    [1024 ** 4, '1.0 TB'],
    [1024 ** 5, '1.0 PB'],
  ])('scales %s bytes to %s', (bytes, expected) => {
    expect(format.formatFileSize(bytes)).toBe(expected)
  })

  it('promotes the unit when the mantissa rounds up to 1024', () => {
    // The unit came from the raw byte count while the mantissa rounded
    // independently, so the two used to disagree at a boundary.
    expect(format.formatFileSize(1023.6)).toBe('1.0 KB')
  })

  it('stays on the largest known unit above 1 PiB', () => {
    expect(format.formatFileSize(1024 ** 6)).toBe('1,024.0 PB')
  })

  it('clamps to bytes for a fractional byte count', () => {
    expect(format.formatFileSize(0.5)).toBe('1 B')
  })

  it('keeps the sign and the unit for a negative size', () => {
    expect(format.formatFileSize(-1024)).toBe('-1.0 KB')
    expect(format.formatFileSize(-(1024 ** 3))).toBe('-1.0 GB')
  })

  // NaN is reachable from an absent `size`; nothing unrepresentable may reach
  // the asset library as the literal string "undefined".
  it.each([Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY])(
    'reports %s as 0 B',
    (bytes) => {
      expect(format.formatFileSize(bytes)).toBe('0 B')
    }
  )
})

describe('formatDateTime', () => {
  it('uses the localized long format by default', () => {
    expect(format.formatDateTime(local(2026, 2, 15, 15, 30))).toBe('March 15, 2026 3:30 PM')
  })

  it('accepts a custom dayjs format', () => {
    expect(format.formatDateTime(local(2026, 2, 15, 15, 30), 'YYYY-MM-DD HH:mm')).toBe(
      '2026-03-15 15:30'
    )
  })

  it('accepts an epoch millisecond number and an ISO string', () => {
    const date = local(2026, 2, 15, 15, 30)

    expect(format.formatDateTime(date.getTime(), 'YYYY-MM-DD')).toBe('2026-03-15')
    expect(format.formatDateTime(date.toISOString(), 'YYYY-MM-DD')).toBe('2026-03-15')
  })

  it.each(['', 'not-a-date'])('renders %s as Invalid Date', (input) => {
    expect(format.formatDateTime(input)).toBe('Invalid Date')
  })
})

describe('formatTime', () => {
  it('renders the localized time only', () => {
    expect(format.formatTime(local(2026, 2, 15, 15, 30))).toBe('3:30 PM')
    expect(format.formatTime(local(2026, 2, 15, 0, 5))).toBe('12:05 AM')
  })
})

describe('formatRelativeTime', () => {
  it.each([
    [local(2026, 2, 13, 12, 0), '2 days ago'],
    [local(2026, 2, 15, 15, 0), 'in 3 hours'],
    [new Date(NOW.getTime() - 30_000), 'a few seconds ago'],
  ])('describes the distance from now', (date, expected) => {
    expect(format.formatRelativeTime(date)).toBe(expected)
  })

  // dayjs' relativeTime feeds a NaN diff through its thresholds and lands in
  // the month bucket, so bad data used to render as plausible copy.
  it('flags an invalid date instead of describing it as "a month ago"', () => {
    expect(format.formatRelativeTime('not-a-date')).toBe('Invalid Date')
  })
})

describe('formatDateTimeDynamically', () => {
  it('stays relative inside the cutoff', () => {
    expect(format.formatDateTimeDynamically(local(2026, 2, 15, 9, 0))).toBe('3 hours ago')
  })

  it('switches to an absolute date beyond the cutoff', () => {
    expect(format.formatDateTimeDynamically(local(2026, 2, 10, 12, 0))).toBe(
      'March 10, 2026 12:00 PM'
    )
  })

  it('treats exactly the cutoff as still relative', () => {
    expect(format.formatDateTimeDynamically(local(2026, 2, 14, 12, 0))).toBe('a day ago')
  })

  it('honours a custom cutoff', () => {
    const date = local(2026, 2, 12, 12, 0)

    expect(format.formatDateTimeDynamically(date, 5)).toBe('3 days ago')
    expect(format.formatDateTimeDynamically(date, 1)).toBe('March 12, 2026 12:00 PM')
  })

  it('applies the cutoff to future dates too — the diff is absolute', () => {
    expect(format.formatDateTimeDynamically(local(2026, 2, 20, 12, 0))).toBe(
      'March 20, 2026 12:00 PM'
    )
    expect(format.formatDateTimeDynamically(local(2026, 2, 16, 12, 0))).toBe('in a day')
  })
})

describe('formatCalendarTime', () => {
  it.each([
    [local(2026, 2, 15, 15, 30), 'Today at 3:30 PM'],
    [local(2026, 2, 14, 9, 5), 'Yesterday at 9:05 AM'],
    [local(2026, 2, 16, 9, 5), 'Tomorrow at 9:05 AM'],
    [local(2026, 2, 10, 9, 5), 'Last Tuesday at 9:05 AM'],
    [local(2026, 2, 19, 9, 5), 'Thursday at 9:05 AM'],
    [local(2026, 0, 2, 9, 5), 'January 2, 2026'],
  ])('describes the day in calendar terms', (date, expected) => {
    expect(format.formatCalendarTime(date)).toBe(expected)
  })
})

describe('formatVersionTime', () => {
  const date = local(2026, 2, 15, 15, 30)

  it.each([
    ['today' as const, '15:30'],
    ['yesterday' as const, '15:30'],
    ['thisWeek' as const, 'Sun 15:30'],
    ['lastWeek' as const, 'Sun 15:30'],
    ['older' as const, 'Mar 15, 2026'],
  ])('formats the %s group', (group, expected) => {
    expect(format.formatVersionTime(date, group)).toBe(expected)
  })

  it('falls back to the long format for an unknown group', () => {
    expect(format.formatVersionTime(date, 'someday' as unknown as VersionGroup)).toBe(
      'March 15, 2026 3:30 PM'
    )
  })
})

describe('locale', () => {
  it('formats numbers, currency and dates in the active locale', () => {
    setLocale('de')
    const german = useFormat()

    expect(german.formatNumber(1234.5, 2)).toBe('1.234,50')
    expect(german.formatCurrency(1234.5, 'EUR')).toContain('1.234,50')
    expect(german.formatDateTime(local(2026, 2, 15, 15, 30))).toBe('15. März 2026 15:30')
  })

  // A long-lived component calls useFormat() once in setup and has to follow
  // the language switcher for the rest of its life.
  it('follows a later language switch instead of snapshotting the locale', () => {
    setLocale('de')

    expect(format.formatNumber(1234.5, 2)).toBe('1.234,50')
  })

  it('translates the calendar labels rather than stamping English on every locale', () => {
    setLocale('de')

    expect(format.formatCalendarTime(local(2026, 2, 14, 9, 5))).toBe('Gestern um 09:05')
  })
})
