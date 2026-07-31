import { afterEach, describe, expect, it, vi } from 'vitest'

import { runtimeConfig } from '~/lib/runtime-config'

type AppConfig = NonNullable<Window['__APP_CONFIG__']>

// Mirrors tests/js/setup.ts, which pins the payload before any module loads.
const PINNED: AppConfig = {
  version: 'test',
  apiBaseUrl: 'https://api.b10cks.test',
  docsUrl: 'https://docs.b10cks.test',
  features: { billing: true, ai: true, realtime: false, registration: true },
  ilum: { baseURL: '/ilum' },
}

// The module snapshots window.__APP_CONFIG__ once at load, so every permutation
// needs a fresh registry.
const loadWith = async (config: AppConfig | undefined) => {
  window.__APP_CONFIG__ = config
  vi.resetModules()

  return (await import('~/lib/runtime-config')).runtimeConfig.public
}

afterEach(async () => {
  window.__APP_CONFIG__ = PINNED
  vi.resetModules()
})

describe('runtimeConfig with the pinned test payload', () => {
  it('exposes everything under a `public` namespace', () => {
    expect(Object.keys(runtimeConfig)).toEqual(['public'])
  })

  it('reads the values the setup file pinned', () => {
    expect(runtimeConfig.public.apiBaseUrl).toBe('https://api.b10cks.test')
    expect(runtimeConfig.public.appVersion).toBe('test')
    expect(runtimeConfig.public.docsUrl).toBe('https://docs.b10cks.test')
    expect(runtimeConfig.public.ilum.baseURL).toBe('/ilum')
  })

  it('honours the explicit feature flags, including a false one', () => {
    expect(runtimeConfig.public.features).toEqual({
      billing: true,
      ai: true,
      realtime: false,
      registration: true,
    })
  })
})

describe('defaults with no payload at all', () => {
  it('falls back to empty strings, SaaS URLs and SaaS feature flags', async () => {
    const config = await loadWith(undefined)

    expect(config).toMatchObject({
      apiBaseUrl: '',
      appVersion: '',
      docsUrl: 'https://www.b10cks.com/docs',
      communityUrl: 'https://discord.gg/mdcDktFFcp',
      socialAuth: { providers: [] },
      sidebarMenu: [],
      posthog: { key: undefined, host: undefined },
      features: { billing: true, ai: true, realtime: false, registration: true },
      echo: null,
    })
  })

  // Like every other URL, ilum has a default so images resolve against the
  // app's own transform route instead of the bare current origin.
  it('defaults ilum.baseURL to the local transform route', async () => {
    expect((await loadWith(undefined)).ilum.baseURL).toBe('/ilum')
  })

  it('behaves identically for an empty payload object', async () => {
    expect(await loadWith({})).toEqual(await loadWith(undefined))
  })
})

describe('string fallbacks', () => {
  it('treats an empty docsUrl as absent because the guard is `||`', async () => {
    expect((await loadWith({ docsUrl: '' })).docsUrl).toBe('https://www.b10cks.com/docs')
  })

  it('treats an empty apiBaseUrl and version as absent', async () => {
    const config = await loadWith({ apiBaseUrl: '', version: '' })

    expect(config.apiBaseUrl).toBe('')
    expect(config.appVersion).toBe('')
  })

  it('passes an explicit communityUrl through', async () => {
    expect((await loadWith({ communityUrl: 'https://chat.test' })).communityUrl).toBe(
      'https://chat.test'
    )
  })
})

describe('features', () => {
  it('uses `??`, so an explicit false is respected', async () => {
    expect(
      (await loadWith({ features: { billing: false, ai: false, registration: false } })).features
    ).toMatchObject({ billing: false, ai: false, registration: false })
  })

  it('defaults each missing flag independently', async () => {
    expect((await loadWith({ features: { billing: false } })).features).toEqual({
      billing: false,
      ai: true,
      realtime: false,
      registration: true,
    })
  })

  it('derives realtime from a usable echo block when unset', async () => {
    expect((await loadWith({ echo: { key: 'k' } })).features.realtime).toBe(true)
    expect((await loadWith({ echo: null })).features.realtime).toBe(false)
    expect((await loadWith({ echo: {} })).features.realtime).toBe(false)
  })

  it('lets an explicit realtime flag override the echo block', async () => {
    expect(
      (await loadWith({ echo: { key: 'k' }, features: { realtime: false } })).features.realtime
    ).toBe(false)
  })
})

describe('echo', () => {
  it('is null without an echo block', async () => {
    expect((await loadWith({})).echo).toBeNull()
  })

  it('is null for an explicitly null echo block', async () => {
    expect((await loadWith({ echo: null })).echo).toBeNull()
  })

  it('defaults the broadcaster to reverb and forceTLS to true', async () => {
    expect((await loadWith({ echo: { key: 'k', wsHost: 'ws.test' } })).echo).toEqual({
      broadcaster: 'reverb',
      key: 'k',
      wsHost: 'ws.test',
      wsPort: undefined,
      wssPort: undefined,
      forceTLS: true,
      enabledTransports: ['ws', 'wss'],
    })
  })

  it('respects forceTLS: false', async () => {
    expect((await loadWith({ echo: { key: 'k', forceTLS: false } })).echo?.forceTLS).toBe(false)
  })

  it('carries the ports through as the strings they arrive as', async () => {
    expect(
      (await loadWith({ echo: { key: 'k', wsPort: '8080', wssPort: '443' } })).echo
    ).toMatchObject({
      wsPort: '8080',
      wssPort: '443',
    })
  })

  // A ws-only self-hosted deployment must be able to stop advertising wss.
  it('honours a payload-supplied enabledTransports', async () => {
    expect(
      (await loadWith({ echo: { key: 'k', enabledTransports: ['ws'] } })).echo?.enabledTransports
    ).toEqual(['ws'])
  })

  // Without a key Echo cannot connect, so an empty block is as good as absent —
  // otherwise it retry-loops against a websocket that isn't there.
  it('is null for an echo block without a key', async () => {
    expect((await loadWith({ echo: {} })).echo).toBeNull()
    expect((await loadWith({ echo: {} })).features.realtime).toBe(false)
  })
})

describe('pass-through collections', () => {
  it('keeps the social auth providers as given', async () => {
    const providers = [{ key: 'github', url: '/auth/github', linkUrl: '/link/github' }]

    expect((await loadWith({ socialAuth: { providers } })).socialAuth.providers).toEqual(providers)
  })

  it('defaults providers to an empty array when socialAuth has no providers', async () => {
    expect((await loadWith({ socialAuth: {} })).socialAuth.providers).toEqual([])
  })

  it('keeps the sidebar menu as given', async () => {
    const sidebarMenu = [{ label: 'Docs', icon: 'lucide:book', href: '/docs' }]

    expect((await loadWith({ sidebarMenu })).sidebarMenu).toEqual(sidebarMenu)
  })

  it('replaces an empty sidebar menu array with a new empty one — `||` on `[]` is a no-op', async () => {
    expect((await loadWith({ sidebarMenu: [] })).sidebarMenu).toEqual([])
  })

  it('exposes the posthog keys without defaults', async () => {
    expect((await loadWith({ posthog: { key: 'phc_1', host: 'https://ph.test' } })).posthog).toEqual(
      { key: 'phc_1', host: 'https://ph.test' }
    )
  })
})
