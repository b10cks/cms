import { CalendarDate, Time } from '@internationalized/date'

export type DateFieldFormat = 'date' | 'time' | 'datetime-local'

/** The wall-clock parts of a stored date field value. Never converted through a time zone. */
export interface DateFieldParts {
  date?: CalendarDate
  time?: Time
}

const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})/
const TIME_PATTERN = /(?:^|[T ])(\d{2}):(\d{2})/

const pad = (value: number) => String(value).padStart(2, '0')

/**
 * Reads `YYYY-MM-DD`, `HH:mm` or `YYYY-MM-DDTHH:mm` (seconds and offsets are ignored),
 * whatever the field's format, so a date-only value in a datetime field keeps its date.
 */
export function parseDateFieldValue(value: string | null | undefined): DateFieldParts {
  if (!value) return {}

  const date = DATE_PATTERN.exec(value)
  const time = TIME_PATTERN.exec(value)

  return {
    date: date ? new CalendarDate(Number(date[1]), Number(date[2]), Number(date[3])) : undefined,
    time: time ? new Time(Number(time[1]), Number(time[2])) : undefined,
  }
}

/** Writes the parts in the field's stored shape, or `null` while a datetime has only one half. */
export function serializeDateFieldValue(
  format: DateFieldFormat,
  { date, time }: DateFieldParts
): string | null {
  const datePart =
    date && `${String(date.year).padStart(4, '0')}-${pad(date.month)}-${pad(date.day)}`
  const timePart = time && `${pad(time.hour)}:${pad(time.minute)}`

  if (format === 'date') return datePart ?? ''
  if (format === 'time') return timePart ?? ''
  if (datePart && timePart) return `${datePart}T${timePart}`

  return datePart || timePart ? null : ''
}
