import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, type EffectScope } from 'vue'

import { useHoverPrefetch } from '~/composables/useHoverPrefetch'

const scopes: EffectScope[] = []

const run = <T>(prefetch: (payload: T) => void | Promise<unknown>, delay?: number) => {
  const scope = effectScope()
  scopes.push(scope)
  const api = scope.run(() => useHoverPrefetch(prefetch, delay))

  return { ...(api as ReturnType<typeof useHoverPrefetch<T>>), scope }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop())
  vi.useRealTimers()
})

describe('start', () => {
  it('waits out the delay before prefetching', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch)

    start('a')
    vi.advanceTimersByTime(149)
    expect(prefetch).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(prefetch).toHaveBeenCalledWith('a')
  })

  it('honours a custom delay', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch, 500)

    start('a')
    vi.advanceTimersByTime(499)
    expect(prefetch).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(prefetch).toHaveBeenCalledTimes(1)
  })

  it('fires immediately with a zero delay, but still asynchronously', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch, 0)

    start('a')
    expect(prefetch).not.toHaveBeenCalled()

    vi.advanceTimersByTime(0)
    expect(prefetch).toHaveBeenCalledTimes(1)
  })

  it('schedules independent payloads separately', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch)

    start('a')
    start('b')
    vi.advanceTimersByTime(150)

    expect(prefetch.mock.calls).toEqual([['a'], ['b']])
  })

  it('ignores a repeat start while one is already pending', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch)

    start('a')
    vi.advanceTimersByTime(100)
    start('a')
    vi.advanceTimersByTime(50)

    // The second start must not restart the clock either.
    expect(prefetch).toHaveBeenCalledTimes(1)
  })

  it('dedupes a payload that already succeeded', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch)

    start('a')
    vi.advanceTimersByTime(150)
    start('a')
    vi.advanceTimersByTime(150)

    expect(prefetch).toHaveBeenCalledTimes(1)
  })

  it('treats structurally equal object payloads as different keys', () => {
    // The dedupe map is keyed by identity, so a template building a fresh
    // object per hover prefetches again every time.
    const prefetch = vi.fn()
    const { start } = run<{ id: string }>(prefetch)

    start({ id: 'a' })
    start({ id: 'a' })
    vi.advanceTimersByTime(150)

    expect(prefetch).toHaveBeenCalledTimes(2)
  })

  it('swallows a rejection and allows a retry on re-hover', async () => {
    const prefetch = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(undefined)
    const { start } = run<string>(prefetch)

    start('a')
    await vi.advanceTimersByTimeAsync(150)

    start('a')
    await vi.advanceTimersByTimeAsync(150)

    expect(prefetch).toHaveBeenCalledTimes(2)
  })

  // `prefetch(payload)` is evaluated before Promise.resolve wraps it, so a
  // synchronous throw needs the same treatment as a rejection: contained, and
  // the payload left retryable.
  it('contains a synchronous throw and keeps the payload retryable', () => {
    const prefetch = vi.fn(() => {
      throw new Error('boom')
    })
    const { start } = run<string>(prefetch)

    start('a')
    expect(() => vi.advanceTimersByTime(150)).not.toThrow()

    start('a')
    vi.advanceTimersByTime(150)
    expect(prefetch).toHaveBeenCalledTimes(2)
  })

  it('does not dedupe a payload whose prefetch is still in flight', async () => {
    let resolve = () => {}
    const prefetch = vi.fn(() => new Promise<void>((r) => (resolve = () => r())))
    const { start } = run<string>(prefetch)

    start('a')
    await vi.advanceTimersByTimeAsync(150)

    // `done` is marked before the promise settles, so an in-flight prefetch
    // already blocks a second attempt.
    start('a')
    await vi.advanceTimersByTimeAsync(150)
    resolve()

    expect(prefetch).toHaveBeenCalledTimes(1)
  })
})

describe('cancel', () => {
  it('drops a pending prefetch for one payload', () => {
    const prefetch = vi.fn()
    const { start, cancel } = run<string>(prefetch)

    start('a')
    start('b')
    cancel('a')
    vi.advanceTimersByTime(150)

    expect(prefetch.mock.calls).toEqual([['b']])
  })

  it('drops every pending prefetch when called without a payload', () => {
    const prefetch = vi.fn()
    const { start, cancel } = run<string>(prefetch)

    start('a')
    start('b')
    cancel()
    vi.advanceTimersByTime(150)

    expect(prefetch).not.toHaveBeenCalled()
  })

  it('is a no-op for an unknown or already-fired payload', () => {
    const prefetch = vi.fn()
    const { start, cancel } = run<string>(prefetch)

    start('a')
    vi.advanceTimersByTime(150)

    expect(() => cancel('a')).not.toThrow()
    expect(() => cancel('never-hovered')).not.toThrow()
  })

  it('allows re-scheduling after a cancel', () => {
    const prefetch = vi.fn()
    const { start, cancel } = run<string>(prefetch)

    start('a')
    cancel('a')
    start('a')
    vi.advanceTimersByTime(150)

    expect(prefetch).toHaveBeenCalledTimes(1)
  })

  it('does not un-dedupe a completed payload', () => {
    const prefetch = vi.fn()
    const { start, cancel } = run<string>(prefetch)

    start('a')
    vi.advanceTimersByTime(150)
    cancel('a')
    start('a')
    vi.advanceTimersByTime(150)

    expect(prefetch).toHaveBeenCalledTimes(1)
  })
})

describe('scope disposal', () => {
  it('clears pending timers so nothing fires after teardown', () => {
    const prefetch = vi.fn()
    const { start, scope } = run<string>(prefetch)

    start('a')
    start('b')
    scope.stop()
    vi.advanceTimersByTime(1000)

    expect(prefetch).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('leaves no timer behind after a completed prefetch either', () => {
    const prefetch = vi.fn()
    const { start } = run<string>(prefetch)

    start('a')
    vi.advanceTimersByTime(150)

    expect(vi.getTimerCount()).toBe(0)
  })
})
