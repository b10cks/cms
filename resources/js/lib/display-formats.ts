import { reactive } from 'vue'

/**
 * How the signed-in user wants dates and times written, independent of the
 * interface language. `auto` follows the language (or, for the zone, the
 * browser). `useUserSettings` writes these, `useFormat` reads them; they live
 * here so neither composable has to import the other.
 */

export const DATE_ORDERS = ['dmy', 'mdy', 'ymd'] as const
export const CLOCKS = ['24h', '12h'] as const

export type DateOrder = 'auto' | (typeof DATE_ORDERS)[number]
export type Clock = 'auto' | (typeof CLOCKS)[number]

export interface DisplayFormats {
  dateOrder: DateOrder
  clock: Clock
  /** `auto` or an IANA zone name. */
  timeZone: string
}

export const defaultDisplayFormats: DisplayFormats = {
  dateOrder: 'auto',
  clock: 'auto',
  timeZone: 'auto',
}

export const displayFormats = reactive<DisplayFormats>({ ...defaultDisplayFormats })

export const browserTimeZone = (): string => Intl.DateTimeFormat().resolvedOptions().timeZone

/** Every zone this browser can format in, the list the user picks from. */
export const supportedTimeZones = (): string[] => Intl.supportedValuesOf('timeZone')

const isTimeZone = (zone: string): boolean => {
  try {
    new Intl.DateTimeFormat(undefined, { timeZone: zone })
    return true
  } catch {
    return false
  }
}

const isOneOf = <T extends string>(values: readonly T[], value: unknown): value is T =>
  values.some((entry) => entry === value)

/**
 * Applies stored choices, dropping anything this browser cannot honour: a value
 * from an older client, or a zone name Intl does not know, falls back to `auto`
 * instead of throwing on every date in the app.
 */
export function applyDisplayFormats(next: Partial<DisplayFormats>): void {
  displayFormats.dateOrder = isOneOf(DATE_ORDERS, next.dateOrder) ? next.dateOrder : 'auto'
  displayFormats.clock = isOneOf(CLOCKS, next.clock) ? next.clock : 'auto'
  displayFormats.timeZone =
    next.timeZone && next.timeZone !== 'auto' && isTimeZone(next.timeZone) ? next.timeZone : 'auto'
}
