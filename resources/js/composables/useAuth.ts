import { computed, readonly, ref } from 'vue'

import type { ApiResponse } from '~/types'
import type { User } from '~/types/users'

import { isClient } from '~/lib/env'
import { useI18n } from '~/plugins/i18n'
import { router } from '~/router'

interface LoginPayload {
  email: string
  password: string
}

interface RegisterPayload {
  email: string
  password: string
  firstname: string
  lastname: string
  invite_id?: string
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

export function useAuth() {
  const { t } = useI18n()

  const user = globalUser
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
        router.push((currentRoute.query.return as string) || '/')
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

  const cancelTwoFactorLogin = () => {
    requiresTwoFactor.value = false
    pendingLoginPayload.value = null
    error.value = null
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
        router.push((currentRoute.query.return as string) || '/')
      })
    } catch (err: any) {
      error.value =
        err?.response?.status === 409
          ? (t('composables.auth.emailExists') as string)
          : (t('composables.auth.registerFailed') as string)
      return false
    } finally {
      isLoading.value = false
    }
  }

  const logout = async (returnPath?: string): Promise<void> => {
    try {
      const { api } = await import('~/api')
      await api.client.post('/auth/v1/logout')
    } catch (error) {
      console.warn('[Auth] Logout API call failed:', error)
    }

    user.value = null
    globalIsReady.value = false
    error.value = null
    requiresTwoFactor.value = false
    pendingLoginPayload.value = null

    const currentRoute = router.currentRoute.value

    if (currentRoute.fullPath.startsWith('/login')) {
      return
    }

    router.push({
      name: 'login',
      query: { return: returnPath || currentRoute.query.return || currentRoute.fullPath || '/' },
    })
  }

  const handleUnauthorized = async (): Promise<{ retry?: boolean }> => {
    await logout()
    return { retry: false }
  }

  const initAuth = async (): Promise<void> => {
    if (!isClient) return
    if (isInitializing.value) return

    isInitializing.value = true
    try {
      await loadUser(true)
    } catch (error) {
      console.error('[Auth] Failed to load user during init:', error)
    } finally {
      isReady.value = true
      isInitializing.value = false
    }
  }

  return {
    user: readonly(user),
    isAuthenticated: readonly(isAuthenticated),
    isLoading: readonly(isLoading),
    isReady: readonly(isReady),
    error,
    requiresTwoFactor: readonly(requiresTwoFactor),

    login,
    verifyTwoFactorAndLogin,
    cancelTwoFactorLogin,
    register,
    logout,
    handleUnauthorized,
    loadUser,
    initAuth,
  }
}
