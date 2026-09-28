import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'

const config = vi.hoisted(() => ({
  public: { posthog: { key: undefined as string | undefined, host: 'https://events.test' } },
}))
const sdk = vi.hoisted(() => ({
  load: vi.fn<() => Promise<void>>(),
  client: {
    init: vi.fn(),
    identify: vi.fn(),
    reset: vi.fn(),
    captureException: vi.fn(),
  },
}))

vi.mock('~/lib/runtime-config', () => ({ runtimeConfig: config }))
beforeEach(() => {
  vi.resetModules()
  vi.clearAllMocks()
  sdk.load.mockResolvedValue(undefined)
  config.public.posthog.key = 'project-key'
  vi.doMock('posthog-js', async () => {
    await sdk.load()
    return { default: sdk.client }
  })
})

afterEach(() => vi.restoreAllMocks())

describe('optional analytics', () => {
  it('does not load the SDK or alter error handling without a key', async () => {
    config.public.posthog.key = undefined
    const { installPosthog, getPosthog } = await import('~/plugins/posthog')
    const app = createApp({})
    const handler = vi.fn()
    app.config.errorHandler = handler

    getPosthog().identify('user')
    getPosthog().reset()
    await installPosthog(app)

    expect(sdk.load).not.toHaveBeenCalled()
    expect(sdk.client.init).not.toHaveBeenCalled()
    expect(app.config.errorHandler).toBe(handler)
  })

  it('keeps identification, logout and Vue errors in order while loading', async () => {
    let finishLoading: () => void = () => {}
    sdk.load.mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          finishLoading = resolve
        })
    )
    const { installPosthog, getPosthog } = await import('~/plugins/posthog')
    const app = createApp({})
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const addListener = vi.spyOn(window, 'addEventListener')
    const removeListener = vi.spyOn(window, 'removeEventListener')

    getPosthog().identify('first-user', { email: 'first@test.local' })
    const installation = installPosthog(app)
    getPosthog().reset()
    getPosthog().identify('second-user')
    const error = new Error('render failed')
    app.config.errorHandler?.(error, null, 'render function')

    await vi.waitFor(() => expect(sdk.load).toHaveBeenCalledOnce())
    expect(sdk.client.identify).not.toHaveBeenCalled()
    finishLoading()
    await installation

    expect(sdk.client.init).toHaveBeenCalledWith(
      'project-key',
      expect.objectContaining({
        api_host: 'https://events.test',
        capture_pageview: 'history_change',
        capture_exceptions: true,
        mask_all_text: true,
      })
    )
    expect(sdk.client.identify.mock.calls).toEqual([
      ['first-user', { email: 'first@test.local' }],
      ['second-user'],
    ])
    expect(sdk.client.identify.mock.invocationCallOrder[0]).toBeLessThan(
      sdk.client.reset.mock.invocationCallOrder[0]
    )
    expect(sdk.client.reset.mock.invocationCallOrder[0]).toBeLessThan(
      sdk.client.identify.mock.invocationCallOrder[1]
    )
    expect(sdk.client.captureException).toHaveBeenCalledWith(error, {
      $exception_source: 'vue',
      vue_component: undefined,
      vue_lifecycle_hook: 'render function',
    })
    expect(addListener).toHaveBeenCalledWith('error', expect.any(Function))
    expect(addListener).toHaveBeenCalledWith('unhandledrejection', expect.any(Function))
    expect(removeListener).toHaveBeenCalledWith('error', expect.any(Function))
    expect(removeListener).toHaveBeenCalledWith('unhandledrejection', expect.any(Function))
    expect(sdk.client.captureException).toHaveBeenCalledTimes(1)

    getPosthog().reset()
    expect(sdk.client.reset).toHaveBeenCalledTimes(2)
  })

  it('does not make authentication depend on a failed SDK download', async () => {
    const error = new Error('chunk unavailable')
    sdk.load.mockRejectedValue(error)
    const { installPosthog, getPosthog } = await import('~/plugins/posthog')
    getPosthog().identify('user')

    await expect(installPosthog(createApp({}))).rejects.toThrow()
    expect(() => getPosthog().reset()).not.toThrow()
    expect(sdk.client.identify).not.toHaveBeenCalled()
    expect(sdk.client.reset).not.toHaveBeenCalled()
  })
})
