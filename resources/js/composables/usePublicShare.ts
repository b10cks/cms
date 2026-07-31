import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { MaybeRef } from 'vue'

import { api } from '~/api'
import { PublicShare } from '~/api/resources/public-share'
import type { DownloadUrlResponse } from '~/types/asset-distribution'

import { queryKeys } from './useQueryClient'

const POLL_INTERVAL = 3000
const MAX_POLL_ATTEMPTS = 200 // ~10 minutes

const storageKey = (spaceId: string, token: string) => `b10cks:share:${spaceId}:${token}:access`

const readStoredAccessToken = (spaceId: string, token: string): string | null => {
  try {
    return sessionStorage.getItem(storageKey(spaceId, token))
  } catch {
    return null
  }
}

const storeAccessToken = (spaceId: string, token: string, accessToken: string) => {
  try {
    sessionStorage.setItem(storageKey(spaceId, token), accessToken)
  } catch {
    // Session storage unavailable (e.g. privacy mode) — keep it in memory only.
  }
}

const clearStoredAccessToken = (spaceId: string, token: string) => {
  try {
    sessionStorage.removeItem(storageKey(spaceId, token))
  } catch {
    // ignore
  }
}

export function usePublicShare(spaceId: MaybeRef<string>, token: MaybeRef<string>) {
  const queryClient = useQueryClient()

  const shareAPI = computed(() => new PublicShare(api.client, toValue(spaceId), toValue(token)))
  const accessToken = ref<string | null>(readStoredAccessToken(toValue(spaceId), toValue(token)))

  watch(
    () => [toValue(spaceId), toValue(token)] as const,
    ([space, value]) => {
      accessToken.value = readStoredAccessToken(space, value)
    }
  )

  const invalidateShare = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.publicShare(spaceId, token).all() })
  }

  const clearAccess = () => {
    accessToken.value = null
    clearStoredAccessToken(toValue(spaceId), toValue(token))
  }

  const useShareQuery = () => {
    return useQuery({
      queryKey: computed(() => [...queryKeys.publicShare(spaceId, token).meta(), accessToken.value]),
      queryFn: async () => {
        try {
          const response = await shareAPI.value.show(accessToken.value)
          return response.data
        } catch (error: any) {
          // A stale/expired access token behaves like a locked share again. The token
          // is part of the query key, so clearing it rekeys the query and refetches as
          // an anonymous visitor — re-requesting here as well would only burn a second
          // metered round trip whose answer lands under the stale key.
          if (error?.status === 403 && accessToken.value) {
            clearAccess()
          }
          throw error
        }
      },
      retry: (failureCount, error: any) => {
        // Neither an unknown share nor a rejected token changes on a retry.
        if (error?.status === 404 || error?.status === 403) return false
        return failureCount < 2
      },
    })
  }

  const useShareAssetsQuery = (
    perPage: MaybeRef<number> = 48,
    enabled: MaybeRef<boolean> = true
  ) => {
    return useInfiniteQuery({
      queryKey: computed(() => [
        ...queryKeys.publicShare(spaceId, token).assetsList({ per_page: toValue(perPage) }),
        accessToken.value,
      ]),
      queryFn: async ({ pageParam }) => {
        return await shareAPI.value.assets(
          { page: pageParam, per_page: toValue(perPage) },
          accessToken.value
        )
      },
      initialPageParam: 1,
      getNextPageParam: (lastPage) =>
        lastPage.meta.current_page < lastPage.meta.last_page
          ? lastPage.meta.current_page + 1
          : undefined,
      enabled: computed(() => toValue(enabled)),
    })
  }

  const useUnlockMutation = () => {
    return useMutation({
      mutationFn: async (password: string) => {
        return await shareAPI.value.unlock(password)
      },
      onSuccess: (data) => {
        accessToken.value = data.access_token
        storeAccessToken(toValue(spaceId), toValue(token), data.access_token)
        invalidateShare()
      },
    })
  }

  /**
   * Request the package download. While the archive is (re)building the
   * endpoint answers 202 — poll until a signed URL arrives.
   */
  const downloadAll = async (
    onProgress?: (progress: number | null) => void
  ): Promise<DownloadUrlResponse> => {
    for (let attempt = 0; attempt < MAX_POLL_ATTEMPTS; attempt++) {
      const response = await shareAPI.value.download(accessToken.value)

      if ('url' in response) {
        invalidateShare()
        return response
      }

      // Failed builds are retried server-side after a cooldown — polling
      // faster than that only spins, so surface the failure instead.
      if (response.state === 'failed') {
        throw new Error('Package build failed')
      }

      onProgress?.(response.progress ?? null)
      await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL))
    }

    throw new Error('Package build timed out')
  }

  const downloadAsset = async (assetId: string): Promise<DownloadUrlResponse> => {
    const response = await shareAPI.value.downloadAsset(assetId, accessToken.value)
    // Single downloads are metered too, so the remaining-downloads figure is now stale.
    invalidateShare()
    return response
  }

  return {
    accessToken,
    useShareQuery,
    useShareAssetsQuery,
    useUnlockMutation,
    downloadAll,
    downloadAsset,
    clearAccess,
  }
}
