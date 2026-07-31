import { afterEach, describe, expect, it, vi } from 'vitest'

import { isClient, isServer } from '~/lib/env'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.resetModules()
})

describe('isClient / isServer', () => {
  it('reports a client environment under jsdom', () => {
    expect(isClient).toBe(true)
    expect(isServer).toBe(false)
  })

  it('keeps the two flags exact complements', () => {
    expect(isServer).toBe(!isClient)
  })

  it('flips to a server environment when `window` is absent at import time', async () => {
    // The flags are computed once at module load, so the only way to reach the
    // server branch is to re-import with `typeof window === 'undefined'`.
    vi.stubGlobal('window', undefined)
    vi.resetModules()

    const env = await import('~/lib/env')

    expect(env.isClient).toBe(false)
    expect(env.isServer).toBe(true)
  })
})
