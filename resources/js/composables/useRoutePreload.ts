import type { RouteLocationRaw } from 'vue-router'

import { loadRouteComponents } from '~/lib/route-components'

import { useHoverPrefetch } from './useHoverPrefetch'

/**
 * Preloads the lazy component chunks of a route on hover/focus intent, so the
 * JS is already in the module cache when the user actually navigates.
 *
 * Built on `useHoverPrefetch`: the load fires after the pointer (or focus) has
 * rested on the link for `delay` ms and is deduped per resolved route, so
 * repeat hovers are free.
 *
 * Usage:
 *   const { preloadRoute, cancelPreload } = useRoutePreload()
 *   <RouterLink :to="to" @mouseenter="preloadRoute(to)" @focusin="preloadRoute(to)"
 *               @mouseleave="cancelPreload(to)" @focusout="cancelPreload(to)" />
 */
export function useRoutePreload(delay = 150) {
  const router = useRouter()

  // Rejections bubble to useHoverPrefetch, which swallows them and clears the
  // dedupe entry so a failed chunk load can be retried on re-hover.
  const loadRouteChunks = (key: string) => loadRouteComponents(router.resolve(key).matched)

  const { start, cancel } = useHoverPrefetch(loadRouteChunks, delay)

  // Dedupe on the resolved path: stable across re-renders even when templates
  // build a fresh location object per hover.
  const toKey = (to: RouteLocationRaw) => router.resolve(to).fullPath

  /** Schedule the chunk preload for `to`; call from mouseenter/focusin. */
  const preloadRoute = (to: RouteLocationRaw) => start(toKey(to))

  /** Cancel the pending preload for `to`, or all when omitted; call from mouseleave/focusout. */
  const cancelPreload = (to?: RouteLocationRaw) => cancel(to === undefined ? undefined : toKey(to))

  return { preloadRoute, cancelPreload }
}
