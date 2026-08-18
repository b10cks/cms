import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { User } from '~/types/users'

type Auth = ReturnType<typeof import('~/composables/useAuth').useAuth>

const ensureCsrfCookie = vi.fn()
const request = vi.fn()
const post = vi.fn()

const cancelQueries = vi.fn()
const clear = vi.fn()

const push = vi.fn()
const currentRoute = {
  value: {
    query: {} as Record<string, string>,
    meta: {} as Record<string, unknown>,
    fullPath: '/',
  },
}

const identify = vi.fn()
const reset = vi.fn()

vi.mock('~/api', () => ({
  api: { client: { ensureCsrfCookie, request, post } },
}))

vi.mock('~/plugins/vue-query', () => ({
  queryClient: { cancelQueries, clear },
}))

vi.mock('~/router', () => ({
  router: { currentRoute, push },
}))

vi.mock('~/plugins/posthog', () => ({
  getPosthog: () => ({ identify, reset }),
}))

const user = (overrides: Partial<User> = {}): User =>
  ({
    id: 'user-1',
    email: 'ada@b10cks.test',
    firstname: 'Ada',
    lastname: 'Lovelace',
    ...overrides,
  }) as User

/** The API client rejects with a shaped object, not an Error. */
const apiError = (status: number, data?: Record<string, unknown>) => ({ status, data })

/**
 * `useAuth` keeps the user, the ready flag and the "session expired" latch in
 * module scope, so every test needs a freshly evaluated module rather than a
 * hand-rolled reset of state the composable does not expose.
 */
const loadAuth = async (): Promise<Auth> => {
  vi.resetModules()
  const module = await import('~/composables/useAuth')
  return module.useAuth()
}

let auth: Auth

beforeEach(async () => {
  vi.clearAllMocks()
  currentRoute.value = { query: {}, meta: {}, fullPath: '/' }
  request.mockResolvedValue({ data: user() })
  post.mockResolvedValue({})
  auth = await loadAuth()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('loadUser', () => {
  it('loads the current user and marks the session authenticated', async () => {
    await auth.loadUser()

    expect(request).toHaveBeenCalledWith('/mgmt/v1/users/me')
    expect(auth.user.value).toEqual(user())
    expect(auth.isAuthenticated.value).toBe(true)
  })

  it('skips the request when a user is already loaded', async () => {
    await auth.loadUser()
    await auth.loadUser()

    expect(request).toHaveBeenCalledTimes(1)
  })

  it('refetches when forced', async () => {
    await auth.loadUser()
    request.mockResolvedValue({ data: user({ firstname: 'Grace' }) })

    await auth.loadUser(true)

    expect(auth.user.value?.firstname).toBe('Grace')
  })

  it('identifies the user in posthog', async () => {
    await auth.loadUser()

    expect(identify).toHaveBeenCalledWith('user-1', {
      email: 'ada@b10cks.test',
      name: 'Ada Lovelace',
    })
  })

  it('clears the user on a 401 without logging it — a logged-out probe is expected', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    request.mockRejectedValue(apiError(401))

    await auth.loadUser()

    expect(auth.user.value).toBeNull()
    expect(consoleError).not.toHaveBeenCalled()
  })

  it('logs a non-401 failure and still clears the user', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    request.mockRejectedValue(apiError(500))

    await auth.loadUser()

    expect(auth.user.value).toBeNull()
    expect(consoleError).toHaveBeenCalledWith("Couldn't load your profile. Refresh the page?", {
      status: 500,
      data: undefined,
    })
  })

  it('stays silent for a failure with no status at all', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    request.mockRejectedValue(new TypeError('Failed to fetch'))

    await auth.loadUser()

    expect(auth.user.value).toBeNull()
    expect(consoleError).not.toHaveBeenCalled()
  })

  it('clears the expired latch once a user loads again', async () => {
    await auth.loadUser()
    await auth.logout()
    expect(auth.sessionExpired.value).toBe(true)

    await auth.loadUser(true)

    expect(auth.sessionExpired.value).toBe(false)
  })
})

describe('login', () => {
  it('ensures the CSRF cookie before posting the credentials', async () => {
    const order: string[] = []
    ensureCsrfCookie.mockImplementation(async () => void order.push('csrf'))
    post.mockImplementation(async () => void order.push('token'))

    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    expect(order).toEqual(['csrf', 'token'])
    expect(post).toHaveBeenCalledWith(
      '/auth/v1/token',
      { email: 'ada@b10cks.test', password: 'secret' },
      { headers: {} }
    )
  })

  it('loads the user and redirects home on success', async () => {
    expect(await auth.login({ email: 'ada@b10cks.test', password: 'secret' })).toBe(true)

    expect(auth.user.value).toEqual(user())
    expect(push).toHaveBeenCalledWith('/')
    expect(auth.error.value).toBeNull()
    expect(auth.isLoading.value).toBe(false)
  })

  it('honours the return query parameter', async () => {
    currentRoute.value = { query: { return: '/spaces/space-1' }, meta: {}, fullPath: '/login' }

    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    expect(push).toHaveBeenCalledWith('/spaces/space-1')
  })

  it('does not push a protocol-relative return query', async () => {
    currentRoute.value = { query: { return: '//evil.example' }, meta: {}, fullPath: '/login' }

    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    expect(push).toHaveBeenCalledWith('/')
  })

  it('sends the TOTP code as a header rather than in the body', async () => {
    await auth.login({ email: 'ada@b10cks.test', password: 'secret' }, '123456')

    expect(post).toHaveBeenCalledWith('/auth/v1/token', expect.anything(), {
      headers: { 'x-totp-code': '123456' },
    })
  })

  it('asks for a second factor on 423 without surfacing an error', async () => {
    post.mockRejectedValue(apiError(423, { error_code: 'TOTP_VERIFICATION_REQUIRED' }))

    expect(await auth.login({ email: 'ada@b10cks.test', password: 'secret' })).toBe(false)

    expect(auth.requiresTwoFactor.value).toBe(true)
    expect(auth.error.value).toBeNull()
    expect(auth.user.value).toBeNull()
    expect(push).not.toHaveBeenCalled()
  })

  it.each([
    [403, 'INVALID_TOTP_CODE', "That code doesn't look right. Try again?"],
    [409, 'EMAIL_NOT_VERIFIED', 'Please verify your email before signing in.'],
  ])('translates %i %s into copy', async (status, errorCode, message) => {
    post.mockRejectedValue(apiError(status, { error_code: errorCode }))

    expect(await auth.login({ email: 'ada@b10cks.test', password: 'x' })).toBe(false)
    expect(auth.error.value).toBe(message)
  })

  it('surfaces the server message when there is one', async () => {
    post.mockRejectedValue(apiError(422, { message: 'These credentials do not match.' }))

    await auth.login({ email: 'ada@b10cks.test', password: 'x' })

    expect(auth.error.value).toBe('These credentials do not match.')
  })

  it('falls back to generic copy for a bare failure', async () => {
    post.mockRejectedValue({})

    await auth.login({ email: 'ada@b10cks.test', password: 'x' })

    expect(auth.error.value).toBe('Login failed. Please try again.')
  })

  it('clears a previous error when a new attempt starts', async () => {
    post.mockRejectedValueOnce(apiError(422, { message: 'Nope.' }))
    await auth.login({ email: 'ada@b10cks.test', password: 'x' })
    expect(auth.error.value).toBe('Nope.')

    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    expect(auth.error.value).toBeNull()
  })

  it('reports failure instead of navigating when the follow-up user request fails', async () => {
    // Credentials accepted but no session: navigating would only bounce off the
    // router guard, so the caller keeps the form up with an error instead.
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    request.mockRejectedValue(apiError(500))

    expect(await auth.login({ email: 'ada@b10cks.test', password: 'secret' })).toBe(false)

    expect(auth.isAuthenticated.value).toBe(false)
    expect(push).not.toHaveBeenCalled()
    expect(auth.error.value).toBe("Couldn't load your profile. Refresh the page?")
    expect(consoleError).toHaveBeenCalled()
  })

  it('resets isLoading after a failure', async () => {
    post.mockRejectedValue(apiError(422))

    await auth.login({ email: 'ada@b10cks.test', password: 'x' })

    expect(auth.isLoading.value).toBe(false)
  })
})

describe('verifyTwoFactorAndLogin', () => {
  it('replays the pending credentials with the code', async () => {
    post.mockRejectedValueOnce(apiError(423, { error_code: 'TOTP_VERIFICATION_REQUIRED' }))
    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    expect(await auth.verifyTwoFactorAndLogin('123456')).toBe(true)

    expect(post).toHaveBeenLastCalledWith(
      '/auth/v1/token',
      { email: 'ada@b10cks.test', password: 'secret' },
      { headers: { 'x-totp-code': '123456' } }
    )
    expect(auth.requiresTwoFactor.value).toBe(false)
  })

  it('refuses without a pending login', async () => {
    expect(await auth.verifyTwoFactorAndLogin('123456')).toBe(false)

    expect(auth.error.value).toBe('Login session expired. Please try again.')
    expect(post).not.toHaveBeenCalled()
  })

  it('keeps the pending payload after a wrong code, so the user can retry', async () => {
    post.mockRejectedValueOnce(apiError(423, { error_code: 'TOTP_VERIFICATION_REQUIRED' }))
    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    post.mockRejectedValueOnce(apiError(403, { error_code: 'INVALID_TOTP_CODE' }))
    expect(await auth.verifyTwoFactorAndLogin('000000')).toBe(false)
    expect(auth.error.value).toBe("That code doesn't look right. Try again?")

    expect(await auth.verifyTwoFactorAndLogin('123456')).toBe(true)
  })

  it('drops the pending payload when the user cancels', async () => {
    post.mockRejectedValueOnce(apiError(423, { error_code: 'TOTP_VERIFICATION_REQUIRED' }))
    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })

    auth.cancelTwoFactorLogin()

    expect(auth.requiresTwoFactor.value).toBe(false)
    expect(auth.error.value).toBeNull()
    expect(await auth.verifyTwoFactorAndLogin('123456')).toBe(false)
  })
})

describe('verifySocialTwoFactorAndLogin', () => {
  it('posts the code and completes the session', async () => {
    expect(await auth.verifySocialTwoFactorAndLogin('123456')).toBe(true)

    expect(ensureCsrfCookie).toHaveBeenCalled()
    expect(post).toHaveBeenCalledWith('/auth/v1/social/2fa', { code: '123456' })
    expect(auth.user.value).toEqual(user())
    expect(push).toHaveBeenCalledWith('/')
  })

  it.each([
    [403, 'INVALID_TOTP_CODE', "That code doesn't look right. Try again?"],
    [401, 'SOCIAL_LOGIN_SESSION_EXPIRED', 'Login session expired. Please try again.'],
  ])('translates %i %s into copy', async (status, errorCode, message) => {
    post.mockRejectedValue(apiError(status, { error_code: errorCode }))

    expect(await auth.verifySocialTwoFactorAndLogin('000000')).toBe(false)
    expect(auth.error.value).toBe(message)
  })

  it('falls back to generic copy', async () => {
    post.mockRejectedValue({})

    await auth.verifySocialTwoFactorAndLogin('000000')

    expect(auth.error.value).toBe('Login failed. Please try again.')
    expect(auth.isLoading.value).toBe(false)
  })
})

describe('forgotPassword', () => {
  it('requests the reset mail', async () => {
    expect(await auth.forgotPassword({ email: 'ada@b10cks.test' })).toBe(true)

    expect(ensureCsrfCookie).toHaveBeenCalled()
    expect(post).toHaveBeenCalledWith('/auth/v1/password/email', { email: 'ada@b10cks.test' })
  })

  it('surfaces the server message', async () => {
    post.mockRejectedValue(apiError(422, { message: 'Unknown email address.' }))

    expect(await auth.forgotPassword({ email: 'ada@b10cks.test' })).toBe(false)
    expect(auth.error.value).toBe('Unknown email address.')
  })

  it('falls back to the translated failure copy', async () => {
    post.mockRejectedValue({})

    await auth.forgotPassword({ email: 'ada@b10cks.test' })

    expect(auth.error.value).toBe("We couldn't send the reset link. Please try again.")
  })
})

describe('resetPassword', () => {
  const payload = {
    email: 'ada@b10cks.test',
    token: 'reset-token',
    password: 'secret',
    password_confirmation: 'secret',
  }

  it('posts the reset and sends the user back to login', async () => {
    expect(await auth.resetPassword(payload)).toBe(true)

    expect(post).toHaveBeenCalledWith('/auth/v1/password/reset', payload)
    expect(push).toHaveBeenCalledWith({
      name: 'login',
      query: { message: 'password_reset_success' },
    })
  })

  it('surfaces the server message', async () => {
    post.mockRejectedValue(apiError(422, { message: 'This token is invalid.' }))

    expect(await auth.resetPassword(payload)).toBe(false)
    expect(auth.error.value).toBe('This token is invalid.')
  })

  it.each([
    ['a 422', apiError(422)],
    ['any other failure', {}],
  ])('falls back to the translated failure copy for %s', async (_label, failure) => {
    post.mockRejectedValue(failure)

    await auth.resetPassword(payload)

    expect(auth.error.value).toBe("We couldn't reset your password. Please try again.")
  })
})

describe('register', () => {
  const payload = {
    email: 'ada@b10cks.test',
    password: 'secret',
    firstname: 'Ada',
    lastname: 'Lovelace',
  }

  it('registers, loads the user and redirects', async () => {
    expect(await auth.register(payload)).toBe(true)

    expect(post).toHaveBeenCalledWith('/auth/v1/register', payload)
    expect(auth.user.value).toEqual(user())
    expect(push).toHaveBeenCalledWith('/')
  })

  it('does not push a protocol-relative return query', async () => {
    currentRoute.value = { query: { return: '//evil.example' }, meta: {}, fullPath: '/login/signup' }

    await auth.register(payload)

    expect(push).toHaveBeenCalledWith('/')
  })

  it('reports a taken email address for a 409', async () => {
    post.mockRejectedValue(apiError(409))

    expect(await auth.register(payload)).toBe(false)
    expect(auth.error.value).toBe('An account with this email already exists.')
  })

  it('prefers the server message over the generic copy', async () => {
    post.mockRejectedValue(apiError(422, { message: 'The password is too short.' }))

    await auth.register(payload)

    expect(auth.error.value).toBe('The password is too short.')
  })

  it('falls back to generic copy', async () => {
    post.mockRejectedValue({})

    await auth.register(payload)

    expect(auth.error.value).toBe('Registration failed. Please try again.')
  })

  it('lets a 409 server message win over the taken-email copy', async () => {
    // A 409 is not always a taken email — an already accepted invite answers 409
    // too, and only the server knows which one it is.
    post.mockRejectedValue(apiError(409, { message: 'Invite already accepted.' }))

    await auth.register(payload)

    expect(auth.error.value).toBe('Invite already accepted.')
  })
})

describe('logout', () => {
  beforeEach(async () => {
    await auth.loadUser()
    currentRoute.value = { query: {}, meta: {}, fullPath: '/spaces/space-1/content' }
  })

  it('ends the session server-side, then locally', async () => {
    await auth.logout()

    expect(post).toHaveBeenCalledWith('/auth/v1/logout')
    expect(auth.user.value).toBeNull()
    expect(auth.isAuthenticated.value).toBe(false)
    expect(auth.sessionExpired.value).toBe(true)
    expect(auth.isReady.value).toBe(true)
  })

  it('cancels in-flight queries and drops every cached response', async () => {
    await auth.logout()

    expect(cancelQueries).toHaveBeenCalled()
    expect(clear).toHaveBeenCalled()
  })

  it('resets the posthog identity', async () => {
    await auth.logout()

    expect(reset).toHaveBeenCalled()
  })

  it('redirects to login with the current path as the return target', async () => {
    await auth.logout()

    expect(push).toHaveBeenCalledWith({
      name: 'login',
      query: { return: '/spaces/space-1/content' },
    })
  })

  it('prefers an explicit return path', async () => {
    await auth.logout({ returnPath: '/spaces/space-2' })

    expect(push).toHaveBeenCalledWith({ name: 'login', query: { return: '/spaces/space-2' } })
  })

  it('does not echo a protocol-relative return onto login', async () => {
    currentRoute.value = {
      query: { return: '//evil.example' },
      meta: {},
      fullPath: '/spaces/space-1/content',
    }

    await auth.logout()

    expect(push).toHaveBeenCalledWith({
      name: 'login',
      query: { return: '/' },
    })
  })

  it('skips the API call for an already expired session and flags the message', async () => {
    post.mockClear()

    await auth.logout({ expired: true })

    expect(post).not.toHaveBeenCalled()
    expect(push).toHaveBeenCalledWith({
      name: 'login',
      query: { return: '/spaces/space-1/content', message: 'session_expired' },
    })
  })

  it('clears local state even when the logout call fails', async () => {
    const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    post.mockRejectedValue(apiError(500))

    await auth.logout()

    expect(auth.user.value).toBeNull()
    expect(clear).toHaveBeenCalled()
    expect(consoleWarn).toHaveBeenCalled()
  })

  it.each([
    ['guest', { guest: true }],
    ['public', { public: true }],
  ])('stays put on a %s route', async (_label, meta) => {
    currentRoute.value = { query: {}, meta, fullPath: '/invite/abc' }

    await auth.logout()

    expect(auth.user.value).toBeNull()
    expect(clear).toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
  })

  it('drops a pending two-factor login', async () => {
    post.mockRejectedValueOnce(apiError(423, { error_code: 'TOTP_VERIFICATION_REQUIRED' }))
    await auth.login({ email: 'ada@b10cks.test', password: 'secret' })
    expect(auth.requiresTwoFactor.value).toBe(true)

    await auth.logout()

    expect(auth.requiresTwoFactor.value).toBe(false)
    expect(auth.error.value).toBeNull()
    expect(await auth.verifyTwoFactorAndLogin('123456')).toBe(false)
  })

  it('drops the persisted user-scoped browser state', async () => {
    // A shared browser: the next account must not start in the previous user's
    // language with their team selected.
    window.localStorage.setItem('global-team', '{"selectedTeamId":"team-1"}')
    window.localStorage.setItem('user-settings', '{"languageIso":"de"}')
    window.localStorage.setItem('space-space-1-settings', '{"blocks":{"pageSize":50}}')

    await auth.logout()

    expect(window.localStorage.getItem('global-team')).toBeNull()
    expect(window.localStorage.getItem('user-settings')).toBeNull()
    // Space-scoped UI preferences are not user-identifying and stay put.
    expect(window.localStorage.getItem('space-space-1-settings')).not.toBeNull()
  })
})

describe('handleUnauthorized', () => {
  it.each([
    '/auth/v1/token',
    '/auth/v1/register',
    '/auth/v1/logout',
    '/auth/v1/csrf-cookie',
    '/auth/v1/social/callback',
    '/auth/v1/password/reset',
    '/auth/v1/one-time-token/consume',
    '/auth/v1/email/verify',
  ])('leaves the session alone for %s — that caller owns its 401', async (endpoint) => {
    await auth.loadUser()

    expect(await auth.handleUnauthorized(endpoint)).toEqual({ retry: false })

    expect(auth.user.value).not.toBeNull()
    expect(push).not.toHaveBeenCalled()
    expect(clear).not.toHaveBeenCalled()
  })

  it('matches an unauthenticated endpoint anywhere in the URL', async () => {
    await auth.loadUser()

    await auth.handleUnauthorized('https://api.b10cks.test/auth/v1/token?foo=1')

    expect(auth.user.value).not.toBeNull()
  })

  it.each([
    ['guest', { guest: true }],
    ['public', { public: true }],
  ])('clears the user but never redirects on a %s route', async (_label, meta) => {
    await auth.loadUser()
    currentRoute.value = { query: {}, meta, fullPath: '/invite/abc' }

    expect(await auth.handleUnauthorized('/mgmt/v1/spaces')).toEqual({ retry: false })

    expect(auth.user.value).toBeNull()
    expect(push).not.toHaveBeenCalled()
    // The public route survives the 401, the expired session's cached responses
    // must not.
    expect(clear).toHaveBeenCalled()
  })

  it('only latches the expired flag when no user was ever loaded', async () => {
    expect(await auth.handleUnauthorized('/mgmt/v1/users/me')).toEqual({ retry: false })

    expect(auth.sessionExpired.value).toBe(true)
    expect(push).not.toHaveBeenCalled()
    expect(clear).not.toHaveBeenCalled()
  })

  it('tears the session down when a signed-in request 401s', async () => {
    await auth.loadUser()

    expect(await auth.handleUnauthorized('/mgmt/v1/spaces')).toEqual({ retry: false })

    expect(auth.user.value).toBeNull()
    expect(clear).toHaveBeenCalled()
    expect(push).toHaveBeenCalledWith({
      name: 'login',
      query: { return: '/', message: 'session_expired' },
    })
  })

  it('collapses concurrent 401s into a single teardown', async () => {
    await auth.loadUser()

    await Promise.all([
      auth.handleUnauthorized('/mgmt/v1/spaces'),
      auth.handleUnauthorized('/mgmt/v1/contents'),
      auth.handleUnauthorized('/mgmt/v1/assets'),
    ])

    expect(clear).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledTimes(1)
  })

  it('treats an empty endpoint as an authenticated one', async () => {
    await auth.loadUser()

    await auth.handleUnauthorized()

    expect(auth.user.value).toBeNull()
    expect(clear).toHaveBeenCalled()
  })
})

describe('initAuth', () => {
  it('loads the user and reports the session ready', async () => {
    await auth.initAuth()

    expect(request).toHaveBeenCalledTimes(1)
    expect(auth.user.value).toEqual(user())
    expect(auth.isReady.value).toBe(true)
  })

  it('does not re-probe once a user is loaded', async () => {
    await auth.initAuth()
    await auth.initAuth()

    expect(request).toHaveBeenCalledTimes(1)
  })

  it('refetches while no user is loaded and the session has not expired', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    request.mockRejectedValue(apiError(500))

    await auth.initAuth()
    await auth.initAuth()

    expect(request).toHaveBeenCalledTimes(2)
    expect(consoleError).toHaveBeenCalled()
  })

  it('stops probing once the session is known to be gone', async () => {
    request.mockRejectedValue(apiError(401))
    await auth.initAuth()
    // The 401 probe latched sessionExpired via handleUnauthorized in the real
    // client; here the latch is set by a logout of a loaded user.
    request.mockResolvedValue({ data: user() })
    await auth.loadUser(true)
    await auth.logout()
    request.mockClear()

    await auth.initAuth()

    expect(request).not.toHaveBeenCalled()
    expect(auth.isReady.value).toBe(true)
  })

  it('does not start a second probe while one is in flight', async () => {
    let resolveRequest: ((value: unknown) => void) | undefined
    request.mockReturnValue(
      new Promise((resolve) => {
        resolveRequest = resolve as (value: unknown) => void
      })
    )

    const first = auth.initAuth()
    await vi.waitUntil(() => request.mock.calls.length === 1)
    const second = auth.initAuth()

    expect(request).toHaveBeenCalledTimes(1)

    resolveRequest?.({ data: user() })
    await Promise.all([first, second])

    expect(auth.user.value).toEqual(user())
  })

  it('resolves a second initAuth only after the in-flight probe finishes', async () => {
    let resolveRequest: ((value: unknown) => void) | undefined
    request.mockReturnValue(
      new Promise((resolve) => {
        resolveRequest = resolve as (value: unknown) => void
      })
    )

    const first = auth.initAuth()
    await vi.waitUntil(() => request.mock.calls.length === 1)

    let secondSettled = false
    const second = auth.initAuth().then(() => {
      secondSettled = true
    })

    await Promise.resolve()
    expect(secondSettled).toBe(false)
    expect(auth.isReady.value).toBe(false)

    resolveRequest?.({ data: user() })
    await Promise.all([first, second])

    expect(secondSettled).toBe(true)
    expect(auth.isReady.value).toBe(true)
    expect(request).toHaveBeenCalledTimes(1)
  })
})

describe('setUser', () => {
  it('replaces the user without touching the server', async () => {
    auth.setUser(user({ firstname: 'Grace' }))

    expect(auth.user.value?.firstname).toBe('Grace')
    expect(auth.isAuthenticated.value).toBe(true)
    expect(request).not.toHaveBeenCalled()
  })

  it('signs the user out locally and drops the cached responses', async () => {
    await auth.loadUser()

    auth.setUser(null)
    // The cache teardown is behind a lazy import of the query plugin.
    await vi.waitUntil(() => clear.mock.calls.length > 0)

    expect(auth.isAuthenticated.value).toBe(false)
    // No server call and no redirect — those belong to logout(), which knows
    // whether the current route survives a sign-out.
    expect(post).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
  })
})
