import { computed, readonly, ref } from 'vue'

import { isClient } from '~/lib/env'
import { safeReturnPath } from '~/lib/safeReturnPath'
import { useI18n } from '~/plugins/i18n'
import { getPosthog } from '~/plugins/posthog'
import { router } from '~/router'
import type { User } from '~/types/users'

interface LoginPayload {
  email: string
  password: string
}

interface ForgotPasswordPayload {
  email: string
}

interface ResetPasswordPayload {
  email: string
  token: string
  password: string
  password_confirmation: string
}

interface RegisterPayload {
  email: string
  password: string
  firstname: string
  lastname: string
  invite_id?: string
  invite_token?: string
}

interface AuthResponse {
  access_token?: string
  refresh_token?: string
  token_type?: 'bearer'
  expires_in?: number
}

interface ParsedError {
  status?: number
  errorCode?: string
  message?: string
}

const globalUser = ref<User | null>(null)
const globalIsReady = ref(false)
const globalIsInitializing = ref(false)

// Endpoints that are *expected* to answer 401/419 while logged out — their callers
// render the error themselves, so a 401 here must not trigger a session teardown.
const UNAUTHENTICATED_ENDPOINTS = [
  '/auth/v1/csrf-cookie',
  '/auth/v1/token',
  '/auth/v1/register',
  '/auth/v1/logout',
  '/auth/v1/social/',
  '/auth/v1/password/',
  '/auth/v1/one-time-token/',
  '/auth/v1/email/verify',
]

const isUnauthenticatedEndpoint = (endpoint: string): boolean =>
  UNAUTHENTICATED_ENDPOINTS.some((path) => endpoint.includes(path))

// Persisted, user-scoped browser state. It has to go when the session ends, or
// the next account on a shared browser starts in the previous user's language
// with their team selected.
const PERSISTED_USER_STATE_KEYS = ['user-settings', 'global-team']

const clearPersistedUserState = () => {
  if (typeof window === 'undefined') return

  for (const key of PERSISTED_USER_STATE_KEYS) {
    try {
      window.localStorage.removeItem(key)
    } catch {
      /** A storage-less or full browser must not break the sign-out. */
    }
  }
}

// Dropping the cache is what stops an expired session's authenticated responses
// from being served to whoever comes next. Imported lazily: the query plugin
// pulls in the composables that call `useAuth`.
const clearQueryCache = async () => {
  const { queryClient } = await import('~/plugins/vue-query')
  queryClient.cancelQueries()
  queryClient.clear()
}

// Every in-flight request fails at once when a session expires; collapse them into
// a single logout + redirect.
let pendingSessionExpiry: Promise<void> | null = null

// Once we know the session is gone, stop re-probing /users/me on every navigation —
// that probe would 401 again and re-enter the redirect.
const globalSessionExpired = ref(false)

// Concurrent initAuth() callers share one /users/me probe.
let initAuthPromise: Promise<void> | null = null

export function useAuth() {
  const { t } = useI18n()

  const user = globalUser
  const setUser = (value: User | null) => {
    if (!value) {
      // A null user ends the session, so it has to take the session's cached
      // responses with it. The server call and the redirect stay in `logout()` —
      // only it knows whether the current route survives a sign-out.
      user.value = null
      void clearQueryCache()
      return
    }

    user.value = value
  }
  const isAuthenticated = computed(() => !!user.value)
  const isLoading = ref(false)
  const isReady = globalIsReady
  const isInitializing = globalIsInitializing
  const error = ref<string | null>(null)

  const requiresTwoFactor = ref(false)
  const pendingLoginPayload = ref<LoginPayload | null>(null)

  const parseErrorResponse = (err: any): ParsedError => {
    const status = err?.status || err?.statusCode || err?.response?.status
    const data = err?.data || err?.response?.data || err?.response?._data
    const errorCode = data?.error_code || data?.code
    const message = data?.message || data?.error || err?.message

    return { status, errorCode, message }
  }

  const ensureCsrfCookie = async () => {
    const { api } = await import('~/api')
    await api.client.ensureCsrfCookie()
  }

  const loadUser = async (force: boolean = false): Promise<void> => {
    if (user.value && !force) return

    try {
      const { api } = await import('~/api')
      const response = await api.client.request<ApiResponse<User>>('/mgmt/v1/users/me')
      user.value = response.data
      globalSessionExpired.value = false

      getPosthog().identify(user.value.id, {
        email: user.value.email,
        name: `${user.value.firstname} ${user.value.lastname}`,
      })
    } catch (err: any) {
      const { status } = parseErrorResponse(err)
      user.value = null

      if (status && status !== 401) {
        console.error(t('composables.auth.failedToLoadUser') as string, err)
      }
    }
  }

  const handleAuthResponse = async (cb: CallableFunction) => {
    await loadUser(true)

    // The credentials were accepted but the session never materialized — navigating
    // now would only bounce off the router guard with nothing shown to the user.
    if (!user.value) {
      error.value = t('composables.auth.failedToLoadUser') as string
      return false
    }

    cb()
    return true
  }

  const login = async (payload: LoginPayload, twoFactorCode?: string): Promise<boolean> => {
    isLoading.value = true
    error.value = null

    try {
      const { api } = await import('~/api')
      const headers: Record<string, string> = {}

      if (twoFactorCode) {
        headers['x-totp-code'] = twoFactorCode
      }

      await ensureCsrfCookie()

      await api.client.post<AuthResponse>('/auth/v1/token', payload, { headers })

      requiresTwoFactor.value = false
      pendingLoginPayload.value = null

      return await handleAuthResponse(() => {
        const currentRoute = router.currentRoute.value
        router.push(safeReturnPath(currentRoute.query.return))
      })
    } catch (err: any) {
      const { status, errorCode, message } = parseErrorResponse(err)

      if (status === 423 && errorCode === 'TOTP_VERIFICATION_REQUIRED') {
        requiresTwoFactor.value = true
        pendingLoginPayload.value = payload
        error.value = null
        return false
      }

      if (status === 403 && errorCode === 'INVALID_TOTP_CODE') {
        error.value = t('composables.auth.invalidTotpCode') as string
      } else if (status === 409 && errorCode === 'EMAIL_NOT_VERIFIED') {
        error.value = t('composables.auth.emailNotVerified') as string
      } else if (message) {
        error.value = message
      } else {
        error.value = t('composables.auth.loginFailed') as string
      }

      return false
    } finally {
      isLoading.value = false
    }
  }

  const verifyTwoFactorAndLogin = async (code: string): Promise<boolean> => {
    if (!pendingLoginPayload.value) {
      error.value = t('composables.auth.loginSessionExpired') as string
      return false
    }

    return await login(pendingLoginPayload.value, code)
  }

  const verifySocialTwoFactorAndLogin = async (code: string): Promise<boolean> => {
    isLoading.value = true
    error.value = null

    try {
      const { api } = await import('~/api')
      await ensureCsrfCookie()
      await api.client.post<AuthResponse>('/auth/v1/social/2fa', { code })

      return await handleAuthResponse(() => {
        const currentRoute = router.currentRoute.value
        router.push(safeReturnPath(currentRoute.query.return))
      })
    } catch (err: any) {
      const { status, errorCode, message } = parseErrorResponse(err)

      if (status === 403 && errorCode === 'INVALID_TOTP_CODE') {
        error.value = t('composables.auth.invalidTotpCode') as string
      } else if (status === 401 && errorCode === 'SOCIAL_LOGIN_SESSION_EXPIRED') {
        error.value = t('composables.auth.loginSessionExpired') as string
      } else {
        error.value = message ?? (t('composables.auth.loginFailed') as string)
      }

      return false
    } finally {
      isLoading.value = false
    }
  }

  const cancelTwoFactorLogin = () => {
    requiresTwoFactor.value = false
    pendingLoginPayload.value = null
    error.value = null
  }

  const forgotPassword = async (payload: ForgotPasswordPayload): Promise<boolean> => {
    isLoading.value = true
    error.value = null

    try {
      const { api } = await import('~/api')
      await ensureCsrfCookie()
      await api.client.post('/auth/v1/password/email', payload)
      return true
    } catch (err: any) {
      const parsedError = parseErrorResponse(err)
      error.value = parsedError.message ?? (t('composables.auth.forgotPasswordFailed') as string)
      return false
    } finally {
      isLoading.value = false
    }
  }

  const resetPassword = async (payload: ResetPasswordPayload): Promise<boolean> => {
    isLoading.value = true
    error.value = null

    try {
      const { api } = await import('~/api')
      await ensureCsrfCookie()
      await api.client.post('/auth/v1/password/reset', payload)

      router.push({
        name: 'login',
        query: { message: 'password_reset_success' },
      })

      return true
    } catch (err: any) {
      const parsedError = parseErrorResponse(err)
      error.value = parsedError.message ?? (t('composables.auth.resetPasswordFailed') as string)
      return false
    } finally {
      isLoading.value = false
    }
  }

  const register = async (payload: RegisterPayload): Promise<boolean> => {
    isLoading.value = true
    error.value = null

    try {
      const { api } = await import('~/api')
      await ensureCsrfCookie()
      await api.client.post<AuthResponse>('/auth/v1/register', payload)

      return await handleAuthResponse(() => {
        const currentRoute = router.currentRoute.value
        router.push(safeReturnPath(currentRoute.query.return))
      })
    } catch (err: any) {
      const parsedError = parseErrorResponse(err)

      // A 409 is usually a taken email, but not always (an already accepted invite
      // answers 409 too), so a server message wins over the assumption.
      error.value =
        parsedError.message ??
        (parsedError.status === 409
          ? (t('composables.auth.emailExists') as string)
          : (t('composables.auth.registerFailed') as string))
      return false
    } finally {
      isLoading.value = false
    }
  }

  const logout = async (
    options: { returnPath?: string; expired?: boolean } = {}
  ): Promise<void> => {
    const { returnPath, expired = false } = options

    // The session is already gone when it expired — the call would only 401 again.
    if (!expired) {
      try {
        const { api } = await import('~/api')
        await api.client.post('/auth/v1/logout')
      } catch (error) {
        console.warn('[Auth] Logout API call failed:', error)
      }
    }

    await clearQueryCache()
    clearPersistedUserState()

    user.value = null
    globalSessionExpired.value = true
    globalIsReady.value = true
    error.value = null
    requiresTwoFactor.value = false
    pendingLoginPayload.value = null

    getPosthog().reset()

    const currentRoute = router.currentRoute.value

    if (currentRoute.meta.guest === true || currentRoute.meta.public === true) {
      return
    }

    await router.push({
      name: 'login',
      query: {
        return: safeReturnPath(
          returnPath || currentRoute.query.return || currentRoute.fullPath || '/'
        ),
        ...(expired ? { message: 'session_expired' } : {}),
      },
    })
  }

  const handleUnauthorized = async (endpoint: string = ''): Promise<{ retry?: boolean }> => {
    // Login, registration and password flows own their 401s.
    if (isUnauthenticatedEndpoint(endpoint)) {
      return { retry: false }
    }

    // Never bounce a guest/public route (invites, public shares, the login page
    // itself) — a 401 there is expected, not a lost session.
    const currentRoute = router.currentRoute.value
    if (currentRoute.meta.guest === true || currentRoute.meta.public === true) {
      user.value = null
      // The route survives, the session does not — the responses it fetched must
      // not stay in the cache for the rest of the tab's life.
      await clearQueryCache()
      return { retry: false }
    }

    // No user was ever loaded in this session (e.g. the initial /users/me probe of a
    // logged-out visitor) — nothing expired, let the router guard do the redirect.
    if (!user.value) {
      globalSessionExpired.value = true
      return { retry: false }
    }

    if (!pendingSessionExpiry) {
      pendingSessionExpiry = logout({ expired: true }).finally(() => {
        pendingSessionExpiry = null
      })
    }

    await pendingSessionExpiry

    return { retry: false }
  }

  const initAuth = async (): Promise<void> => {
    if (!isClient) return
    if (initAuthPromise) return initAuthPromise
    // Already initialized with a loaded user — don't block navigation on a refetch
    if (isReady.value && user.value) return
    // Session already known to be gone — probing again would just 401 in a loop
    if (globalSessionExpired.value && !user.value) {
      isReady.value = true
      return
    }

    isInitializing.value = true
    initAuthPromise = (async () => {
      try {
        await loadUser(true)
      } catch (error) {
        console.error('[Auth] Failed to load user during init:', error)
      } finally {
        isReady.value = true
        isInitializing.value = false
        initAuthPromise = null
      }
    })()

    return initAuthPromise
  }

  return {
    user: readonly(user),
    setUser,
    isAuthenticated: readonly(isAuthenticated),
    isLoading: readonly(isLoading),
    isReady: readonly(isReady),
    sessionExpired: readonly(globalSessionExpired),
    error,
    requiresTwoFactor: readonly(requiresTwoFactor),

    login,
    verifyTwoFactorAndLogin,
    verifySocialTwoFactorAndLogin,
    cancelTwoFactorLogin,
    forgotPassword,
    resetPassword,
    register,
    logout,
    handleUnauthorized,
    loadUser,
    initAuth,
  }
}
