import { afterEach, describe, expect, it, vi } from 'vitest'

import { reloadOnStaleChunks } from '~/lib/staleChunks'

describe('reloadOnStaleChunks', () => {
  afterEach(() => {
    vi.useRealTimers()
    sessionStorage.clear()
  })

  it('reloads once per cooldown so a broken chunk cannot loop', () => {
    vi.useFakeTimers()
    const reload = vi.fn()
    reloadOnStaleChunks(reload)

    window.dispatchEvent(new Event('vite:preloadError'))
    window.dispatchEvent(new Event('vite:preloadError'))
    expect(reload).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(10_000)
    window.dispatchEvent(new Event('vite:preloadError'))
    expect(reload).toHaveBeenCalledTimes(2)
  })
})
