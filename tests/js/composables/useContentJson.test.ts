import { afterEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { ContentResource } from '~/types/contents'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

vi.mock('~/api', () => ({
  api: {
    spaces: { get: vi.fn() },
    forSpace: () => ({ tokens: { index: vi.fn() } }),
  },
}))

const { useContentJson } = await import('~/composables/useContentJson')

const SPACE = 'space-1'
const UPDATED_AT = '2026-07-29T10:00:00.000Z'

type JsonContent = Pick<ContentResource, 'full_slug' | 'language_iso'>

const content = (extra: Partial<JsonContent> = {}) =>
  ({ full_slug: '/home', language_iso: 'en', ...extra }) as JsonContent

const seeds = (
  options: { updatedAt?: string | null; tokens?: Array<{ token: string }> } = {}
): Array<[readonly unknown[], unknown]> => [
  [
    queryKeys.spaces.detail(SPACE),
    { id: SPACE, updated_at: 'updatedAt' in options ? options.updatedAt : UPDATED_AT },
  ],
  [queryKeys.tokens(SPACE).list({}), options.tokens ?? [{ token: 'tok-1' }]],
]

let harness: Harness<ReturnType<typeof useContentJson>> | undefined

const setup = (
  activeContent: unknown = content(),
  seed: Array<[readonly unknown[], unknown]> = seeds()
) => {
  harness = withSetup(() => useContentJson(SPACE, activeContent as JsonContent), { seed })
  return harness.result
}

const params = (url: string) => new URL(url).searchParams

afterEach(() => {
  harness?.unmount()
  harness = undefined
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('apiToken', () => {
  it('is the first token of the space', () => {
    expect(setup().apiToken.value).toBe('tok-1')
  })

  it('is null when the space has no tokens', () => {
    expect(setup(content(), seeds({ tokens: [] })).apiToken.value).toBeNull()
  })
})

describe('rv', () => {
  it('is the space update time in milliseconds', () => {
    expect(setup().rv.value).toBe(Date.parse(UPDATED_AT))
  })

  it('falls back to now when the space has never been updated', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-01-02T03:04:05.000Z'))

    expect(setup(content(), seeds({ updatedAt: null })).rv.value).toBe(
      Date.parse('2026-01-02T03:04:05.000Z')
    )
  })
})

describe('buildContentJsonUrl', () => {
  it('builds a delivery URL against the configured API base', () => {
    expect(setup().buildContentJsonUrl('draft')).toBe(
      `https://api.b10cks.test/api/v1/contents/home?vid=draft&rv=${
        Date.parse(UPDATED_AT) / 1000
      }&token=tok-1&language=en`
    )
  })

  it('passes the requested version through verbatim', () => {
    const { buildContentJsonUrl } = setup()

    expect(params(buildContentJsonUrl('published') as string).get('vid')).toBe('published')
    expect(params(buildContentJsonUrl('v-123') as string).get('vid')).toBe('v-123')
  })

  it('sends rv in seconds', () => {
    expect(params(setup().buildContentJsonUrl('draft') as string).get('rv')).toBe(
      String(Date.parse(UPDATED_AT) / 1000)
    )
  })

  it('truncates a sub-second update time to whole seconds', () => {
    // A fractional cache-buster would split the delivery cache per millisecond.
    const url = setup(content(), seeds({ updatedAt: '2026-07-29T10:00:00.123Z' }))
      .buildContentJsonUrl('draft') as string

    expect(params(url).get('rv')).toBe(
      String(Math.floor(Date.parse('2026-07-29T10:00:00.123Z') / 1000))
    )
  })

  it('still builds a URL for a space stamped at the Unix epoch', () => {
    expect(
      setup(content(), seeds({ updatedAt: '1970-01-01T00:00:00.000Z' })).buildContentJsonUrl('draft')
    ).toContain('rv=0')
  })

  it('strips every leading slash from the slug', () => {
    expect(setup(content({ full_slug: '///nested/page' })).buildContentJsonUrl('draft')).toContain(
      '/contents/nested/page?'
    )
  })

  it('encodes the language and token as query parameters', () => {
    const url = setup(content({ language_iso: 'de-AT' }), seeds({ tokens: [{ token: 'a b' }] }))
      .buildContentJsonUrl('draft') as string

    expect(params(url).get('language')).toBe('de-AT')
    expect(params(url).get('token')).toBe('a b')
  })

  it('returns null when the composable was given no content argument', () => {
    harness = withSetup(() => useContentJson(SPACE), { seed: seeds() })

    expect(harness.result.buildContentJsonUrl('draft')).toBeNull()
  })

  it.each([
    ['a null content', null],
    ['a content without a slug', { full_slug: '', language_iso: 'en' }],
    ['a content whose slug is only slashes', { full_slug: '//', language_iso: 'en' }],
    ['a content without a language', { full_slug: '/home', language_iso: '' }],
  ])('returns null for %s', (_label, activeContent) => {
    expect(setup(activeContent).buildContentJsonUrl('draft')).toBeNull()
  })

  it('returns null while the space has no token', () => {
    expect(setup(content(), seeds({ tokens: [] })).buildContentJsonUrl('draft')).toBeNull()
  })

  it('follows a reactive content ref', () => {
    const activeContent = ref<JsonContent>(content())
    harness = withSetup(() => useContentJson(SPACE, activeContent), { seed: seeds() })

    expect(harness.result.buildContentJsonUrl('draft')).toContain('/contents/home?')

    activeContent.value = content({ full_slug: '/about', language_iso: 'de' })

    const url = harness.result.buildContentJsonUrl('draft') as string
    expect(url).toContain('/contents/about?')
    expect(params(url).get('language')).toBe('de')
  })
})

describe('openContentJsonInNewTab', () => {
  it('opens the built URL in a new tab without leaking the referrer', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    setup().openContentJsonInNewTab('draft')

    expect(open).toHaveBeenCalledWith(
      expect.stringContaining('/api/v1/contents/home?'),
      '_blank',
      'noopener,noreferrer'
    )
  })

  it('does nothing when no URL can be built', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    setup(null).openContentJsonInNewTab('draft')

    expect(open).not.toHaveBeenCalled()
  })
})
