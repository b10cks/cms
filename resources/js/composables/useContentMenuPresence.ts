import type Echo from 'laravel-echo'

import { isClient } from '~/lib/env'

import type { PresenceUser } from './usePresence'

export interface ContentPresenceMap {
  [contentId: string]: PresenceUser[]
}

interface PresenceUserWithContent extends PresenceUser {
  content_id?: string
}

export function useContentMenuPresence(spaceIdRef: MaybeRefOrComputed<string>) {
  const spaceId = computed(() => unref(spaceIdRef))
  const presenceMap = ref<ContentPresenceMap>({})

  let echoChannel: ReturnType<Echo<'reverb'>['join']> | null = null

  const getEcho = () => {
    try {
      return useEcho()
    } catch {
      return null
    }
  }

  const setupPresenceListener = () => {
    if (!isClient) return

    const echo = getEcho()
    if (!echo) return

    const channelName = `presence-spaces.${spaceId.value}.content`

    try {
      echoChannel = echo.join(channelName)

      echoChannel
        .here((members: PresenceUserWithContent[]) => {
          const newMap: ContentPresenceMap = {}
          members.forEach((member) => {
            if (member.content_id) {
              if (!newMap[member.content_id]) {
                newMap[member.content_id] = []
              }
              newMap[member.content_id].push(member)
            }
          })
          presenceMap.value = newMap
        })
        .joining((member: PresenceUserWithContent) => {
          if (member.content_id) {
            const currentUsers = presenceMap.value[member.content_id] || []
            if (!currentUsers.find((u) => u.id === member.id)) {
              presenceMap.value = {
                ...presenceMap.value,
                [member.content_id]: [...currentUsers, member],
              }
            }
          }
        })
        .leaving((member: PresenceUserWithContent) => {
          if (member.content_id) {
            const currentUsers = presenceMap.value[member.content_id] || []
            const filtered = currentUsers.filter((u) => u.id !== member.id)
            if (filtered.length === 0) {
              const { [member.content_id]: _, ...rest } = presenceMap.value
              presenceMap.value = rest
            } else {
              presenceMap.value = {
                ...presenceMap.value,
                [member.content_id]: filtered,
              }
            }
          }
        })
        .listen(
          '.content:presence',
          (event: { content_id: string; user: PresenceUser; action: 'join' | 'leave' }) => {
            if (event.action === 'join') {
              const currentUsers = presenceMap.value[event.content_id] || []
              if (!currentUsers.find((u) => u.id === event.user.id)) {
                presenceMap.value = {
                  ...presenceMap.value,
                  [event.content_id]: [...currentUsers, event.user],
                }
              }
            } else if (event.action === 'leave') {
              const currentUsers = presenceMap.value[event.content_id] || []
              const filtered = currentUsers.filter((u) => u.id !== event.user.id)
              if (filtered.length === 0) {
                const { [event.content_id]: _, ...rest } = presenceMap.value
                presenceMap.value = rest
              } else {
                presenceMap.value = {
                  ...presenceMap.value,
                  [event.content_id]: filtered,
                }
              }
            }
          }
        )
    } catch {
      // Silently fail if presence is not available
    }
  }

  const cleanup = () => {
    if (echoChannel) {
      try {
        const echo = getEcho()
        if (echo) {
          echo.leave(`presence-spaces.${spaceId.value}.content`)
        }
      } catch {
        // Ignore cleanup errors
      }
      echoChannel = null
    }
    presenceMap.value = {}
  }

  const getUsersForContent = (contentId: string): PresenceUser[] => {
    return presenceMap.value[contentId] || []
  }

  watch(spaceId, (newId, oldId) => {
    if (newId !== oldId) {
      cleanup()
      if (newId) {
        setupPresenceListener()
      }
    }
  })

  onMounted(() => {
    if (spaceId.value) {
      setupPresenceListener()
    }
  })

  onUnmounted(() => {
    cleanup()
  })

  return {
    presenceMap: readonly(presenceMap),
    getUsersForContent,
  }
}
