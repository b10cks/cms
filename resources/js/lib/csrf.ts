const XSRF_COOKIE_NAME = 'XSRF-TOKEN'

export const getXsrfToken = (): string | null => {
  if (typeof document === 'undefined') return null

  const cookies = document.cookie ? document.cookie.split('; ') : []
  const entry = cookies.find((cookie) => cookie.startsWith(`${XSRF_COOKIE_NAME}=`))

  if (!entry) {
    console.warn('[CSRF] XSRF-TOKEN cookie not found. Available cookies:', cookies)
    return null
  }

  const value = entry.split('=').slice(1).join('=')
  if (!value) {
    console.warn('[CSRF] XSRF-TOKEN cookie has no value')
    return null
  }

  try {
    return decodeURIComponent(value)
  } catch (e) {
    console.warn('[CSRF] Failed to decode XSRF-TOKEN:', e)
    return value
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

export async function ensureCsrfToken(): Promise<boolean> {
  if (hasXsrfToken()) return true

  const success = await fetchCsrfCookie()
  if (!success) return false

  await new Promise((resolve) => setTimeout(resolve, 100))
  return hasXsrfToken()
}
