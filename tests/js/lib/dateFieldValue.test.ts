import { CalendarDate, Time } from '@internationalized/date'
import { describe, expect, it } from 'vitest'

import { parseDateFieldValue, serializeDateFieldValue } from '~/lib/dateFieldValue'

describe('parseDateFieldValue', () => {
  it('reads each stored shape as wall-clock parts', () => {
    expect(parseDateFieldValue('2026-11-27')).toEqual({
      date: new CalendarDate(2026, 11, 27),
      time: undefined,
    })
    expect(parseDateFieldValue('19:00')).toEqual({ date: undefined, time: new Time(19, 0) })
    expect(parseDateFieldValue('2026-11-27T19:00')).toEqual({
      date: new CalendarDate(2026, 11, 27),
      time: new Time(19, 0),
    })
  })

  it('ignores seconds and offsets instead of shifting the time', () => {
    expect(parseDateFieldValue('2026-11-27T23:30:15+05:00')).toEqual({
      date: new CalendarDate(2026, 11, 27),
      time: new Time(23, 30),
    })
  })

  it('returns no parts for an empty or unreadable value', () => {
    expect(parseDateFieldValue(null)).toEqual({})
    expect(parseDateFieldValue('soon')).toEqual({ date: undefined, time: undefined })
  })
})

describe('serializeDateFieldValue', () => {
  const date = new CalendarDate(2026, 3, 7)
  const time = new Time(9, 5)

  it('writes the stored shape of each format', () => {
    expect(serializeDateFieldValue('date', { date, time })).toBe('2026-03-07')
    expect(serializeDateFieldValue('time', { date, time })).toBe('09:05')
    expect(serializeDateFieldValue('datetime-local', { date, time })).toBe('2026-03-07T09:05')
  })

  it('writes an empty string when cleared', () => {
    expect(serializeDateFieldValue('date', {})).toBe('')
    expect(serializeDateFieldValue('datetime-local', {})).toBe('')
  })

  it('holds back a datetime with only one half', () => {
    expect(serializeDateFieldValue('datetime-local', { date })).toBeNull()
    expect(serializeDateFieldValue('datetime-local', { time })).toBeNull()
  })
})
