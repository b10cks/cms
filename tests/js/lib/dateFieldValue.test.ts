import { CalendarDate, Time } from '@internationalized/date'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  boundTimeOn,
  dateFieldTimestamp,
  parseDateFieldValue,
  serializeDateFieldValue,
} from '~/lib/dateFieldValue'

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

  it('keeps seconds and ignores offsets instead of shifting the time', () => {
    expect(parseDateFieldValue('2026-11-27T23:30:15+05:00')).toEqual({
      date: new CalendarDate(2026, 11, 27),
      time: new Time(23, 30, 15),
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

  it('keeps seconds a stored value already had', () => {
    expect(serializeDateFieldValue('time', { time: new Time(19, 0, 30) })).toBe('19:00:30')
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

describe('boundTimeOn', () => {
  const day = new CalendarDate(2026, 11, 27)
  const bound = { date: day, time: new Time(18, 0) }

  it('limits a time field always', () => {
    expect(boundTimeOn('time', undefined, { time: new Time(9, 0) })).toEqual(new Time(9, 0))
  })

  it('limits a datetime only on the bound day', () => {
    expect(boundTimeOn('datetime-local', day, bound)).toEqual(new Time(18, 0))
    expect(boundTimeOn('datetime-local', day.add({ days: 1 }), bound)).toBeUndefined()
    expect(boundTimeOn('datetime-local', undefined, bound)).toBeUndefined()
  })

  it('never limits the time from a date-only bound', () => {
    expect(boundTimeOn('datetime-local', day, { date: day })).toBeUndefined()
    expect(boundTimeOn('time', undefined, { date: day })).toBeUndefined()
  })
})

describe('dateFieldTimestamp', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 10, 19, 15, 0))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('reads values as local wall-clock time, a date-only value at midnight', () => {
    expect(dateFieldTimestamp('2026-11-27T19:00')).toBe(new Date(2026, 10, 27, 19, 0).getTime())
    expect(dateFieldTimestamp('2026-11-27')).toBe(new Date(2026, 10, 27).getTime())
  })

  it('reads a time-only value on the current day', () => {
    expect(dateFieldTimestamp('19:00')).toBe(new Date(2026, 10, 19, 19, 0).getTime())
  })

  it('is NaN for an unreadable value', () => {
    expect(dateFieldTimestamp('soon')).toBeNaN()
  })
})
