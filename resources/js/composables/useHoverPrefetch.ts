/**
 * Hover-intent prefetching: schedules `prefetch(payload)` after the pointer
 * (or focus) has rested on an element for `delay` ms, and cancels the pending
 * call when it leaves early. Successful prefetches are deduped per key, so
 * re-hovering an item never fires the request twice.
 *
 * Usage:
 *   const { start, cancel } = useHoverPrefetch((id: string) => prefetchDetail(id))
 *   <div @mouseenter="start(id)" @mouseleave="cancel(id)" @focusin="start(id)" @focusout="cancel(id)" />
 */
export function useHoverPrefetch<TPayload>(
  prefetch: (payload: TPayload) => void | Promise<unknown>,
  delay = 150
) {
  const timers = new Map<TPayload, ReturnType<typeof setTimeout>>()
  const done = new Set<TPayload>()

  /** Schedule the prefetch for `payload`; call from mouseenter/focus. */
  const start = (payload: TPayload) => {
    if (done.has(payload) || timers.has(payload)) {
      return
    }

    timers.set(
      payload,
      setTimeout(() => {
        timers.delete(payload)
        done.add(payload)
        // Swallow rejections so a failed prefetch never surfaces; the real
        // query will simply fetch on demand (and may retry) later.
        void Promise.resolve(prefetch(payload)).catch(() => {
          done.delete(payload)
        })
      }, delay)
    )
  }

  /** Cancel the pending prefetch for `payload`, or all when omitted; call from mouseleave/blur. */
  const cancel = (payload?: TPayload) => {
    if (payload === undefined) {
      timers.forEach((timer) => clearTimeout(timer))
      timers.clear()
      return
    }

    const timer = timers.get(payload)
    if (timer !== undefined) {
      clearTimeout(timer)
      timers.delete(payload)
    }
  }

  onScopeDispose(() => cancel())

  return { start, cancel }
}
