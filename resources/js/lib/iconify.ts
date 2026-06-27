/**
 * Thin client for the public Iconify API (https://iconify.design/docs/api/).
 * Centralises the host, request shapes and name parsing that were previously
 * duplicated across `useIconifyCollections` and `IconifyPicker`.
 */
export const ICONIFY_HOST = 'https://api.iconify.design'

export interface IconifyCollectionMeta {
  prefix: string
  name: string
  total: number
  category: string | null
}

/** Catalogue of every public collection (e.g. `mdi`, `lucide`, `tabler`), sorted by name. */
export async function fetchIconifyCollections(): Promise<IconifyCollectionMeta[]> {
  const response = await fetch(`${ICONIFY_HOST}/collections`)
  if (!response.ok) {
    throw new Error(`Iconify collections request failed: ${response.status}`)
  }

  const data = (await response.json()) as Record<
    string,
    { name?: string; total?: number; category?: string }
  >

  return Object.entries(data)
    .map(([prefix, meta]) => ({
      prefix,
      name: meta?.name || prefix,
      total: typeof meta?.total === 'number' ? meta.total : 0,
      category: meta?.category || null,
    }))
    .sort((a, b) => a.name.localeCompare(b.name))
}

// Full name lists per collection prefix are cached for the session so switching
// chips (or re-opening the picker) is instant.
const collectionCache = new Map<string, string[]>()

/** Every fully-qualified icon name (e.g. `mdi:home`) in a single collection. */
export async function fetchIconifyCollection(
  prefix: string,
  signal?: AbortSignal
): Promise<string[]> {
  const cached = collectionCache.get(prefix)
  if (cached) return cached

  const response = await fetch(`${ICONIFY_HOST}/collection?prefix=${encodeURIComponent(prefix)}`, {
    signal,
  })
  const data = await response.json()

  const names: string[] = []
  if (Array.isArray(data.uncategorized)) names.push(...data.uncategorized)
  if (data.categories && typeof data.categories === 'object') {
    for (const value of Object.values(data.categories)) {
      if (Array.isArray(value)) names.push(...(value as string[]))
    }
  }

  const full = names.map((name) => `${prefix}:${name}`)
  collectionCache.set(prefix, full)
  return full
}

export interface IconifySearchScope {
  prefix?: string
  prefixes?: string[]
  limit?: number
}

/** Full-text icon search, optionally scoped to one prefix or a set of prefixes. */
export async function searchIconifyIcons(
  query: string,
  scope: IconifySearchScope = {},
  signal?: AbortSignal
): Promise<{ icons: string[]; total: number }> {
  const params = new URLSearchParams({ query, limit: String(scope.limit ?? 120) })
  if (scope.prefix) params.set('prefix', scope.prefix)
  else if (scope.prefixes?.length) params.set('prefixes', scope.prefixes.join(','))

  const response = await fetch(`${ICONIFY_HOST}/search?${params.toString()}`, { signal })
  const data = await response.json()

  const icons = Array.isArray(data.icons) ? data.icons : []
  return { icons, total: typeof data.total === 'number' ? data.total : icons.length }
}

/** Splits a fully-qualified icon name into its collection prefix and bare name. */
export function splitIconName(full: string): { prefix: string | null; name: string } {
  const index = full.indexOf(':')
  return index === -1
    ? { prefix: null, name: full }
    : { prefix: full.slice(0, index), name: full.slice(index + 1) }
}
