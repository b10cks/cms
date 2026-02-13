import type { PresenceUser } from './usePresence'

export interface SpacePresenceInfo {
  spaceId: string
  users: PresenceUser[]
  count: number
}

export function useSpacePresencePeek() {
  const presenceData = ref<Map<string, SpacePresenceInfo>>(new Map())
  const isLoading = ref(false)
  const error = ref<Error | null>(null)

  const fetchSpacePresence = async (spaceId: string): Promise<SpacePresenceInfo | null> => {
    try {
      const { api } = await import('~/api')
      const response = await api.client.get<{ data: SpacePresenceInfo }>(
        `/mgmt/v1/spaces/${spaceId}/presence`
      )
      return response.data
    } catch (err) {
      console.error(`Failed to fetch presence for space ${spaceId}:`, err)
      return null
    }
  }

  const peekSpacePresence = async (spaceId: string) => {
    isLoading.value = true
    error.value = null

    try {
      const info = await fetchSpacePresence(spaceId)
      if (info) {
        presenceData.value.set(spaceId, info)
      }
    } catch (err) {
      error.value = err instanceof Error ? err : new Error('Failed to peek space presence')
    } finally {
      isLoading.value = false
    }
  }

  const peekMultipleSpaces = async (spaceIds: string[]) => {
    isLoading.value = true
    error.value = null

    try {
      const results = await Promise.all(
        spaceIds.map((id) => fetchSpacePresence(id).catch(() => null))
      )

      results.forEach((info, index) => {
        if (info) {
          presenceData.value.set(spaceIds[index], info)
        }
      })
    } catch (err) {
      error.value = err instanceof Error ? err : new Error('Failed to peek spaces presence')
    } finally {
      isLoading.value = false
    }
  }

  const getSpacePresence = (spaceId: string): SpacePresenceInfo | null => {
    return presenceData.value.get(spaceId) || null
  }

  const getSpaceUsers = (spaceId: string): PresenceUser[] => {
    return presenceData.value.get(spaceId)?.users || []
  }

  return {
    presenceData: readonly(presenceData),
    isLoading: readonly(isLoading),
    error: readonly(error),
    peekSpacePresence,
    peekMultipleSpaces,
    getSpacePresence,
    getSpaceUsers,
  }
}
