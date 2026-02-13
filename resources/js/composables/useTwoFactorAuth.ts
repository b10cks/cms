import { ref } from 'vue'

import { api } from '~/api'

interface QueuedRequest {
  resolve: (value: any) => void
  reject: (reason: any) => void
  endpoint: string
  options: any
}

interface TwoFactorState {
  requiresVerification: boolean
  requiresPassword: boolean
  pendingRequest: QueuedRequest | null
}

export function useTwoFactorAuth() {
  const state = ref<TwoFactorState>({
    requiresVerification: false,
    requiresPassword: false,
    pendingRequest: null,
  })

  const verifyDialogOpen = ref(false)
  const passwordDialogOpen = ref(false)

  const makeRequestWith2FA = async <T>(endpoint: string, options: any = {}): Promise<T> => {
    try {
      return await api.client.request<T>(endpoint, options)
    } catch (error: any) {
      const errorCode = error?.response?.data?.error_code
      const status = error?.response?.status

      if (status === 423) {
        if (errorCode === 'TOTP_VERIFICATION_REQUIRED') {
          state.value.requiresVerification = true
          state.value.pendingRequest = { resolve: () => {}, reject: () => {}, endpoint, options }
          verifyDialogOpen.value = true

          return new Promise((resolve, reject) => {
            state.value.pendingRequest = { resolve, reject, endpoint, options }
          })
        }

        if (errorCode === 'PASSWORD_CONFIRMATION_REQUIRED') {
          state.value.requiresPassword = true
          state.value.pendingRequest = { resolve: () => {}, reject: () => {}, endpoint, options }
          passwordDialogOpen.value = true

          return new Promise((resolve, reject) => {
            state.value.pendingRequest = { resolve, reject, endpoint, options }
          })
        }
      }

      throw error
    }
  }

  const verifyWithTOTP = async (code: string): Promise<void> => {
    if (!state.value.pendingRequest) return

    const { endpoint, options, resolve, reject } = state.value.pendingRequest

    try {
      const headers = {
        ...options.headers,
        'X-TOTP-Code': code,
      }

      const response = await api.client.request(endpoint, {
        ...options,
        headers,
      })

      state.value.requiresVerification = false
      state.value.pendingRequest = null
      verifyDialogOpen.value = false

      resolve(response)
    } catch (error) {
      reject(error)
      throw error
    }
  }

  const verifyWithPassword = async (password: string): Promise<void> => {
    if (!state.value.pendingRequest) return

    const { endpoint, options, resolve, reject } = state.value.pendingRequest

    try {
      const headers = {
        ...options.headers,
        'X-Password-Confirmation': password,
      }

      const response = await api.client.request(endpoint, {
        ...options,
        headers,
      })

      state.value.requiresPassword = false
      state.value.pendingRequest = null
      passwordDialogOpen.value = false

      resolve(response)
    } catch (error) {
      reject(error)
      throw error
    }
  }

  const cancelVerification = () => {
    if (state.value.pendingRequest) {
      state.value.pendingRequest.reject(new Error('Verification cancelled'))
    }
    state.value.requiresVerification = false
    state.value.requiresPassword = false
    state.value.pendingRequest = null
    verifyDialogOpen.value = false
    passwordDialogOpen.value = false
  }

  return {
    state: readonly(state),
    verifyDialogOpen: readonly(verifyDialogOpen),
    passwordDialogOpen: readonly(passwordDialogOpen),
    makeRequestWith2FA,
    verifyWithTOTP,
    verifyWithPassword,
    cancelVerification,
  }
}
