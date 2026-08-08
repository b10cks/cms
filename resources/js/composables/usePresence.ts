import type Echo from 'laravel-echo'

import { isClient } from '~/lib/env'
import type { User } from '~/types/users'

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

interface WhisperListener {
  eventName: string
  callback: (payload: unknown) => void
}

interface PresenceRealtimeChannel {
  whisper?: (eventName: string, payload: unknown) => void
  listenForWhisper?: (eventName: string, callback: (payload: unknown) => void) => void
  stopListeningForWhisper?: (eventName: string, callback: (payload: unknown) => void) => void
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
  // The channel handle is dropped on a channel error (so nothing whispers into a
  // dead object), but the subscription still has to be left — hence a separate
  // record of what was actually joined.
  let joinedChannelName: string | null = null
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null
  const whisperListeners: WhisperListener[] = []

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

  const getRealtimeChannel = (): PresenceRealtimeChannel | null => {
    if (!presenceChannel) return null
    return presenceChannel as unknown as PresenceRealtimeChannel
  }

  const attachWhisperListeners = () => {
    const channel = getRealtimeChannel()
    if (!channel?.listenForWhisper) return

    whisperListeners.forEach((listener) => {
      channel.listenForWhisper?.(listener.eventName, listener.callback)
    })
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
      joinedChannelName = channelName.value
      attachWhisperListeners()

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
          // Drop the handle so whispers are not written into a dead channel
          // while the reconnect is pending; joinedChannelName keeps the leave.
          presenceChannel = null
          handleReconnect()
        })
    } catch (err) {
      error.value = err instanceof Error ? err : new Error('Failed to join presence channel')
      isConnecting.value = false
      handleReconnect()
    }
  }

  // channelToLeave must be passed explicitly when the channel name has already
  // changed (see the channelName watcher) — leaving channelName.value there
  // would target the new channel and leak the old subscription.
  const clearReconnectTimer = () => {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
  }

  const disconnect = (channelToLeave: string | null = channelName.value) => {
    clearReconnectTimer()

    if (joinedChannelName) {
      try {
        const echo = getEcho()
        const target = channelToLeave ?? joinedChannelName
        if (echo && target) {
          echo.leave(target)
        }
      } catch {
        // Ignore leave errors
      }
      presenceChannel = null
      joinedChannelName = null
    }

    users.value = []
    isConnected.value = false
    isConnecting.value = false
    // reconnectAttempts is deliberately NOT reset here: the reconnect timer
    // calls disconnect() before rejoining, so resetting would let a dead server
    // reconnect forever. Only a successful subscription (here) clears it.
  }

  const handleReconnect = () => {
    // One reconnect at a time — a burst of channel errors must not leave a
    // pending timer per error.
    if (reconnectTimer) return
    if (reconnectAttempts.value >= maxReconnectAttempts) return

    reconnectAttempts.value++
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null
      disconnect()
      connect()
    }, reconnectDelay)
  }

  // An explicit retry (or a different channel) starts the budget over.
  const resetReconnect = () => {
    clearReconnectTimer()
    reconnectAttempts.value = 0
  }

  const refresh = () => {
    disconnect()
    resetReconnect()
    connect()
  }

  const whisper = <T>(eventName: string, payload: T) => {
    getRealtimeChannel()?.whisper?.(eventName, payload)
  }

  const onWhisper = <T>(eventName: string, callback: (payload: T) => void) => {
    const listener: WhisperListener = {
      eventName,
      callback: callback as (payload: unknown) => void,
    }

    whisperListeners.push(listener)
    getRealtimeChannel()?.listenForWhisper?.(eventName, listener.callback)

    return () => {
      const index = whisperListeners.indexOf(listener)
      if (index >= 0) {
        whisperListeners.splice(index, 1)
      }
      // Forgetting the listener is not enough: it is still attached to the live
      // channel, so a consumer that unsubscribes and resubscribes would receive
      // every whisper twice.
      getRealtimeChannel()?.stopListeningForWhisper?.(eventName, listener.callback)
    }
  }

  watch(channelName, (newChannel, oldChannel) => {
    if (newChannel !== oldChannel) {
      disconnect(oldChannel ?? null)
      resetReconnect()
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
    whisper,
    onWhisper,
  }
}

/**
 * Join space presence channel - use this when user is INSIDE the space
 * This makes the user visible to others in the space
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
