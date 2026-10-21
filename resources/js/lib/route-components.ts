import type { RouteRecordNormalized } from 'vue-router'

/**
 * Starts the lazy component chunks of the matched records. The router later
 * calls the same `import()`, which the module cache dedupes. Every route here
 * declares its components as `() => import(...)`, so functions are loaders.
 */
export function loadRouteComponents(matched: readonly RouteRecordNormalized[]): Promise<unknown[]> {
  const loaders: Promise<unknown>[] = []

  for (const record of matched) {
    for (const component of Object.values(record.components ?? {})) {
      if (typeof component === 'function') {
        loaders.push((component as () => Promise<unknown>)())
      }
    }
  }

  return Promise.all(loaders)
}
