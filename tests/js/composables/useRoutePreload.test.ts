import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, type EffectScope } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

interface FakeRecord {
  components?: Record<string, unknown> | null
}

const resolve = vi.fn<(to: RouteLocationRaw) => { fullPath: string; matched: FakeRecord[] }>()

// The router is the boundary here: resolution and the lazy component loaders it
// hands back are what the composable drives.
vi.mock('vue-router', () => ({ useRouter: () => ({ resolve }) }))

const { useRoutePreload } = await import('~/composables/useRoutePreload')

const scopes: EffectScope[] = []

const run = (delay?: number) => {
  const scope = effectScope()
  scopes.push(scope)
  const api = scope.run(() => useRoutePreload(delay))

  return { ...(api as ReturnType<typeof useRoutePreload>), scope }
}

/** Resolve every location to `fullPath`, with the given matched records. */
const routes = (map: Record<string, FakeRecord[]>) => {
  resolve.mockImplementation((to) => {
    const fullPath = typeof to === 'string' ? to : String((to as { path?: string }).path)
    return { fullPath, matched: map[fullPath] ?? [] }
  })
}

beforeEach(() => {
  vi.useFakeTimers()
  resolve.mockReset()
})

afterEach(() => {
  scopes.splice(0).forEach((scope) => scope.stop())
  vi.useRealTimers()
})

describe('preloadRoute', () => {
  it('calls every lazy component loader of the matched records after the delay', () => {
    const parent = vi.fn().mockResolvedValue({})
    const child = vi.fn().mockResolvedValue({})
    const sidebar = vi.fn().mockResolvedValue({})
    routes({
      '/spaces/s1/assets': [
        { components: { default: parent } },
        { components: { default: child, sidebar } },
      ],
    })

    const { preloadRoute } = run()
    preloadRoute({ path: '/spaces/s1/assets' })

    expect(parent).not.toHaveBeenCalled()

    vi.advanceTimersByTime(150)

    expect(parent).toHaveBeenCalledTimes(1)
    expect(child).toHaveBeenCalledTimes(1)
    expect(sidebar).toHaveBeenCalledTimes(1)
  })

  it('skips eagerly imported components', () => {
    const lazy = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: { name: 'Eager' }, aside: lazy } }] })

    run().preloadRoute({ path: '/a' })
    vi.advanceTimersByTime(150)

    expect(lazy).toHaveBeenCalledTimes(1)
  })

  it.each([[{ components: undefined }], [{ components: null }], [{ components: {} }]])(
    'tolerates a record without components',
    (record) => {
      routes({ '/a': [record] })

      const { preloadRoute } = run()

      expect(() => {
        preloadRoute({ path: '/a' })
        vi.advanceTimersByTime(150)
      }).not.toThrow()
    }
  )

  it('tolerates a route that matches nothing', () => {
    routes({})

    const { preloadRoute } = run()

    expect(() => {
      preloadRoute({ path: '/unknown' })
      vi.advanceTimersByTime(150)
    }).not.toThrow()
  })

  it('honours a custom delay', () => {
    const loader = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    run(400).preloadRoute({ path: '/a' })

    vi.advanceTimersByTime(399)
    expect(loader).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(loader).toHaveBeenCalledTimes(1)
  })

  it('dedupes on the resolved path, not the location object', () => {
    const loader = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    const { preloadRoute } = run()

    // A template builds a fresh object per hover; both resolve to /a.
    preloadRoute({ path: '/a' })
    vi.advanceTimersByTime(150)
    preloadRoute({ path: '/a' })
    vi.advanceTimersByTime(150)

    expect(loader).toHaveBeenCalledTimes(1)
  })

  it('preloads distinct routes independently', () => {
    const a = vi.fn().mockResolvedValue({})
    const b = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: a } }], '/b': [{ components: { default: b } }] })

    const { preloadRoute } = run()
    preloadRoute({ path: '/a' })
    preloadRoute({ path: '/b' })
    vi.advanceTimersByTime(150)

    expect(a).toHaveBeenCalledTimes(1)
    expect(b).toHaveBeenCalledTimes(1)
  })

  it('retries after a failed chunk load', async () => {
    const loader = vi
      .fn()
      .mockRejectedValueOnce(new Error('chunk load failed'))
      .mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    const { preloadRoute } = run()

    preloadRoute({ path: '/a' })
    await vi.advanceTimersByTimeAsync(150)

    preloadRoute({ path: '/a' })
    await vi.advanceTimersByTimeAsync(150)

    expect(loader).toHaveBeenCalledTimes(2)
  })

  it('resolves the location twice: once for the key, once for the chunks', () => {
    routes({ '/a': [{ components: {} }] })

    const { preloadRoute } = run()
    preloadRoute({ path: '/a' })
    vi.advanceTimersByTime(150)

    // The second call is made with the fullPath string rather than the original
    // location — extra work, but it keeps the dedupe key stable.
    expect(resolve.mock.calls).toEqual([[{ path: '/a' }], ['/a']])
  })
})

describe('cancelPreload', () => {
  it('cancels a pending preload for one route', () => {
    const a = vi.fn().mockResolvedValue({})
    const b = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: a } }], '/b': [{ components: { default: b } }] })

    const { preloadRoute, cancelPreload } = run()
    preloadRoute({ path: '/a' })
    preloadRoute({ path: '/b' })
    cancelPreload({ path: '/a' })
    vi.advanceTimersByTime(150)

    expect(a).not.toHaveBeenCalled()
    expect(b).toHaveBeenCalledTimes(1)
  })

  it('cancels everything when called without a route', () => {
    const loader = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    const { preloadRoute, cancelPreload } = run()
    preloadRoute({ path: '/a' })
    cancelPreload()
    vi.advanceTimersByTime(150)

    expect(loader).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('cancels by resolved path, so a different object for the same route works', () => {
    const loader = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    const { preloadRoute, cancelPreload } = run()
    preloadRoute({ path: '/a' })
    cancelPreload({ path: '/a' })
    vi.advanceTimersByTime(150)

    expect(loader).not.toHaveBeenCalled()
  })

  it('allows a new preload after cancelling', () => {
    const loader = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    const { preloadRoute, cancelPreload } = run()
    preloadRoute({ path: '/a' })
    cancelPreload({ path: '/a' })
    preloadRoute({ path: '/a' })
    vi.advanceTimersByTime(150)

    expect(loader).toHaveBeenCalledTimes(1)
  })
})

describe('scope disposal', () => {
  it('drops pending preloads so no chunk loads after teardown', () => {
    const loader = vi.fn().mockResolvedValue({})
    routes({ '/a': [{ components: { default: loader } }] })

    const { preloadRoute, scope } = run()
    preloadRoute({ path: '/a' })
    scope.stop()
    vi.advanceTimersByTime(1000)

    expect(loader).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })
})
