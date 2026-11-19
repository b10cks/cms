import { CalendarDate, Time } from '@internationalized/date'

export type DateFieldFormat = 'date' | 'time' | 'datetime-local'

/** The wall-clock parts of a stored date field value. Never converted through a time zone. */
export interface DateFieldParts {
  date?: CalendarDate
  time?: Time
}

const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})/
const TIME_PATTERN = /(?:^|[T ])(\d{2}):(\d{2})(?::(\d{2}))?/

const pad = (value: number) => String(value).padStart(2, '0')

/**
 * Reads `YYYY-MM-DD`, `HH:mm` or `YYYY-MM-DDTHH:mm` (optional seconds kept, offsets ignored),
 * whatever the field's format, so a date-only value in a datetime field keeps its date.
 */
export function parseDateFieldValue(value: string | null | undefined): DateFieldParts {
  if (!value) return {}

  const date = DATE_PATTERN.exec(value)
  const time = TIME_PATTERN.exec(value)

  return {
    date: date ? new CalendarDate(Number(date[1]), Number(date[2]), Number(date[3])) : undefined,
    time: time ? new Time(Number(time[1]), Number(time[2]), Number(time[3] ?? 0)) : undefined,
  }
}

/** Writes the parts in the field's stored shape, or `null` while a datetime has only one half. */
export function serializeDateFieldValue(
  format: DateFieldFormat,
  { date, time }: DateFieldParts
): string | null {
  const datePart =
    date && `${String(date.year).padStart(4, '0')}-${pad(date.month)}-${pad(date.day)}`
  const timePart =
    time && `${pad(time.hour)}:${pad(time.minute)}${time.second ? `:${pad(time.second)}` : ''}`

  if (format === 'date') return datePart ?? ''
  if (format === 'time') return timePart ?? ''
  if (datePart && timePart) return `${datePart}T${timePart}`

  return datePart || timePart ? null : ''
}

/**
 * The time a bound allows on the picked date: a time bound always, a datetime bound only
 * on its own day, a date-only bound never.
 */
export function boundTimeOn(
  format: DateFieldFormat,
  date: CalendarDate | undefined,
  bound: DateFieldParts
): Time | undefined {
  if (!bound.time) return undefined
  if (format === 'time') return bound.time
  return bound.date && date?.compare(bound.date) === 0 ? bound.time : undefined
}

/**
 * Milliseconds for comparing a value with its bounds, read like the backend's `strtotime`:
 * local wall-clock time, midnight for a date-only value, today for a time-only value.
 * Other strings fall back to `Date.parse`; `NaN` means unreadable.
 */
export function dateFieldTimestamp(value: string): number {
  const { date, time } = parseDateFieldValue(value)
  if (!date && !time) return Date.parse(value)

  const today = new Date()
  return new Date(
    date?.year ?? today.getFullYear(),
    (date?.month ?? today.getMonth() + 1) - 1,
    date?.day ?? today.getDate(),
    time?.hour ?? 0,
    time?.minute ?? 0,
    time?.second ?? 0
  ).getTime()
}
