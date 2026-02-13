import { isClient } from '~/lib/env'
const XSRF_COOKIE_NAME = 'XSRF-TOKEN'

const isClient = typeof window !== 'undefined'

export const getXsrfToken = (): string | null => {
  if (!isClient) return null

  const cookies = document.cookie ? document.cookie.split('; ') : []
  const entry = cookies.find((cookie) => cookie.startsWith(`${XSRF_COOKIE_NAME}=`))

  if (!entry) {
    console.warn('[CSRF] XSRF-TOKEN cookie not found. Available cookies:', cookies)
    return null
  }

  const value = entry.split('=').slice(1).join('=')
  return value ? decodeURIComponent(value) : null
}

export const getXsrfHeaders = (): Record<string, string> => {
  const token = getXsrfToken()
  if (!token) {
    console.warn('[CSRF] No XSRF token available for request headers')
  }
  return token ? { 'X-XSRF-TOKEN': token } : {}
}
