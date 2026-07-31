import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const request = vi.fn()

vi.mock('~/api', () => ({
  api: { client: { request } },
}))

const { useTwoFactorAuth } = await import('~/composables/useTwoFactorAuth')

type TwoFactorAuth = ReturnType<typeof useTwoFactorAuth>

/** What `~/api/client` really rejects with — `response` is the raw `Response`. */
const clientError = (status: number, errorCode?: string) => {
  const error = new Error('Locked') as Error & Record<string, unknown>
  error.response = new Response(null, { status })
  error.data = errorCode ? { error_code: errorCode } : {}
  error.status = status
  return error
}

/** The older envelope shape, where the body sat under `response.data`. */
const nestedError = (status: number, errorCode?: string) => ({
  response: { status, data: errorCode ? { error_code: errorCode } : {} },
})

let auth: TwoFactorAuth
let parkCount = 0

/**
 * Park a step-up request and wait until the composable has queued it.
 *
 * The pending promise is returned wrapped: returning it bare from an `async`
 * helper would make `await park(...)` adopt it, and it never settles on its own.
 * Each call uses a fresh endpoint so the wait condition cannot match a request
 * parked by an earlier call.
 */
const park = async (
  errorCode: 'TOTP_VERIFICATION_REQUIRED' | 'PASSWORD_CONFIRMATION_REQUIRED',
  options: Record<string, unknown> = {}
) => {
  const endpoint = `/mgmt/v1/spaces/park-${++parkCount}`
  request.mockRejectedValueOnce(clientError(423, errorCode))
  const pending = auth.makeRequestWith2FA<{ ok: boolean }>(endpoint, options)
  await vi.waitUntil(() =>
    auth.state.value.pendingRequests.some((entry) => entry.endpoint === endpoint)
  )
  return { pending, endpoint }
}

beforeEach(() => {
  vi.clearAllMocks()
  // No module-scoped state: every call gets its own dialog flags and queue.
  auth = useTwoFactorAuth()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('initial state', () => {
  it('starts with nothing pending and both dialogs closed', () => {
    expect(auth.state.value).toEqual({
      requiresVerification: false,
      requiresPassword: false,
      pendingRequests: [],
    })
    expect(auth.verifyDialogOpen.value).toBe(false)
    expect(auth.passwordDialogOpen.value).toBe(false)
  })

  it('gives each caller its own state — nothing is shared at module scope', async () => {
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')

    expect(useTwoFactorAuth().verifyDialogOpen.value).toBe(false)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow()
  })
})

describe('makeRequestWith2FA', () => {
  it('passes the endpoint and options straight through and returns the response', async () => {
    request.mockResolvedValue({ ok: true })

    const result = await auth.makeRequestWith2FA<{ ok: boolean }>('/mgmt/v1/spaces', {
      method: 'POST',
      body: { name: 'Space' },
    })

    expect(request).toHaveBeenCalledWith('/mgmt/v1/spaces', {
      method: 'POST',
      body: { name: 'Space' },
    })
    expect(result).toEqual({ ok: true })
  })

  it('defaults the options to an empty object', async () => {
    request.mockResolvedValue({ ok: true })

    await auth.makeRequestWith2FA('/mgmt/v1/spaces')

    expect(request).toHaveBeenCalledWith('/mgmt/v1/spaces', {})
  })

  it.each([401, 403, 422, 500])('rethrows a %i without opening a dialog', async (status) => {
    request.mockRejectedValue(clientError(status, 'TOTP_VERIFICATION_REQUIRED'))

    await expect(auth.makeRequestWith2FA('/mgmt/v1/spaces')).rejects.toThrow('Locked')
    expect(auth.verifyDialogOpen.value).toBe(false)
    expect(auth.passwordDialogOpen.value).toBe(false)
    expect(auth.state.value.pendingRequests).toEqual([])
  })

  it.each([
    ['an unrecognised code', 'SOME_OTHER_REQUIREMENT'],
    ['no code at all', undefined],
  ])('rethrows a 423 carrying %s', async (_label, errorCode) => {
    request.mockRejectedValue(clientError(423, errorCode))

    await expect(auth.makeRequestWith2FA('/mgmt/v1/spaces')).rejects.toBeTruthy()
    expect(auth.state.value.pendingRequests).toEqual([])
    expect(auth.verifyDialogOpen.value).toBe(false)
    expect(auth.passwordDialogOpen.value).toBe(false)
  })

  it('rethrows a bare rejection with no response envelope', async () => {
    request.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(auth.makeRequestWith2FA('/mgmt/v1/spaces')).rejects.toThrow('Failed to fetch')
    expect(auth.state.value.pendingRequests).toEqual([])
  })

  it('intercepts a real 423 from the API client, which carries the code on error.data', async () => {
    // `~/api/client` sets `error.response` to the raw `Response` — whose `.data`
    // is undefined — and puts the parsed body on `error.data`. Reading only
    // `response.data.error_code` made both step-up branches unreachable.
    request.mockRejectedValueOnce(clientError(423, 'TOTP_VERIFICATION_REQUIRED'))

    const pending = auth.makeRequestWith2FA('/mgmt/v1/spaces')
    await vi.waitUntil(() => auth.verifyDialogOpen.value)

    expect(auth.state.value.requiresVerification).toBe(true)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow('Verification cancelled')
  })

  it('still intercepts the older envelope, where the body sat under response.data', async () => {
    request.mockRejectedValueOnce(nestedError(423, 'PASSWORD_CONFIRMATION_REQUIRED'))

    const pending = auth.makeRequestWith2FA('/mgmt/v1/spaces')
    await vi.waitUntil(() => auth.passwordDialogOpen.value)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow('Verification cancelled')
  })

  it('parks a TOTP challenge and opens the verify dialog', async () => {
    const { pending, endpoint } = await park('TOTP_VERIFICATION_REQUIRED', { method: 'DELETE' })

    expect(auth.state.value.requiresVerification).toBe(true)
    expect(auth.state.value.requiresPassword).toBe(false)
    expect(auth.verifyDialogOpen.value).toBe(true)
    expect(auth.passwordDialogOpen.value).toBe(false)
    expect(auth.state.value.pendingRequests[0]).toMatchObject({
      requirement: 'totp',
      endpoint,
      options: { method: 'DELETE' },
    })

    auth.cancelVerification()
    await expect(pending).rejects.toThrow('Verification cancelled')
  })

  it('parks a password challenge and opens the password dialog', async () => {
    const { pending } = await park('PASSWORD_CONFIRMATION_REQUIRED')

    expect(auth.state.value.requiresPassword).toBe(true)
    expect(auth.state.value.requiresVerification).toBe(false)
    expect(auth.passwordDialogOpen.value).toBe(true)
    expect(auth.verifyDialogOpen.value).toBe(false)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow('Verification cancelled')
  })

  it('queues a second challenge instead of orphaning the first caller', async () => {
    const first = await park('TOTP_VERIFICATION_REQUIRED', { method: 'DELETE' })
    const second = await park('TOTP_VERIFICATION_REQUIRED', { method: 'PATCH' })

    expect(auth.state.value.pendingRequests.map((entry) => entry.endpoint)).toEqual([
      first.endpoint,
      second.endpoint,
    ])

    // One step-up unlocks the session, so both parked requests are replayed.
    request.mockResolvedValue({ ok: true })
    await auth.verifyWithTOTP('123456')

    expect(await first.pending).toEqual({ ok: true })
    expect(await second.pending).toEqual({ ok: true })
    expect(auth.state.value.pendingRequests).toEqual([])
  })

  it('tracks a TOTP and a password challenge independently', async () => {
    const totp = await park('TOTP_VERIFICATION_REQUIRED')
    const password = await park('PASSWORD_CONFIRMATION_REQUIRED')

    expect(auth.verifyDialogOpen.value).toBe(true)
    expect(auth.passwordDialogOpen.value).toBe(true)

    request.mockResolvedValue({ ok: true })
    await auth.verifyWithPassword('secret')

    // Answering the password prompt must not touch the TOTP one.
    expect(await password.pending).toEqual({ ok: true })
    expect(auth.passwordDialogOpen.value).toBe(false)
    expect(auth.verifyDialogOpen.value).toBe(true)
    expect(auth.state.value.requiresVerification).toBe(true)

    await auth.verifyWithTOTP('123456')
    expect(await totp.pending).toEqual({ ok: true })
  })
})

describe('verifyWithTOTP', () => {
  it('replays the request with the code header and resolves the parked promise', async () => {
    const { pending, endpoint } = await park('TOTP_VERIFICATION_REQUIRED', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: { name: 'Space' },
    })
    request.mockResolvedValueOnce({ ok: true })

    await auth.verifyWithTOTP('123456')

    // Lowercase to match the `x-totp-code` the middleware reads and `useAuth`.
    expect(request).toHaveBeenLastCalledWith(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'x-totp-code': '123456' },
      body: { name: 'Space' },
    })
    expect(await pending).toEqual({ ok: true })
  })

  it('closes the dialog and clears the queue on success', async () => {
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')
    request.mockResolvedValueOnce({ ok: true })

    await auth.verifyWithTOTP('123456')
    await pending

    expect(auth.state.value.requiresVerification).toBe(false)
    expect(auth.state.value.pendingRequests).toEqual([])
    expect(auth.verifyDialogOpen.value).toBe(false)
  })

  it('adds the header when the original request carried none', async () => {
    const { pending, endpoint } = await park('TOTP_VERIFICATION_REQUIRED')
    request.mockResolvedValueOnce({ ok: true })

    await auth.verifyWithTOTP('123456')
    await pending

    expect(request).toHaveBeenLastCalledWith(endpoint, {
      headers: { 'x-totp-code': '123456' },
    })
  })

  it('does nothing at all without a parked request', async () => {
    expect(await auth.verifyWithTOTP('123456')).toBeUndefined()
    expect(request).not.toHaveBeenCalled()
  })

  it('ignores a password challenge — it can only answer a TOTP one', async () => {
    const { pending } = await park('PASSWORD_CONFIRMATION_REQUIRED')
    request.mockClear()

    expect(await auth.verifyWithTOTP('123456')).toBeUndefined()
    expect(request).not.toHaveBeenCalled()
    expect(auth.passwordDialogOpen.value).toBe(true)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow()
  })

  it('rethrows a wrong code without settling the parked promise', async () => {
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')
    request.mockRejectedValueOnce(new Error('Invalid code'))

    await expect(auth.verifyWithTOTP('000000')).rejects.toThrow('Invalid code')

    // The caller must stay unsettled: rejecting it here would make the later
    // `resolve` of a successful retry a silent no-op.
    const settled = await Promise.race([
      pending.then(() => 'settled').catch(() => 'settled'),
      Promise.resolve('still pending'),
    ])
    expect(settled).toBe('still pending')
  })

  it('recovers the original caller with a correct retry after a wrong code', async () => {
    const { pending, endpoint } = await park('TOTP_VERIFICATION_REQUIRED')
    request.mockRejectedValueOnce(new Error('Invalid code'))
    await expect(auth.verifyWithTOTP('000000')).rejects.toThrow()

    request.mockResolvedValueOnce({ ok: true })
    await auth.verifyWithTOTP('123456')

    expect(request).toHaveBeenLastCalledWith(endpoint, { headers: { 'x-totp-code': '123456' } })
    expect(await pending).toEqual({ ok: true })
    expect(auth.verifyDialogOpen.value).toBe(false)
  })

  it('leaves the dialog open after a wrong code so the user can retry', async () => {
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')
    request.mockRejectedValueOnce(new Error('Invalid code'))

    await expect(auth.verifyWithTOTP('000000')).rejects.toThrow()

    expect(auth.verifyDialogOpen.value).toBe(true)
    expect(auth.state.value.requiresVerification).toBe(true)
    expect(auth.state.value.pendingRequests).toHaveLength(1)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow('Verification cancelled')
  })
})

describe('verifyWithPassword', () => {
  it('replays the request with the confirmation header and resolves the parked promise', async () => {
    const { pending, endpoint } = await park('PASSWORD_CONFIRMATION_REQUIRED', {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    })
    request.mockResolvedValueOnce({ ok: true })

    await auth.verifyWithPassword('secret')

    expect(request).toHaveBeenLastCalledWith(endpoint, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'x-password-confirmation': 'secret' },
    })
    expect(await pending).toEqual({ ok: true })
  })

  it('closes the dialog and clears the queue on success', async () => {
    const { pending } = await park('PASSWORD_CONFIRMATION_REQUIRED')
    request.mockResolvedValueOnce({ ok: true })

    await auth.verifyWithPassword('secret')
    await pending

    expect(auth.state.value.requiresPassword).toBe(false)
    expect(auth.state.value.pendingRequests).toEqual([])
    expect(auth.passwordDialogOpen.value).toBe(false)
  })

  it('does nothing at all without a parked request', async () => {
    expect(await auth.verifyWithPassword('secret')).toBeUndefined()
    expect(request).not.toHaveBeenCalled()
  })

  it('rethrows a wrong password and keeps the request parked', async () => {
    const { pending } = await park('PASSWORD_CONFIRMATION_REQUIRED')
    request.mockRejectedValueOnce(new Error('The password is incorrect.'))

    await expect(auth.verifyWithPassword('wrong')).rejects.toThrow('The password is incorrect.')
    expect(auth.passwordDialogOpen.value).toBe(true)
    expect(auth.state.value.pendingRequests).toHaveLength(1)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow('Verification cancelled')
  })

  it('refuses to answer a TOTP challenge with a password', async () => {
    // A password can only confirm a PASSWORD_CONFIRMATION_REQUIRED challenge:
    // answering a TOTP prompt would send the wrong header and clear the wrong
    // flag, leaving the verify dialog stuck open over a completed request.
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')
    request.mockClear()

    expect(await auth.verifyWithPassword('secret')).toBeUndefined()
    expect(request).not.toHaveBeenCalled()
    expect(auth.verifyDialogOpen.value).toBe(true)
    expect(auth.state.value.requiresVerification).toBe(true)

    auth.cancelVerification()
    await expect(pending).rejects.toThrow()
  })
})

describe('cancelVerification', () => {
  it('rejects the parked promise and resets everything', async () => {
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')

    auth.cancelVerification()

    await expect(pending).rejects.toThrow('Verification cancelled')
    expect(auth.state.value).toEqual({
      requiresVerification: false,
      requiresPassword: false,
      pendingRequests: [],
    })
    expect(auth.verifyDialogOpen.value).toBe(false)
    expect(auth.passwordDialogOpen.value).toBe(false)
  })

  it('closes both dialogs when a password challenge is cancelled', async () => {
    const { pending } = await park('PASSWORD_CONFIRMATION_REQUIRED')

    auth.cancelVerification()

    await expect(pending).rejects.toThrow('Verification cancelled')
    expect(auth.state.value.requiresPassword).toBe(false)
    expect(auth.passwordDialogOpen.value).toBe(false)
  })

  it('rejects every queued caller, not just the newest', async () => {
    const first = await park('TOTP_VERIFICATION_REQUIRED')
    const second = await park('PASSWORD_CONFIRMATION_REQUIRED')

    auth.cancelVerification()

    await expect(first.pending).rejects.toThrow('Verification cancelled')
    await expect(second.pending).rejects.toThrow('Verification cancelled')
  })

  it('is a safe no-op with nothing pending', () => {
    expect(() => auth.cancelVerification()).not.toThrow()
    expect(auth.state.value.pendingRequests).toEqual([])
  })

  it('makes a later verify attempt a no-op', async () => {
    const { pending } = await park('TOTP_VERIFICATION_REQUIRED')
    auth.cancelVerification()
    await expect(pending).rejects.toThrow()

    expect(await auth.verifyWithTOTP('123456')).toBeUndefined()
    expect(request).toHaveBeenCalledTimes(1)
  })
})
