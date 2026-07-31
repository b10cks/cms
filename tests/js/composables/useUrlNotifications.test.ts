import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { withSetup, type Harness } from '../support/harness'

type Query = Record<string, string | string[] | null | undefined>

const route = reactive({
  name: 'space-settings' as string | undefined,
  params: { space: 'space-1' } as Record<string, string>,
  query: {} as Query,
  hash: '',
})

const replace = vi.fn(async (to: { query?: Query }) => {
  route.query = { ...to.query }
})

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')
  return { ...actual, useRoute: () => route, useRouter: () => ({ replace }) }
})

const success = vi.fn()
const error = vi.fn()
const info = vi.fn()
const warning = vi.fn()
const message = vi.fn()
vi.mock('vue-sonner', () => {
  const toast = Object.assign(message, { success, error, info, warning })
  return { toast }
})

type Module = typeof import('~/composables/useUrlNotifications')

/**
 * `consumedNotifications` is module-scoped and never reset, by design: a value
 * already toasted in this page session must not fire again. Each test therefore
 * re-executes the module so it starts from an empty set — only inlined source is
 * re-run, so Vue itself stays the same instance and the harness keeps working.
 */
const load = async (): Promise<Module['useUrlNotifications']> => {
  vi.resetModules()
  const module = await import('~/composables/useUrlNotifications')
  return module.useUrlNotifications
}

type Api = ReturnType<Module['useUrlNotifications']>

let harness: Harness<Api> | undefined

const mount = async (query: Query) => {
  route.query = { ...query }
  const useUrlNotifications = await load()
  harness = withSetup<Api>(() => useUrlNotifications())
  await nextTick()
  return harness.result
}

beforeEach(() => {
  for (const fn of [success, error, info, warning, message, replace]) fn.mockClear()
  route.name = 'space-settings'
  route.params = { space: 'space-1' }
  route.query = {}
  route.hash = ''
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('parsing', () => {
  it('resolves a known notification value out of the query', async () => {
    // Hold the URL still: `notifications` mirrors the *current* query, so the
    // real replace() would have emptied it again before the assertion.
    replace.mockImplementationOnce(async () => {})
    const api = await mount({ notification: 'email_verified' })

    expect(api.notifications.value).toEqual([
      {
        param: 'notification',
        value: 'email_verified',
        level: 'success',
        translationKey: 'labels.notifications.url.email_verified',
      },
    ])
  })

  it('resolves nothing when the param is absent', async () => {
    const api = await mount({})

    expect(api.notifications.value).toEqual([])
    expect(success).not.toHaveBeenCalled()
  })

  it('ignores an unknown value', async () => {
    const api = await mount({ notification: 'not_a_thing' })

    expect(api.notifications.value).toEqual([])
    expect(message).not.toHaveBeenCalled()
  })

  it('ignores an empty value', async () => {
    const api = await mount({ notification: '' })

    expect(api.notifications.value).toEqual([])
  })

  it('ignores a repeated param, which Vue Router hands over as an array', async () => {
    const api = await mount({ notification: ['email_verified', 'payment_failed'] })

    expect(api.notifications.value).toEqual([])
    expect(success).not.toHaveBeenCalled()
  })

  it('ignores a valueless param', async () => {
    const api = await mount({ notification: null })

    expect(api.notifications.value).toEqual([])
  })

  it('ignores an unrelated query param', async () => {
    const api = await mount({ page: '2', notification: 'nope' })

    expect(api.notifications.value).toEqual([])
    expect(replace).not.toHaveBeenCalled()
  })

  it('is case sensitive about the value', async () => {
    const api = await mount({ notification: 'EMAIL_VERIFIED' })

    expect(api.notifications.value).toEqual([])
  })
})

describe('toast level', () => {
  it.each([
    ['email_verified', 'Your email address has been verified successfully.'],
    ['payment_success', 'Your payment was completed successfully.'],
  ])('%s shows a success toast', async (value, copy) => {
    await mount({ notification: value })

    expect(success).toHaveBeenCalledWith(copy)
  })

  it('payment_pending shows an info toast', async () => {
    await mount({ notification: 'payment_pending' })

    expect(info).toHaveBeenCalledWith('Your payment is still pending.')
  })

  it('payment_failed shows an error toast', async () => {
    await mount({ notification: 'payment_failed' })

    expect(error).toHaveBeenCalledWith('Your payment failed. Please try again.')
  })

  it('payment_cancelled shows a warning toast', async () => {
    await mount({ notification: 'payment_cancelled' })

    expect(warning).toHaveBeenCalledWith('Your payment was cancelled.')
  })

  it('shows exactly one toast, on one level only', async () => {
    await mount({ notification: 'payment_failed' })

    expect(error).toHaveBeenCalledTimes(1)
    for (const fn of [success, info, warning, message]) expect(fn).not.toHaveBeenCalled()
  })
})

describe('clearing the param', () => {
  it('strips the consumed param so a refresh cannot re-fire the toast', async () => {
    await mount({ notification: 'email_verified' })

    expect(replace).toHaveBeenCalledTimes(1)
    expect(replace.mock.calls[0][0]).toEqual({
      name: 'space-settings',
      params: { space: 'space-1' },
      query: {},
      hash: '',
    })
    expect(route.query).toEqual({})
  })

  it('keeps the other query params intact', async () => {
    await mount({ notification: 'payment_success', page: '2', tab: 'billing' })

    expect(replace.mock.calls[0][0]).toMatchObject({ query: { page: '2', tab: 'billing' } })
    expect(route.query).toEqual({ page: '2', tab: 'billing' })
  })

  it('preserves the hash and the route params', async () => {
    route.hash = '#plans'
    await mount({ notification: 'payment_pending' })

    expect(replace.mock.calls[0][0]).toMatchObject({
      hash: '#plans',
      params: { space: 'space-1' },
    })
  })

  it('passes undefined rather than null when the route has no name', async () => {
    route.name = undefined
    await mount({ notification: 'email_verified' })

    expect(replace.mock.calls[0][0]).toMatchObject({ name: undefined })
  })

  it('does not navigate when there is nothing to consume', async () => {
    await mount({ notification: 'garbage' })

    expect(replace).not.toHaveBeenCalled()
  })

  it('leaves an unrecognised param in the URL', async () => {
    await mount({ notification: 'garbage', page: '2' })

    expect(route.query).toEqual({ notification: 'garbage', page: '2' })
  })
})

describe('re-firing', () => {
  it('does not toast the same value twice in one page session', async () => {
    const api = await mount({ notification: 'email_verified' })

    route.query = { notification: 'email_verified' }
    await nextTick()
    await api.consume()

    expect(success).toHaveBeenCalledTimes(1)
    expect(api.pendingNotifications.value).toEqual([])
  })

  it('still resolves a consumed value, it just stops being pending', async () => {
    const api = await mount({ notification: 'email_verified' })

    route.query = { notification: 'email_verified' }
    await nextTick()

    expect(api.notifications.value).toHaveLength(1)
    expect(api.pendingNotifications.value).toEqual([])
  })

  it('toasts a different value that arrives later', async () => {
    await mount({ notification: 'email_verified' })

    route.query = { notification: 'payment_failed' }
    await nextTick()
    await nextTick()

    expect(success).toHaveBeenCalledTimes(1)
    expect(error).toHaveBeenCalledWith('Your payment failed. Please try again.')
  })

  /**
   * The consumed set is shared across every caller, so a second component
   * mounting the composable in the same session sees the value as already
   * consumed even though it never toasted it itself.
   */
  it('shares the consumed set across callers of the same module instance', async () => {
    const useUrlNotifications = await load()
    route.query = { notification: 'payment_success' }

    const first = withSetup<Api>(() => useUrlNotifications())
    await nextTick()
    route.query = { notification: 'payment_success' }
    const second = withSetup<Api>(() => useUrlNotifications())
    await nextTick()

    expect(success).toHaveBeenCalledTimes(1)
    expect(second.result.pendingNotifications.value).toEqual([])
    first.unmount()
    second.unmount()
  })

  it('consume() is idempotent when called by hand', async () => {
    const api = await mount({ notification: 'payment_cancelled' })

    await api.consume()
    await api.consume()

    expect(warning).toHaveBeenCalledTimes(1)
    expect(replace).toHaveBeenCalledTimes(1)
  })
})

describe('reacting to navigation', () => {
  it('toasts a value that only appears after mount', async () => {
    await mount({})

    expect(success).not.toHaveBeenCalled()

    route.query = { notification: 'email_verified' }
    await nextTick()
    await nextTick()

    expect(success).toHaveBeenCalledWith('Your email address has been verified successfully.')
    expect(route.query).toEqual({})
  })

  it('does not loop: stripping the param settles after one replace', async () => {
    await mount({ notification: 'payment_success' })

    await nextTick()
    await nextTick()

    expect(replace).toHaveBeenCalledTimes(1)
  })
})
