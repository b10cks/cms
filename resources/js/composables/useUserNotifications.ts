import { useQueryClient } from '@tanstack/vue-query'

import { isClient } from '~/lib/env'

import { useAuth } from './useAuth'
import { useEcho } from './useEcho'
import { queryKeys } from './useQueryClient'

/**
 * Subscribes to the authenticated user's private channel and refreshes the
 * notification queries whenever Reverb delivers a new notification. Mounted
 * once in the default layout, alongside useSpaceBroadcasts.
 */
export function useUserNotifications() {
  const queryClient = useQueryClient()
  const { user } = useAuth()

  const channelFor = (id: string) => `App.Models.User.${id}`

  const setup = (id: string) => {
    if (!isClient || !id) return

    try {
      const echo = useEcho()
      if (!echo) return

      echo.private(channelFor(id)).notification(() => {
        queryClient.invalidateQueries({ queryKey: queryKeys.notifications.all() })
      })
    } catch {
      /** */
    }
  }

  const teardown = (id: string) => {
    if (!isClient || !id) return

    try {
      const echo = useEcho()
      if (!echo) return
      echo.leave(channelFor(id))
    } catch {
      /** */
    }
  }

  onMounted(() => {
    if (user.value?.id) setup(user.value.id)
  })

  onUnmounted(() => {
    if (user.value?.id) teardown(user.value.id)
  })

  watch(
    () => user.value?.id,
    (newId, oldId) => {
      if (oldId) teardown(oldId)
      if (newId) setup(newId)
    }
  )
}
