import type { PresenceUser } from './usePresence'

const LOCATION_EVENT = 'content-location'
const LOCATION_REQUEST_EVENT = 'content-location-request'

const LOCATION_REPLY_MAX_DELAY_MS = 300

export interface ContentPresenceMap {
  [contentId: string]: PresenceUser[]
}

interface ContentLocationWhisperPayload {
  userId: string
  contentId: string | null
}

interface ContentLocationRequestWhisperPayload {
  userId: string
}

/**
 * Tracks which content every space member is currently editing, for the
 * presence badges in the content tree.
 *
 * Everyone on the content section joins `presence-spaces.{space}.content`
 * (the tree stays mounted as the parent route of the detail pages) and
 * whispers their current content id — on connect, on navigation, and in
 * reply to location requests from late joiners. Locations are pruned when
 * a member leaves the channel, so badges clear on disconnect.
 */
export function useContentMenuPresence(spaceIdRef: MaybeRefOrComputed<string>) {
  const route = useRoute()
  const spaceId = computed(() => unref(spaceIdRef))
  const currentContentId = computed(() => (route.params.contentId as string | undefined) || null)

  const presence = usePresence(
    computed(() => (spaceId.value ? `presence-spaces.${spaceId.value}.content` : null))
  )

  const locations = ref<Record<string, string>>({})
  const replyTimers = new Map<string, ReturnType<typeof setTimeout>>()

  const broadcastLocation = () => {
    const userId = presence.currentUser.value?.id
    if (!userId || !presence.isConnected.value) return

    presence.whisper(LOCATION_EVENT, {
      userId,
      contentId: currentContentId.value,
    } satisfies ContentLocationWhisperPayload)
  }

  const requestLocations = () => {
    const userId = presence.currentUser.value?.id
    if (!userId) return

    presence.whisper(LOCATION_REQUEST_EVENT, {
      userId,
    } satisfies ContentLocationRequestWhisperPayload)
  }

  watch(currentContentId, broadcastLocation)

  watch(
    () => presence.isConnected.value,
    (isConnected) => {
      if (isConnected) {
        broadcastLocation()
        requestLocations()
      }
    },
    { immediate: true }
  )

  // Members who leave the channel (navigation away, tab close, disconnect)
  // take their location with them.
  watch(
    () => presence.users.value,
    (users) => {
      const activeUserIds = new Set(users.map((user) => user.id))
      const next = Object.fromEntries(
        Object.entries(locations.value).filter(([userId]) => activeUserIds.has(userId))
      )

      if (Object.keys(next).length !== Object.keys(locations.value).length) {
        locations.value = next
      }
    }
  )

  const stopLocationListener = presence.onWhisper<ContentLocationWhisperPayload>(
    LOCATION_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return

      if (payload.contentId) {
        locations.value = { ...locations.value, [payload.userId]: payload.contentId }
        return
      }

      const { [payload.userId]: _, ...rest } = locations.value
      locations.value = rest
    }
  )

  const stopRequestListener = presence.onWhisper<ContentLocationRequestWhisperPayload>(
    LOCATION_REQUEST_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return
      if (!currentContentId.value) return

      const existingTimer = replyTimers.get(payload.userId)
      if (existingTimer) clearTimeout(existingTimer)

      replyTimers.set(
        payload.userId,
        setTimeout(
          () => {
            replyTimers.delete(payload.userId)
            broadcastLocation()
          },
          Math.random() * LOCATION_REPLY_MAX_DELAY_MS
        )
      )
    }
  )

  onBeforeUnmount(() => {
    replyTimers.forEach((timer) => clearTimeout(timer))
    replyTimers.clear()
    stopLocationListener()
    stopRequestListener()
  })

  const presenceMap = computed<ContentPresenceMap>(() => {
    const currentUserId = presence.currentUser.value?.id
    const map: ContentPresenceMap = {}

    for (const user of presence.users.value) {
      if (user.id === currentUserId) continue

      const contentId = locations.value[user.id]
      if (!contentId) continue

      if (!map[contentId]) map[contentId] = []
      map[contentId].push(user)
    }

    return map
  })

  const getUsersForContent = (contentId: string): PresenceUser[] =>
    presenceMap.value[contentId] || []

  return {
    presenceMap,
    getUsersForContent,
  }
}
