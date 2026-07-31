import { beforeEach, describe, expect, it } from 'vitest'

import { useUsageFormatters } from '~/composables/useUsageFormatters'
import { setLocale } from '~/plugins/i18n'

const GB = 1024 ** 3
const MB = 1024 ** 2

let formatters: ReturnType<typeof useUsageFormatters>

beforeEach(() => {
  formatters = useUsageFormatters()
})

describe('formatBytes', () => {
  it.each([null, undefined])('reports %s as unlimited', (value) => {
    expect(formatters.formatBytes(value)).toBe('Unlimited')
  })

  it('renders sub-gigabyte values as whole megabytes', () => {
    expect(formatters.formatBytes(0)).toBe('0 MB')
    expect(formatters.formatBytes(512 * MB)).toBe('512 MB')
    expect(formatters.formatBytes(1.5 * MB)).toBe('2 MB')
  })

  it('rounds a value below 1 MB down to 0 MB', () => {
    expect(formatters.formatBytes(1023)).toBe('0 MB')
  })

  it('switches to gigabytes exactly at 1 GiB', () => {
    expect(formatters.formatBytes(GB - 1)).toBe('1024 MB')
    expect(formatters.formatBytes(GB)).toBe('1.0 GB')
  })

  it('keeps one decimal below 10 GB and drops it from 10 GB up', () => {
    expect(formatters.formatBytes(2.5 * GB)).toBe('2.5 GB')
    // toFixed rounds the binary double, and 2.55 is stored just below the
    // midpoint, so it rounds down where a decimal-half-up rule would not.
    expect(formatters.formatBytes(2.55 * GB)).toBe('2.5 GB')
    expect(formatters.formatBytes(10 * GB)).toBe('10 GB')
    expect(formatters.formatBytes(123.4 * GB)).toBe('123 GB')
  })

  it('rounds 9.99 GB to "10.0 GB" — the decimal rule reads the raw value', () => {
    expect(formatters.formatBytes(9.99 * GB)).toBe('10.0 GB')
  })

  it('scales past GB into terabytes', () => {
    expect(formatters.formatBytes(5 * 1024 ** 4)).toBe('5.0 TB')
    expect(formatters.formatBytes(20 * 1024 ** 4)).toBe('20 TB')
  })

  // The unit is chosen from the magnitude, so a negative delta or remaining
  // quota does not fall through to a four-digit megabyte figure.
  it('scales negative byte counts the same way as positive ones', () => {
    expect(formatters.formatBytes(-2 * GB)).toBe('-2.0 GB')
    expect(formatters.formatBytes(-512 * MB)).toBe('-512 MB')
  })
})

describe('formatNumber', () => {
  it.each([null, undefined])('reports %s as unlimited', (value) => {
    expect(formatters.formatNumber(value)).toBe('Unlimited')
  })

  it('groups thousands in the app locale', () => {
    // The app locale, not the browser's — these figures sit next to useFormat's
    // output on the usage pages.
    expect(formatters.formatNumber(1234567)).toBe('1,234,567')

    setLocale('de')
    expect(useUsageFormatters().formatNumber(1234567)).toBe('1.234.567')
    setLocale('en')
  })

  it('formats zero and negative counts', () => {
    expect(formatters.formatNumber(0)).toBe('0')
    expect(formatters.formatNumber(-1500)).toBe('-1,500')
  })

  it('keeps at most three decimals — Intl default', () => {
    expect(formatters.formatNumber(1.23456)).toBe('1.235')
  })
})

describe('formatUnit', () => {
  it.each(['bytes' as const, 'count' as const, 'usd' as const])(
    'reports a null %s quota as unlimited',
    (unit) => {
      expect(formatters.formatUnit(null, unit)).toBe('Unlimited')
      expect(formatters.formatUnit(undefined, unit)).toBe('Unlimited')
    }
  )

  it('delegates bytes to formatBytes', () => {
    expect(formatters.formatUnit(2 * GB, 'bytes')).toBe('2.0 GB')
  })

  it('delegates counts to formatNumber', () => {
    expect(formatters.formatUnit(12345, 'count')).toBe('12,345')
  })

  it('formats USD with two decimals', () => {
    expect(formatters.formatUnit(0, 'usd')).toBe('$0.00')
    expect(formatters.formatUnit(12.5, 'usd')).toBe('$12.50')
    expect(formatters.formatUnit(1234.567, 'usd')).toBe('$1,234.57')
  })

  it('widens to four decimals for sub-dollar spend', () => {
    expect(formatters.formatUnit(0.0123, 'usd')).toBe('$0.0123')
    expect(formatters.formatUnit(0.00004, 'usd')).toBe('$0.00')
  })

  it('keeps only two decimals at exactly 1 USD', () => {
    expect(formatters.formatUnit(1, 'usd')).toBe('$1.00')
  })

  // The widening is gated on magnitude, so a sub-cent credit keeps the same
  // precision as the equivalent spend.
  it('keeps sub-cent precision on small negative USD amounts', () => {
    expect(formatters.formatUnit(-0.0123, 'usd')).toBe('-$0.0123')
  })

  it('falls back to the count formatter for an unrecognised unit', () => {
    expect(formatters.formatUnit(42, 'requests' as unknown as UsageUnit)).toBe('42')
  })
})
