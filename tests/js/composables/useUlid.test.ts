import { afterEach, describe, expect, it, vi } from 'vitest'

import { useUlid } from '~/composables/useUlid'

/** Crockford base32, lowercased by the generator. */
const ULID = /^[0-9abcdefghjkmnpqrstvwxyz]{26}$/

const at = (ms: number) => new Date(ms)

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('shape', () => {
  it('is 26 lowercase Crockford base32 characters', () => {
    const ulid = useUlid()()

    expect(ulid).toHaveLength(26)
    expect(ulid).toMatch(ULID)
  })

  it('never emits the excluded letters i, l, o or u', () => {
    const ulid = useUlid()
    const ids = Array.from({ length: 200 }, () => ulid())

    expect(ids.join('')).not.toMatch(/[ilou]/)
  })

  it('splits into a 10-character timestamp and a 16-character random part', () => {
    const ulid = useUlid()

    expect(ulid(at(0)).slice(0, 10)).toBe('0000000000')
    expect(ulid(at(0))).toHaveLength(26)
  })

  it('encodes the supplied timestamp deterministically', () => {
    const first = useUlid()(at(1_700_000_000_000))
    const second = useUlid()(at(1_700_000_000_000))

    expect(first.slice(0, 10)).toBe(second.slice(0, 10))
    // Only the random tail differs between two independent generators.
    expect(first.slice(10)).not.toBe(second.slice(10))
  })

  it('reads the clock when no date is given', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(1_700_000_000_000))

    expect(useUlid()().slice(0, 10)).toBe(useUlid()(at(1_700_000_000_000)).slice(0, 10))
  })
})

describe('ordering', () => {
  it('sorts lexicographically by timestamp', () => {
    const ulid = useUlid()
    const ids = [1_000_000_000_000, 1_500_000_000_000, 1_700_000_000_000, 2_000_000_000_000].map(
      (ms) => ulid(at(ms))
    )

    expect([...ids].sort()).toEqual(ids)
  })

  it('stays monotonic within the same millisecond', () => {
    const ulid = useUlid()
    const ids = Array.from({ length: 50 }, () => ulid(at(1_700_000_000_000)))

    expect([...ids].sort()).toEqual(ids)
    expect(new Set(ids).size).toBe(ids.length)
  })

  it('increments only the random tail within a millisecond', () => {
    const ulid = useUlid()
    const first = ulid(at(1_700_000_000_000))
    const second = ulid(at(1_700_000_000_000))

    expect(second.slice(0, 10)).toBe(first.slice(0, 10))
    expect(second.slice(10, 22)).toBe(first.slice(10, 22))
    expect(second.slice(22)).not.toBe(first.slice(22))
  })

  it('re-randomises when the timestamp changes', () => {
    const ulid = useUlid()
    const first = ulid(at(1_700_000_000_000))
    const second = ulid(at(1_700_000_000_001))

    expect(second.slice(10)).not.toBe(first.slice(10))
    expect(second > first).toBe(true)
  })

  // An out-of-order date must keep counting from the last timestamp, not
  // reset to an earlier one — ULIDs are relied on for ordering.
  it('stays monotonic when handed an earlier timestamp', () => {
    const ulid = useUlid()
    const later = ulid(at(1_700_000_000_001))

    expect(ulid(at(1_700_000_000_000)) > later).toBe(true)
  })
})

describe('uniqueness', () => {
  it('produces no collisions over 2000 ids from one generator', () => {
    const ulid = useUlid()
    const ids = Array.from({ length: 2000 }, () => ulid())

    expect(new Set(ids).size).toBe(2000)
  })

  it('keeps per-generator state — two generators do not share a counter', () => {
    const a = useUlid()
    const b = useUlid()
    const time = at(1_700_000_000_000)

    a(time)
    a(time)

    expect(new Set([a(time), b(time), b(time)]).size).toBe(3)
  })
})

describe('random-tail overflow', () => {
  it('borrows a millisecond when all four random blocks are exhausted', () => {
    // Math.random pinned to its maximum makes every block 0xfffff, so the very
    // next id in the same millisecond has nothing left to increment.
    vi.spyOn(Math, 'random').mockReturnValue(0.999999999)

    const ulid = useUlid()
    const first = ulid(at(1_700_000_000_000))

    expect(first.slice(10)).toBe('z'.repeat(16))

    const second = ulid(at(1_700_000_000_000))

    // A fresh generator, because re-using `ulid` would overflow again and
    // borrow a second millisecond.
    expect(second.slice(0, 10)).toBe(useUlid()(at(1_700_000_000_001)).slice(0, 10))
    expect(second > first).toBe(true)
  })

  it('rolls the lowest block over into the next one', () => {
    const values = [0, 0, 0, 0xfffff / 0x100000]
    vi.spyOn(Math, 'random').mockImplementation(() => values.shift() ?? 0)

    const ulid = useUlid()
    const first = ulid(at(1_700_000_000_000))

    expect(first.slice(10)).toBe('000000000000zzzz')

    // Last block wraps to 0 and the third block takes the carry, which still
    // sorts after the previous tail.
    const second = ulid(at(1_700_000_000_000))

    expect(second.slice(10)).toBe('0000000000010000')
    expect(second > first).toBe(true)
  })
})
