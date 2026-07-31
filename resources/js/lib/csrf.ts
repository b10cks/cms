const XSRF_COOKIE_NAME = 'XSRF-TOKEN'

/** `[name, value]` per cookie; the name is everything before the *first* `=`. */
const parseCookies = (): Array<[string, string]> => {
  const cookies = document.cookie ? document.cookie.split('; ') : []
  return cookies.map((cookie) => {
    const separator = cookie.indexOf('=')
    return separator === -1
      ? ([cookie, ''] as [string, string])
      : ([cookie.slice(0, separator), cookie.slice(separator + 1)] as [string, string])
  })
}

/** Reads the token without logging — for polling and presence checks. */
const readXsrfToken = (): string | null => {
  if (typeof document === 'undefined') return null

  const entry = parseCookies().find(([name]) => name === XSRF_COOKIE_NAME)
  if (!entry || !entry[1]) return null

  try {
    return decodeURIComponent(entry[1])
  } catch {
    return entry[1]
  }
}

export const getXsrfToken = (): string | null => {
  if (typeof document === 'undefined') return null

  const entry = parseCookies().find(([name]) => name === XSRF_COOKIE_NAME)

  if (!entry) {
    // Names only — cookie *values* are credentials and this runs in production.
    console.warn(
      '[CSRF] XSRF-TOKEN cookie not found. Available cookies:',
      parseCookies().map(([name]) => name)
    )
    return null
  }

  if (!entry[1]) {
    console.warn('[CSRF] XSRF-TOKEN cookie has no value')
    return null
  }

  try {
    return decodeURIComponent(entry[1])
  } catch (e) {
    console.warn('[CSRF] Failed to decode XSRF-TOKEN:', e)
    return entry[1]
  }
}

export const getXsrfHeaders = (): Record<string, string> => {
  const token = getXsrfToken()
  if (!token) {
    console.warn('[CSRF] No XSRF token available for request headers')
    return {}
  }
  return { 'X-XSRF-TOKEN': token }
}

export const hasXsrfToken = (): boolean => {
  return getXsrfToken() !== null
}

export async function fetchCsrfCookie(): Promise<boolean> {
  try {
    const response = await fetch('/auth/v1/csrf-cookie', {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return response.ok
  } catch {
    return false
  }
}

const CSRF_COOKIE_POLL_ATTEMPTS = 10
const CSRF_COOKIE_POLL_INTERVAL = 20

export async function ensureCsrfToken(): Promise<boolean> {
  if (hasXsrfToken()) return true

  const success = await fetchCsrfCookie()
  if (!success) return false

  // The cookie is set by the response itself, but the browser may need a tick
  // to commit it. Poll briefly instead of betting on one fixed wait.
  for (let attempt = 0; attempt < CSRF_COOKIE_POLL_ATTEMPTS; attempt++) {
    if (readXsrfToken() !== null) return true
    await new Promise((resolve) => setTimeout(resolve, CSRF_COOKIE_POLL_INTERVAL))
  }

  return hasXsrfToken()
}
