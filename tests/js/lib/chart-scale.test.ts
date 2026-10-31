import { describe, expect, it } from 'vitest'

import { divergingScale, scalePosition } from '~/lib/chart-scale'

describe('divergingScale', () => {
  it('puts zero on a tick when bars run both ways', () => {
    const scale = divergingScale(5800, -1900)
    expect(scale).toEqual({ top: 6000, bottom: -2000, ticks: [6000, 4000, 2000, 0, -2000] })
  })

  it('keeps a step above zero when every value is negative', () => {
    expect(divergingScale(0, -300)).toMatchObject({ top: 100, bottom: -300 })
  })

  it('falls back to a unit scale without data', () => {
    expect(divergingScale(0, 0)).toEqual({ top: 1, bottom: 0, ticks: [1, 0] })
  })

  it('positions values from the bottom in percent', () => {
    const scale = divergingScale(6000, -2000)
    expect(scalePosition(scale, 0)).toBe(25)
    expect(scalePosition(scale, 9000)).toBe(100)
  })
})
