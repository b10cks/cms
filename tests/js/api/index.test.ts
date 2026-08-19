import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { API, api } from '~/api'

const CSRF_ENDPOINT = '/auth/v1/csrf-cookie'
const BASE_URL = 'https://api.b10cks.test'

const json = (body: unknown, init: ResponseInit = {}) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'content-type': 'application/json' },
    ...init,
  })

const fetchMock = vi.fn()

const isCsrf = (url: unknown) => String(url).endsWith(CSRF_ENDPOINT)

const urls = () =>
  fetchMock.mock.calls.filter(([url]) => !isCsrf(url)).map(([url]) => String(url))

const lastUrl = () => urls().at(-1)

let sdk: API

beforeEach(() => {
  fetchMock.mockReset()
  fetchMock.mockImplementation(async (url: unknown) =>
    isCsrf(url) ? new Response(null, { status: 204 }) : json({ data: [] })
  )
  vi.stubGlobal('fetch', fetchMock)
  document.cookie = 'XSRF-TOKEN=token'
  vi.spyOn(console, 'warn').mockImplementation(() => {})
  sdk = new API({ baseURL: BASE_URL })
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('construction', () => {
  it('passes the options straight to its client', () => {
    expect(sdk.client.getBaseUrl()).toBe(BASE_URL)
    expect(new API({ authToken: 'token-1' }).client.getAuthHeaders()).toEqual({
      Authorization: 'Bearer token-1',
    })
  })

  it('exports a singleton with an empty base URL', () => {
    // ODDITY: `new API()` takes no options, so window.__APP_CONFIG__.apiBaseUrl is
    // never applied — every app request is same-origin relative. Anything that
    // needs an absolute URL has to read getBaseUrl(), which returns ''.
    expect(api.client.getBaseUrl()).toBe('')
  })

  it('shares one client across every resource', async () => {
    const space = sdk.forSpace('space-1')

    await sdk.spaces.index()
    await space.contents.index()

    // Both hit the same configured host.
    expect(urls()).toEqual([
      `${BASE_URL}/mgmt/v1/spaces`,
      `${BASE_URL}/mgmt/v1/spaces/space-1/contents`,
    ])
  })
})

describe('auth delegation', () => {
  it('forwards the token to the client', () => {
    sdk.setAuthToken('token-2')
    expect(sdk.client.getAuthHeaders()).toEqual({ Authorization: 'Bearer token-2' })

    sdk.setAuthToken(undefined)
    expect(sdk.client.getAuthHeaders()).toEqual({})
  })

  it('forwards the auth handler to the client', async () => {
    const handleUnauthorized = vi.fn(async () => ({ retry: false }))

    sdk.setAuthHandler({ handleUnauthorized })
    fetchMock.mockImplementation(async () => json({ message: 'Unauthenticated.' }, { status: 401 }))

    await sdk.users.getMe().catch(() => {})

    expect(handleUnauthorized).toHaveBeenCalledWith('/mgmt/v1/users/me', expect.any(Object))
  })
})

describe('management resources', () => {
  it.each([
    ['spaces', () => sdk.spaces.index(), '/mgmt/v1/spaces'],
    ['spaceBlueprints', () => sdk.spaceBlueprints.index(), '/mgmt/v1/space-blueprints'],
    ['plans', () => sdk.plans.index(), '/mgmt/v1/plans'],
    ['teams', () => sdk.teams.index(), '/mgmt/v1/teams'],
    ['users', () => sdk.users.getMe(), '/mgmt/v1/users/me'],
    ['invites', () => sdk.invites.index(), '/mgmt/v1/users/me/invites'],
    ['notifications', () => sdk.notifications.index(), '/mgmt/v1/users/me/notifications'],
    [
      'personalAccessTokens',
      () => sdk.personalAccessTokens.index(),
      '/mgmt/v1/users/me/tokens',
    ],
    ['provider', () => sdk.provider.index(), '/mgmt/v1/provider/notes'],
  ] as const)('%s resolves to %s', async (_name, call, path) => {
    await call()

    expect(lastUrl()).toBe(`${BASE_URL}${path}`)
  })

  it('exposes the same instance on every property read', () => {
    expect(sdk.spaces).toBe(sdk.spaces)
    expect(sdk.users).toBe(sdk.users)
    expect(sdk.plans).toBe(sdk.plans)
    expect(sdk.ai).toBe(sdk.ai)
    expect(sdk.twoFactor).toBe(sdk.twoFactor)
    expect(sdk.authorization).toBe(sdk.authorization)
    expect(sdk.notifications).toBe(sdk.notifications)
    expect(sdk.invites).toBe(sdk.invites)
    expect(sdk.teams).toBe(sdk.teams)
    expect(sdk.provider).toBe(sdk.provider)
    expect(sdk.spaceBlueprints).toBe(sdk.spaceBlueprints)
    expect(sdk.personalAccessTokens).toBe(sdk.personalAccessTokens)
  })

  it('exposes only the space-less part of Ai on the top-level accessor', async () => {
    // The instance has no space id, so anything that interpolates one would
    // request /spaces/undefined/... — the type only admits getStreamUrl.
    expect(sdk.ai.getStreamUrl()).toBe(`${BASE_URL}/mgmt/v1/ai/content-interaction/stream`)
    expect(Object.keys(sdk.ai)).not.toContain('getAiConfigs')
    // @ts-expect-error space-scoped calls are reachable only via forSpace(id).ai
    expect(sdk.ai.getAiConfigs).toBeDefined()

    await sdk.forSpace('space-1').ai.getAiConfigs()

    expect(lastUrl()).toBe(`${BASE_URL}/mgmt/v1/spaces/space-1/ai-configs`)
  })
})

describe('forSpace', () => {
  it('exposes exactly the known resource set', () => {
    expect(Object.keys(sdk.forSpace('space-1')).sort()).toEqual([
      'ai',
      'assetCollections',
      'assetFolders',
      'assetPackages',
      'assetShares',
      'assetTags',
      'assetVersions',
      'assets',
      'auditLogs',
      'automationActions',
      'automationExecutions',
      'automations',
      'backups',
      'blockFolders',
      'blockTags',
      'blockTemplates',
      'blockVersions',
      'blocks',
      'comments',
      'contentMenu',
      'contentVersions',
      'contents',
      'dataSources',
      'fieldPlugins',
      'icons',
      'invoices',
      'massEdit',
      'members',
      'migrations',
      'people',
      'redirects',
      'releases',
      'subscriptions',
      'tokens',
      'usage',
    ])
  })

  it.each([
    ['assets', (s: string) => sdk.forSpace(s).assets.index(), 'assets'],
    ['assetFolders', (s: string) => sdk.forSpace(s).assetFolders.index(), 'asset-folders'],
    ['assetTags', (s: string) => sdk.forSpace(s).assetTags.index(), 'asset-tags'],
    [
      'assetCollections',
      (s: string) => sdk.forSpace(s).assetCollections.index(),
      'asset-collections',
    ],
    ['assetPackages', (s: string) => sdk.forSpace(s).assetPackages.index(), 'asset-packages'],
    ['assetShares', (s: string) => sdk.forSpace(s).assetShares.index(), 'asset-shares'],
    ['auditLogs', (s: string) => sdk.forSpace(s).auditLogs.index(), 'audit-logs'],
    ['automations', (s: string) => sdk.forSpace(s).automations.index(), 'automations'],
    [
      'automationActions',
      (s: string) => sdk.forSpace(s).automationActions.index(),
      'automation-actions',
    ],
    [
      'automationExecutions',
      (s: string) => sdk.forSpace(s).automationExecutions.index(),
      'automation-executions',
    ],
    ['backups', (s: string) => sdk.forSpace(s).backups.index(), 'backups'],
    ['blocks', (s: string) => sdk.forSpace(s).blocks.index(), 'blocks'],
    ['blockFolders', (s: string) => sdk.forSpace(s).blockFolders.index(), 'block-folders'],
    ['blockTags', (s: string) => sdk.forSpace(s).blockTags.index(), 'block-tags'],
    ['contents', (s: string) => sdk.forSpace(s).contents.index(), 'contents'],
    ['contentMenu', (s: string) => sdk.forSpace(s).contentMenu.get(), 'content-menu'],
    ['dataSources', (s: string) => sdk.forSpace(s).dataSources.index(), 'data-sources'],
    ['fieldPlugins', (s: string) => sdk.forSpace(s).fieldPlugins.index(), 'field-plugins'],
    ['icons', (s: string) => sdk.forSpace(s).icons.index(), 'icons'],
    ['migrations', (s: string) => sdk.forSpace(s).migrations.index(), 'migrations'],
    ['redirects', (s: string) => sdk.forSpace(s).redirects.index(), 'redirects'],
    ['releases', (s: string) => sdk.forSpace(s).releases.index(), 'releases'],
    ['tokens', (s: string) => sdk.forSpace(s).tokens.index(), 'tokens'],
    ['members', (s: string) => sdk.forSpace(s).members.list(), 'members'],
  ] as const)('%s is scoped to /spaces/{id}/%s', async (_name, call, segment) => {
    await call('space-1')

    expect(lastUrl()).toBe(`${BASE_URL}/mgmt/v1/spaces/space-1/${segment}`)
  })

  it('scopes two space ids independently', async () => {
    const one = sdk.forSpace('space-1')
    const two = sdk.forSpace('space-2')

    await one.contents.index()
    await two.contents.index()
    await one.assets.index()

    expect(urls()).toEqual([
      `${BASE_URL}/mgmt/v1/spaces/space-1/contents`,
      `${BASE_URL}/mgmt/v1/spaces/space-2/contents`,
      `${BASE_URL}/mgmt/v1/spaces/space-1/assets`,
    ])
  })

  it('builds a brand new resource set on every call', () => {
    // ODDITY: nothing is memoized, so a computed `spaceAPI` re-creates ~30
    // resource objects on every re-evaluation and no instance identity is stable.
    const first = sdk.forSpace('space-1')
    const second = sdk.forSpace('space-1')

    expect(second).not.toBe(first)
    expect(second.contents).not.toBe(first.contents)
  })

  it.each([
    [
      'assetVersions',
      (s: string) => sdk.forSpace(s).assetVersions('a1').index(),
      'assets/a1/versions',
    ],
    [
      'blockTemplates',
      (s: string) => sdk.forSpace(s).blockTemplates('b1').index(),
      'blocks/b1/templates',
    ],
    [
      'blockVersions',
      (s: string) => sdk.forSpace(s).blockVersions('b1').index(),
      'blocks/b1/versions',
    ],
    [
      'contentVersions',
      (s: string) => sdk.forSpace(s).contentVersions('c1').index(),
      'contents/c1/versions',
    ],
    ['comments', (s: string) => sdk.forSpace(s).comments('c1').index(), 'contents/c1/comments'],
  ] as const)('%s is a factory nesting under its parent id', async (_name, call, segment) => {
    await call('space-1')

    expect(lastUrl()).toBe(`${BASE_URL}/mgmt/v1/spaces/space-1/${segment}`)
  })

  it('scopes the space-scoped Ai resource properly', () => {
    expect(sdk.forSpace('space-1').ai.getStreamUrl()).toBe(
      `${BASE_URL}/mgmt/v1/ai/content-interaction/stream?spaceId=space-1`
    )
  })

  it('routes writes from a space resource through the shared CSRF priming', async () => {
    await sdk.forSpace('space-1').contents.create({ name: 'A', slug: 'a', block_id: 'b1' })

    expect(fetchMock.mock.calls.filter(([url]) => isCsrf(url))).toHaveLength(1)
    expect(lastUrl()).toBe(`${BASE_URL}/mgmt/v1/spaces/space-1/contents`)
  })
})
