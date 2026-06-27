import { ref } from 'vue'

export interface IconifyCollectionOption {
  prefix: string
  name: string
  total: number
  category: string | null
}

const ICONIFY_HOST = 'https://api.iconify.design'

// Module-level cache so the (large, rarely-changing) collection list is fetched once per session
// and shared across every icon-field config that mounts.
let cache: IconifyCollectionOption[] | null = null
let inflight: Promise<IconifyCollectionOption[]> | null = null

const fetchCollections = async (): Promise<IconifyCollectionOption[]> => {
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

/**
 * Loads the catalogue of public Iconify collections (e.g. `mdi`, `lucide`, `tabler`) for
 * autocomplete/whitelisting. Results are cached for the lifetime of the page.
 */
export const useIconifyCollections = () => {
  const collections = ref<IconifyCollectionOption[]>(cache ?? [])
  const loading = ref(false)
  const failed = ref(false)

  const load = async () => {
    if (cache) {
      collections.value = cache
      return
    }

    loading.value = true
    failed.value = false

    try {
      inflight = inflight ?? fetchCollections()
      const result = await inflight
      cache = result
      collections.value = result
    } catch {
      failed.value = true
      inflight = null
    } finally {
      loading.value = false
    }
  }

  void load()

  return { collections, loading, failed, reload: load }
}
