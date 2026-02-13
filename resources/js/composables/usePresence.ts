import type Echo from 'laravel-echo'

import type { User } from '~/types/users'

import { isClient } from '~/lib/env'

export interface PresenceUser extends User {
  joined_at: string
}

export interface PresenceState {
  users: PresenceUser[]
  count: number
}

export interface UsePresenceOptions {
  maxReconnectAttempts?: number
  reconnectDelay?: number
}

export function usePresence(
  channelNameRef: MaybeRefOrComputed<string | null>,
  options: UsePresenceOptions = {}
) {
  const { maxReconnectAttempts = 5, reconnectDelay = 3000 } = options

  const channelName = computed(() => unref(channelNameRef))
  const users = ref<PresenceUser[]>([])
  const isConnected = ref(false)
  const isConnecting = ref(false)
  const error = ref<Error | null>(null)
  const reconnectAttempts = ref(0)

  let presenceChannel: ReturnType<Echo<'reverb'>['join']> | null = null
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null

  const currentUser = computed(() => {
    const auth = useAuth()
    return auth.user.value
  })

  const getEcho = (): Echo<'reverb'> | null => {
    try {
      return useEcho()
    } catch {
      return null
    }
  }

  const connect = () => {
    if (!isClient) return
    if (!channelName.value) return

    const echo = getEcho()
    if (!echo) {
      error.value = new Error('Echo not initialized')
      return
    }

    isConnecting.value = true
    error.value = null

    try {
      presenceChannel = echo.join(channelName.value)

      presenceChannel
        .here((members: PresenceUser[]) => {
          users.value = members
          isConnected.value = true
          isConnecting.value = false
          reconnectAttempts.value = 0
        })
        .joining((member: PresenceUser) => {
          if (!users.value.find((u) => u.id === member.id)) {
            users.value = [...users.value, member]
          }
        })
        .leaving((member: PresenceUser) => {
          users.value = users.value.filter((u) => u.id !== member.id)
        })
        .error((err: Error) => {
          error.value = err
          isConnected.value = false
          isConnecting.value = false
          handleReconnect()
        })
    } catch (err) {
      error.value = err instanceof Error ? err : new Error('Failed to join presence channel')
      isConnecting.value = false
      handleReconnect()
    }
  }

  const disconnect = () => {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }

    if (presenceChannel) {
      try {
        const echo = getEcho()
        if (echo && channelName.value) {
          echo.leave(channelName.value)
        }
      } catch {
        // Ignore leave errors
      }
      presenceChannel = null
    }

    users.value = []
    isConnected.value = false
    isConnecting.value = false
    reconnectAttempts.value = 0
  }

  const handleReconnect = () => {
    if (reconnectAttempts.value < maxReconnectAttempts) {
      reconnectAttempts.value++
      reconnectTimer = setTimeout(() => {
        disconnect()
        connect()
      }, reconnectDelay)
    }
  }

  const refresh = () => {
    disconnect()
    connect()
  }

  watch(channelName, (newChannel, oldChannel) => {
    if (newChannel !== oldChannel) {
      disconnect()
      if (newChannel) {
        connect()
      }
    }
  })

  onMounted(() => {
    if (channelName.value) {
      connect()
    }
  })

  onUnmounted(() => {
    disconnect()
  })

  return {
    users: readonly(users),
    count: computed(() => users.value.length),
    isConnected: readonly(isConnected),
    isConnecting: readonly(isConnecting),
    error: readonly(error),
    currentUser: readonly(currentUser),
    refresh,
    disconnect,
  }
}

/**
 * Join space presence channel - use this when user is INSIDE the space
 * This makes the user visible to others in the space
 * For read-only peeking (space overview), use useSpacePresencePeek instead
 */
export function useSpacePresence(spaceIdRef: MaybeRefOrComputed<string | null>) {
  const spaceId = computed(() => unref(spaceIdRef))

  const channelName = computed(() => {
    if (!spaceId.value) return null
    return `presence-spaces.${spaceId.value}`
  })

  return usePresence(channelName)
}

export function useContentPresence(
  spaceIdRef: MaybeRefOrComputed<string | null>,
  contentIdRef: MaybeRefOrComputed<string | null>
) {
  const spaceId = computed(() => unref(spaceIdRef))
  const contentId = computed(() => unref(contentIdRef))

  const channelName = computed(() => {
    if (!spaceId.value || !contentId.value) return null
    return `presence-spaces.${spaceId.value}.content.${contentId.value}`
  })

  return usePresence(channelName)
}
