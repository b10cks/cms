import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const error = vi.fn()
const push = vi.fn()
const route = { params: {} as Record<string, unknown> }
const billing = { enabled: true }

vi.mock('vue-sonner', () => ({ toast: { error } }))
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => ({ push }) }))

vi.mock('~/lib/runtime-config', async () => {
  const actual =
    await vi.importActual<typeof import('~/lib/runtime-config')>('~/lib/runtime-config')

  return {
    runtimeConfig: {
      public: {
        ...actual.runtimeConfig.public,
        features: {
          ...actual.runtimeConfig.public.features,
          get billing() {
            return billing.enabled
          },
        },
      },
    },
  }
})

const { useAiErrorToast } = await import('~/composables/useAiErrorToast')

const PLAN_MESSAGE = "Your current plan doesn't include AI features. Upgrade your plan to use them."
const GENERIC_MESSAGE = 'Something went wrong with the AI request. Please try again.'

let showAiError: ReturnType<typeof useAiErrorToast>['showAiError']

beforeEach(() => {
  error.mockReset()
  push.mockReset()
  route.params = { space: 'space-1' }
  billing.enabled = true
  showAiError = useAiErrorToast().showAiError
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('known reasons', () => {
  it.each([
    ['not_configured', 'This space has no AI configuration yet. Add one in the space AI settings to use AI features.'],
    ['provider_unavailable', 'The AI provider is currently unavailable. Please try again later.'],
    ['not_provisioned', 'Your AI access is still being set up. Please try again in a moment.'],
    ['no_result', "The AI service didn't return a usable result. Please try again."],
    ['csrf', 'Your session has expired. Please refresh the page and try again.'],
    ['generic', GENERIC_MESSAGE],
  ])('shows the localised message for %s with no action', (reason, message) => {
    showAiError(reason)

    expect(error).toHaveBeenCalledWith(message)
  })

  it('prefers the localised message over a supplied fallback', () => {
    showAiError('csrf', 'raw backend text')

    expect(error).toHaveBeenCalledWith('Your session has expired. Please refresh the page and try again.')
  })
})

describe('unknown reasons', () => {
  it.each([undefined, null, '', 'something_new'])('uses the fallback for %s', (reason) => {
    showAiError(reason, 'The model refused.')

    expect(error).toHaveBeenCalledWith('The model refused.')
  })

  it.each([undefined, null, '', 'something_new'])(
    'uses the generic message for %s without a fallback',
    (reason) => {
      showAiError(reason)

      expect(error).toHaveBeenCalledWith(GENERIC_MESSAGE)
    }
  )

  it('treats an empty fallback as no fallback', () => {
    showAiError('something_new', '')

    expect(error).toHaveBeenCalledWith(GENERIC_MESSAGE)
  })
})

describe('plan errors', () => {
  it('offers an upgrade shortcut for the current space', () => {
    showAiError('plan_excluded')

    expect(error).toHaveBeenCalledWith(PLAN_MESSAGE, {
      action: { label: 'View plans', onClick: expect.any(Function) },
    })
  })

  it('navigates to the space subscription page when the action is clicked', () => {
    showAiError('plan_excluded')

    const [, options] = error.mock.calls[0] as [string, { action: { onClick: () => void } }]
    options.action.onClick()

    expect(push).toHaveBeenCalledWith({
      name: 'space-settings-subscription',
      params: { space: 'space-1' },
    })
  })

  it('offers no action outside a space route', () => {
    route.params = {}

    showAiError('plan_excluded')

    expect(error).toHaveBeenCalledWith(PLAN_MESSAGE, undefined)
  })

  it('offers no action when billing is disabled', () => {
    billing.enabled = false

    showAiError('plan_excluded')

    expect(error).toHaveBeenCalledWith(PLAN_MESSAGE)
    expect(push).not.toHaveBeenCalled()
  })

  it('shows exactly one toast per call', () => {
    showAiError('plan_excluded')

    expect(error).toHaveBeenCalledTimes(1)
  })
})
