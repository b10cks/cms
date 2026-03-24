import { getPresenceColor } from '~/components/ui/presence-colors'
import type {
  ContentWizardCollaborator,
  ContentWizardRemoteCursorState,
  ContentWizardSyncOperation,
} from '~/types/content-wizard'

const CONTENT_WIZARD_FOCUS_EVENT = 'content-canvas-focus'
const CONTENT_WIZARD_CURSOR_EVENT = 'content-canvas-cursor'
const CONTENT_WIZARD_OPERATION_EVENT = 'content-canvas-operation'

interface ContentWizardFocusWhisperPayload {
  nodeId: string | null
  userId: string
}

interface ContentWizardCursorWhisperPayload {
  userId: string
  x: number
  y: number
  visible: boolean
}

interface ContentWizardOperationWhisperPayload {
  operation: ContentWizardSyncOperation
  userId: string
}

export function useContentWizardCollaboration(spaceIdRef: MaybeRefOrComputed<string | null>) {
  const channelName = computed(() => {
    const spaceId = unref(spaceIdRef)
    if (!spaceId) {
      return null
    }

    return `presence-spaces.${spaceId}.content-canvas`
  })

  const presence = usePresence(channelName)
  const remoteFocusedNodeIds = ref<Record<string, string | null>>({})
  const remoteCursors = ref<Record<string, ContentWizardRemoteCursorState>>({})

  const collaborators = computed<ContentWizardCollaborator[]>(() =>
    presence.users.value.map((user) => {
      const color = getPresenceColor(user.id)

      return {
        ...user,
        color: color.value,
        colorLabel: color.label,
      }
    })
  )

  const collaboratorMap = computed(
    () => new Map(collaborators.value.map((user) => [user.id, user] as const))
  )

  const focusedUsersByNodeId = computed<Record<string, ContentWizardCollaborator[]>>(() => {
    const grouped: Record<string, ContentWizardCollaborator[]> = {}

    Object.entries(remoteFocusedNodeIds.value).forEach(([userId, nodeId]) => {
      if (!nodeId) {
        return
      }

      const user = collaboratorMap.value.get(userId)
      if (!user) {
        return
      }

      grouped[nodeId] = [...(grouped[nodeId] || []), user]
    })

    return grouped
  })

  const visibleRemoteCursors = computed(() =>
    Object.values(remoteCursors.value)
      .filter((cursor) => cursor.visible)
      .map((cursor) => {
        const user = collaboratorMap.value.get(cursor.userId)
        if (!user) {
          return null
        }

        return {
          ...cursor,
          user,
        }
      })
      .filter(
        (
          cursor
        ): cursor is ContentWizardRemoteCursorState & {
          user: ContentWizardCollaborator
        } => !!cursor
      )
  )

  const cleanupAbsentUsers = () => {
    const activeUserIds = new Set(collaborators.value.map((user) => user.id))

    remoteFocusedNodeIds.value = Object.fromEntries(
      Object.entries(remoteFocusedNodeIds.value).filter(([userId]) => activeUserIds.has(userId))
    )

    remoteCursors.value = Object.fromEntries(
      Object.entries(remoteCursors.value).filter(([userId]) => activeUserIds.has(userId))
    )
  }

  const currentUserId = computed(() => presence.currentUser.value?.id || null)

  const broadcastFocus = (nodeId: string | null) => {
    if (!currentUserId.value) {
      return
    }

    presence.whisper(CONTENT_WIZARD_FOCUS_EVENT, {
      nodeId,
      userId: currentUserId.value,
    } satisfies ContentWizardFocusWhisperPayload)
  }

  const broadcastCursor = (payload: { x: number; y: number } | null) => {
    if (!currentUserId.value) {
      return
    }

    presence.whisper(CONTENT_WIZARD_CURSOR_EVENT, {
      userId: currentUserId.value,
      x: payload?.x ?? 0,
      y: payload?.y ?? 0,
      visible: !!payload,
    } satisfies ContentWizardCursorWhisperPayload)
  }

  const broadcastOperation = (operation: ContentWizardSyncOperation) => {
    if (!currentUserId.value) {
      return
    }

    presence.whisper(CONTENT_WIZARD_OPERATION_EVENT, {
      operation,
      userId: currentUserId.value,
    } satisfies ContentWizardOperationWhisperPayload)
  }

  const onOperation = (callback: (operation: ContentWizardSyncOperation) => void) =>
    presence.onWhisper<ContentWizardOperationWhisperPayload>(
      CONTENT_WIZARD_OPERATION_EVENT,
      (payload) => {
        if (!payload || payload.userId === currentUserId.value) {
          return
        }

        callback(payload.operation)
      }
    )

  const stopFocusListener = presence.onWhisper<ContentWizardFocusWhisperPayload>(
    CONTENT_WIZARD_FOCUS_EVENT,
    (payload) => {
      if (!payload || payload.userId === currentUserId.value) {
        return
      }

      remoteFocusedNodeIds.value = {
        ...remoteFocusedNodeIds.value,
        [payload.userId]: payload.nodeId,
      }
    }
  )

  const stopCursorListener = presence.onWhisper<ContentWizardCursorWhisperPayload>(
    CONTENT_WIZARD_CURSOR_EVENT,
    (payload) => {
      if (!payload || payload.userId === currentUserId.value) {
        return
      }

      remoteCursors.value = {
        ...remoteCursors.value,
        [payload.userId]: {
          userId: payload.userId,
          x: payload.x,
          y: payload.y,
          visible: payload.visible,
          updatedAt: Date.now(),
        },
      }
    }
  )

  watch(collaborators, cleanupAbsentUsers)

  onBeforeUnmount(() => {
    broadcastFocus(null)
    broadcastCursor(null)
    stopFocusListener()
    stopCursorListener()
  })

  return {
    collaborators,
    currentUser: presence.currentUser,
    isConnected: presence.isConnected,
    focusedUsersByNodeId,
    visibleRemoteCursors,
    broadcastFocus,
    broadcastCursor,
    broadcastOperation,
    onOperation,
  }
}
