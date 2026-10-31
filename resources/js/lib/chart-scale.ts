/** A value axis that may run below zero, with zero always on a tick. */
export interface DivergingScale {
  top: number
  bottom: number
  /** Tick values from the top down. */
  ticks: number[]
}

/** The smallest of 1, 2, 2.5 and 5 times a power of ten at or above `value`. */
const niceStep = (value: number) => {
  const magnitude = 10 ** Math.floor(Math.log10(value))
  return ([1, 2, 2.5, 5, 10].find((step) => step * magnitude >= value) ?? 10) * magnitude
}

/**
 * Scale for stacked bars with positive stacks above zero and negative ones below: about four
 * whole steps across the span, the top at least one step above zero so an all-negative chart
 * still shows the zero line in place.
 */
export function divergingScale(high: number, low: number): DivergingScale {
  const span = Math.max(high, 0) - Math.min(low, 0)
  const step = span > 0 ? Math.max(1, niceStep(span / 4)) : 1
  const top = Math.max(step, Math.ceil(high / step) * step)
  const bottom = low < 0 ? Math.floor(low / step) * step : 0
  const ticks: number[] = []
  for (let tick = top; tick >= bottom - step / 2; tick -= step)
    ticks.push(Math.round(tick * 100) / 100)
  return { top, bottom, ticks }
}

/** Position of `value` from the bottom of the scale, in percent. */
export const scalePosition = (scale: DivergingScale, value: number) =>
  ((Math.min(Math.max(value, scale.bottom), scale.top) - scale.bottom) /
    (scale.top - scale.bottom)) *
  100
