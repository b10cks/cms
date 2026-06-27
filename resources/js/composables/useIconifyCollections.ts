import { ref } from 'vue'

import { fetchIconifyCollections, type IconifyCollectionMeta } from '~/lib/iconify'

export type IconifyCollectionOption = IconifyCollectionMeta

// Module-level cache so the (large, rarely-changing) collection list is fetched once per session
// and shared across every icon-field config that mounts.
let cache: IconifyCollectionOption[] | null = null
let inflight: Promise<IconifyCollectionOption[]> | null = null

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
      inflight = inflight ?? fetchIconifyCollections()
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
