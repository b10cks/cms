const RELOADED_AT_KEY = 'b10cks:stale-chunk-reload'
const RELOAD_COOLDOWN_MS = 10_000

/**
 * A deploy replaces the hashed chunks, so a tab still on the old build fails its
 * next lazy import. Reload once to pick up the new build. The error still
 * propagates, so async components show their error state if the reload is
 * cancelled (e.g. by the unsaved-changes prompt). The cooldown stops a reload
 * loop when a chunk is broken rather than stale.
 */
export function reloadOnStaleChunks(reload = () => window.location.reload()): void {
  window.addEventListener('vite:preloadError', () => {
    const now = Date.now()
    if (now - Number(sessionStorage.getItem(RELOADED_AT_KEY) ?? 0) < RELOAD_COOLDOWN_MS) {
      return
    }

    sessionStorage.setItem(RELOADED_AT_KEY, String(now))
    reload()
  })
}
