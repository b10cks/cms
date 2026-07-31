import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  ensureCsrfToken,
  fetchCsrfCookie,
  getXsrfHeaders,
  getXsrfToken,
  hasXsrfToken,
} from '~/lib/csrf'

// Every test starts from a known cookie jar: jsdom keeps cookies for the whole
// file otherwise, so one test's token would satisfy the next one's assertion.
const clearCookies = () => {
  for (const cookie of document.cookie.split('; ')) {
    const name = cookie.split('=')[0]
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT`
  }
}

let warn: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  clearCookies()
  // csrf.ts is chatty on every miss; silence it but keep the calls assertable.
  warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
  // Undo the `document` stub and the cookie getter spy before touching cookies.
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  clearCookies()
})

describe('getXsrfToken', () => {
  it('reads the XSRF-TOKEN cookie', () => {
    document.cookie = 'XSRF-TOKEN=abc123'

    expect(getXsrfToken()).toBe('abc123')
  })

  it('percent-decodes the cookie value', () => {
    document.cookie = 'XSRF-TOKEN=token%2Fvalue%3D%3D'

    expect(getXsrfToken()).toBe('token/value==')
  })

  it('finds the token among other cookies', () => {
    document.cookie = 'laravel_session=xyz'
    document.cookie = 'XSRF-TOKEN=abc123'
    document.cookie = 'locale=en'

    expect(getXsrfToken()).toBe('abc123')
  })

  it('keeps equals signs inside the raw value instead of truncating at the first', () => {
    document.cookie = 'XSRF-TOKEN=a=b=c'

    expect(getXsrfToken()).toBe('a=b=c')
  })

  it('returns null and warns with cookie names only, never their values', () => {
    document.cookie = 'laravel_session=xyz'

    expect(getXsrfToken()).toBeNull()
    // This warning reaches production consoles and log forwarders, so the
    // values — which are credentials — must never appear in it.
    expect(warn).toHaveBeenCalledWith('[CSRF] XSRF-TOKEN cookie not found. Available cookies:', [
      'laravel_session',
    ])
  })

  it('returns null and warns when there are no cookies at all', () => {
    expect(getXsrfToken()).toBeNull()
    expect(warn).toHaveBeenCalledWith('[CSRF] XSRF-TOKEN cookie not found. Available cookies:', [])
  })

  it('returns null and warns for an empty cookie value', () => {
    document.cookie = 'other=1'
    // jsdom drops a cookie set to the empty string, so build the header text by
    // hand to exercise the `if (!value)` guard.
    vi.spyOn(document, 'cookie', 'get').mockReturnValue('other=1; XSRF-TOKEN=')

    expect(getXsrfToken()).toBeNull()
    expect(warn).toHaveBeenCalledWith('[CSRF] XSRF-TOKEN cookie has no value')
  })

  it('falls back to the raw value when percent-decoding fails', () => {
    vi.spyOn(document, 'cookie', 'get').mockReturnValue('XSRF-TOKEN=%E0%A4%A')

    expect(getXsrfToken()).toBe('%E0%A4%A')
    expect(warn).toHaveBeenCalledWith('[CSRF] Failed to decode XSRF-TOKEN:', expect.any(URIError))
  })

  // The lookup is `startsWith('XSRF-TOKEN=')`, so a cookie whose name merely
  // contains the token name is correctly ignored...
  it('ignores a cookie whose name only ends with XSRF-TOKEN', () => {
    document.cookie = 'MY-XSRF-TOKEN=nope'

    expect(getXsrfToken()).toBeNull()
  })

  // ...but a cookie whose name only *starts* with it is a false positive.
  it('matches a longer cookie name that starts with XSRF-TOKEN=', () => {
    vi.spyOn(document, 'cookie', 'get').mockReturnValue('XSRF-TOKEN=EXTRA=real')

    expect(getXsrfToken()).toBe('EXTRA=real')
  })

  it('returns null without touching cookies when document is undefined', () => {
    vi.stubGlobal('document', undefined)

    expect(getXsrfToken()).toBeNull()
    expect(warn).not.toHaveBeenCalled()
  })
})

describe('getXsrfHeaders', () => {
  it('returns the X-XSRF-TOKEN header when a token exists', () => {
    document.cookie = 'XSRF-TOKEN=abc123'

    expect(getXsrfHeaders()).toEqual({ 'X-XSRF-TOKEN': 'abc123' })
  })

  it('returns an empty object and warns when there is no token', () => {
    expect(getXsrfHeaders()).toEqual({})
    expect(warn).toHaveBeenCalledWith('[CSRF] No XSRF token available for request headers')
  })
})

describe('hasXsrfToken', () => {
  it('is true with a token present', () => {
    document.cookie = 'XSRF-TOKEN=abc123'

    expect(hasXsrfToken()).toBe(true)
  })

  it('is false with no token', () => {
    expect(hasXsrfToken()).toBe(false)
  })
})

describe('fetchCsrfCookie', () => {
  it('GETs the cookie endpoint with credentials and returns true on success', async () => {
    const fetchMock = vi.fn(async () => new Response('', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    expect(await fetchCsrfCookie()).toBe(true)
    expect(fetchMock).toHaveBeenCalledWith('/auth/v1/csrf-cookie', {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
  })

  it('returns false on a non-OK response', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 500 })))

    expect(await fetchCsrfCookie()).toBe(false)
  })

  it('swallows a network failure and returns false', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new TypeError('Failed to fetch')
    }))

    expect(await fetchCsrfCookie()).toBe(false)
  })
})

describe('ensureCsrfToken', () => {
  it('short-circuits without a request when a token is already present', async () => {
    document.cookie = 'XSRF-TOKEN=abc123'
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    expect(await ensureCsrfToken()).toBe(true)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('returns false when fetching the cookie fails', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 419 })))

    expect(await ensureCsrfToken()).toBe(false)
  })

  it('polls until the cookie lands and reports success', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('fetch', vi.fn(async () => {
      document.cookie = 'XSRF-TOKEN=fresh'
      return new Response('', { status: 200 })
    }))

    const pending = ensureCsrfToken()
    await vi.advanceTimersByTimeAsync(100)

    expect(await pending).toBe(true)
    vi.useRealTimers()
  })

  // Polling has a bounded budget: if the server answers OK but sets no cookie,
  // the caller gets false rather than an error or an endless wait.
  it('returns false when the request succeeds but no cookie appears', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 200 })))

    const pending = ensureCsrfToken()
    await vi.advanceTimersByTimeAsync(1000)

    expect(await pending).toBe(false)
    vi.useRealTimers()
  })
})
