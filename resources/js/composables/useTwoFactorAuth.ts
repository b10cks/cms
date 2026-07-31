import { computed, readonly, ref, shallowRef } from 'vue'

import { api } from '~/api'
import type { RequestOptions } from '~/api/client'

type StepUpRequirement = 'totp' | 'password'

/** The `error_code` the step-up middleware returns, mapped to what it wants. */
const REQUIREMENT_BY_ERROR_CODE: Record<string, StepUpRequirement> = {
  TOTP_VERIFICATION_REQUIRED: 'totp',
  PASSWORD_CONFIRMATION_REQUIRED: 'password',
}

/** Lowercase to match the middleware and `useAuth`; HTTP headers are case-insensitive. */
const HEADER_BY_REQUIREMENT: Record<StepUpRequirement, string> = {
  totp: 'x-totp-code',
  password: 'x-password-confirmation',
}

export interface QueuedRequest {
  requirement: StepUpRequirement
  endpoint: string
  options: RequestOptions
  resolve: (value: unknown) => void
  reject: (reason: unknown) => void
}

export interface TwoFactorState {
  requiresVerification: boolean
  requiresPassword: boolean
  pendingRequests: QueuedRequest[]
}

interface StepUpError {
  status?: number
  data?: { error_code?: string }
  response?: { status?: number; data?: { error_code?: string } }
}

/**
 * `~/api/client` puts the parsed body on `error.data` and the raw `Response` —
 * which has no `.data` — on `error.response`. Both shapes are read so the
 * interception survives the client moving the body back under `response.data`.
 */
const parseStepUpError = (error: unknown): { status?: number; errorCode?: string } => {
  if (!error || typeof error !== 'object') return {}

  const source = error as StepUpError
  return {
    status: source.status ?? source.response?.status,
    errorCode: source.data?.error_code ?? source.response?.data?.error_code,
  }
}

export function useTwoFactorAuth() {
  // A shallow ref holding a plain array: every parked challenge must survive,
  // and reassignment keeps the queued `options` free of a reactive proxy.
  const pendingRequests = shallowRef<QueuedRequest[]>([])

  const verifyDialogOpen = ref(false)
  const passwordDialogOpen = ref(false)

  const state = computed<TwoFactorState>(() => ({
    requiresVerification: pendingRequests.value.some((entry) => entry.requirement === 'totp'),
    requiresPassword: pendingRequests.value.some((entry) => entry.requirement === 'password'),
    pendingRequests: pendingRequests.value,
  }))

  const makeRequestWith2FA = async <T>(
    endpoint: string,
    options: RequestOptions = {}
  ): Promise<T> => {
    try {
      return await api.client.request<T>(endpoint, options)
    } catch (error) {
      const { status, errorCode } = parseStepUpError(error)
      const requirement =
        status === 423 && errorCode ? REQUIREMENT_BY_ERROR_CODE[errorCode] : undefined

      if (!requirement) throw error

      return new Promise<T>((resolve, reject) => {
        // Appended, never overwritten: two concurrent challenges must both be
        // replayed, or the older caller's promise never settles at all.
        pendingRequests.value = [
          ...pendingRequests.value,
          {
            requirement,
            endpoint,
            options,
            resolve: resolve as (value: unknown) => void,
            reject,
          },
        ]

        if (requirement === 'totp') {
          verifyDialogOpen.value = true
        } else {
          passwordDialogOpen.value = true
        }
      })
    }
  }

  const verify = async (requirement: StepUpRequirement, credential: string): Promise<void> => {
    // Only the challenge that is actually pending may be answered: replying to
    // a TOTP prompt with a password would send the wrong header and leave the
    // verify dialog open over an already-completed request.
    const parked = pendingRequests.value.filter((entry) => entry.requirement === requirement)
    if (!parked.length) return

    // Deliberately not caught: on a wrong credential the requests stay parked
    // and the dialog stays open, so a correct retry still settles the original
    // callers. Rejecting them here would settle them for good and turn the
    // later `resolve` into a silent no-op.
    const responses = await Promise.all(
      parked.map((entry) =>
        api.client.request(entry.endpoint, {
          ...entry.options,
          headers: {
            ...entry.options.headers,
            [HEADER_BY_REQUIREMENT[requirement]]: credential,
          },
        })
      )
    )

    pendingRequests.value = pendingRequests.value.filter(
      (entry) => entry.requirement !== requirement
    )

    if (requirement === 'totp') {
      verifyDialogOpen.value = false
    } else {
      passwordDialogOpen.value = false
    }

    parked.forEach((entry, index) => entry.resolve(responses[index]))
  }

  const verifyWithTOTP = (code: string): Promise<void> => verify('totp', code)

  const verifyWithPassword = (password: string): Promise<void> => verify('password', password)

  const cancelVerification = () => {
    const parked = pendingRequests.value

    pendingRequests.value = []
    verifyDialogOpen.value = false
    passwordDialogOpen.value = false

    parked.forEach((entry) => entry.reject(new Error('Verification cancelled')))
  }

  return {
    state,
    verifyDialogOpen: readonly(verifyDialogOpen),
    passwordDialogOpen: readonly(passwordDialogOpen),
    makeRequestWith2FA,
    verifyWithTOTP,
    verifyWithPassword,
    cancelVerification,
  }
}
