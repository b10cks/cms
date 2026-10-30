import { getPresenceColor } from '~/components/ui/presence-colors'
import type {
  ContentWizardCollaborator,
  ContentWizardRemoteCursorState,
  ContentWizardSyncOperation,
} from '~/types/content-wizard'

const CONTENT_WIZARD_FOCUS_EVENT = 'content-canvas-focus'
const CONTENT_WIZARD_CURSOR_EVENT = 'content-canvas-cursor'
const CONTENT_WIZARD_OPERATION_EVENT = 'content-canvas-operation'
const CONTENT_WIZARD_SYNC_REQUEST_EVENT = 'content-canvas-sync-request'
const CONTENT_WIZARD_SYNC_STATE_EVENT = 'content-canvas-sync-state'
const SYNC_REPLY_MAX_DELAY_MS = 400
// Reverb rejects messages over 10KB (max_message_size), so replies are chunked.
const SYNC_CHUNK_MAX_BYTES = 8000

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

interface ContentWizardSyncRequestWhisperPayload {
  userId: string
}

interface ContentWizardSyncStateWhisperPayload {
  userId: string
  requesterId: string
  operations: ContentWizardSyncOperation[]
}

const textEncoder = new TextEncoder()

const chunkOperations = (operations: ContentWizardSyncOperation[]) => {
  const chunks: ContentWizardSyncOperation[][] = []
  let chunk: ContentWizardSyncOperation[] = []
  let chunkBytes = 0

  operations.forEach((operation) => {
    const bytes = textEncoder.encode(JSON.stringify(operation)).length
    if (chunk.length > 0 && chunkBytes + bytes > SYNC_CHUNK_MAX_BYTES) {
      chunks.push(chunk)
      chunk = []
      chunkBytes = 0
    }

    chunk.push(operation)
    chunkBytes += bytes
  })

  if (chunk.length > 0) {
    chunks.push(chunk)
  }

  return chunks
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

  const operationCallbacks = new Set<(operation: ContentWizardSyncOperation) => void>()

  /** Receives remote operations, both live ones and the late-join sync replay. */
  const onOperation = (callback: (operation: ContentWizardSyncOperation) => void) => {
    operationCallbacks.add(callback)

    return () => {
      operationCallbacks.delete(callback)
    }
  }

  const emitOperation = (operation: ContentWizardSyncOperation) => {
    operationCallbacks.forEach((callback) => callback(operation))
  }

  // Drafts live only in the browser, so a late joiner asks the room for them.
  // Peers with a draft reply with the operations that turn the server tree into
  // it, jittered so one of them answers and the others stand down.
  let syncProvider: (() => ContentWizardSyncOperation[]) | null = null
  const syncReplyTimers = new Map<string, ReturnType<typeof setTimeout>>()
  let syncSourceUserId: string | null = null

  /** Supplies the operations that bring a late joiner up to the local draft. */
  const provideSyncOperations = (provider: () => ContentWizardSyncOperation[]) => {
    syncProvider = provider
  }

  const sendSyncState = (requesterId: string) => {
    const operations = syncProvider?.() ?? []
    if (!currentUserId.value || operations.length === 0) {
      return
    }

    const userId = currentUserId.value
    chunkOperations(operations).forEach((chunk) => {
      presence.whisper(CONTENT_WIZARD_SYNC_STATE_EVENT, {
        userId,
        requesterId,
        operations: chunk,
      } satisfies ContentWizardSyncStateWhisperPayload)
    })
  }

  const requestSyncState = () => {
    if (!currentUserId.value) {
      return
    }

    syncSourceUserId = null
    presence.whisper(CONTENT_WIZARD_SYNC_REQUEST_EVENT, {
      userId: currentUserId.value,
    } satisfies ContentWizardSyncRequestWhisperPayload)
  }

  const clearSyncReply = (requesterId: string) => {
    const timer = syncReplyTimers.get(requesterId)
    if (timer) {
      clearTimeout(timer)
      syncReplyTimers.delete(requesterId)
    }
  }

  const stopOperationListener = presence.onWhisper<ContentWizardOperationWhisperPayload>(
    CONTENT_WIZARD_OPERATION_EVENT,
    (payload) => {
      if (!payload || payload.userId === currentUserId.value) {
        return
      }

      emitOperation(payload.operation)
    }
  )

  const stopSyncRequestListener = presence.onWhisper<ContentWizardSyncRequestWhisperPayload>(
    CONTENT_WIZARD_SYNC_REQUEST_EVENT,
    (payload) => {
      if (!payload || payload.userId === currentUserId.value) {
        return
      }

      clearSyncReply(payload.userId)
      syncReplyTimers.set(
        payload.userId,
        setTimeout(() => {
          syncReplyTimers.delete(payload.userId)
          sendSyncState(payload.userId)
        }, Math.random() * SYNC_REPLY_MAX_DELAY_MS)
      )
    }
  )

  const stopSyncStateListener = presence.onWhisper<ContentWizardSyncStateWhisperPayload>(
    CONTENT_WIZARD_SYNC_STATE_EVENT,
    (payload) => {
      if (!payload || payload.userId === currentUserId.value) {
        return
      }

      // Someone else already answered this joiner.
      clearSyncReply(payload.requesterId)

      if (payload.requesterId !== currentUserId.value) {
        return
      }

      // Every peer with a draft may answer; replaying two would double-apply.
      syncSourceUserId ??= payload.userId
      if (payload.userId !== syncSourceUserId) {
        return
      }

      payload.operations.forEach(emitOperation)
    }
  )

  watch(
    () => presence.isConnected.value,
    (isConnected) => {
      if (isConnected) {
        requestSyncState()
      }
    },
    { immediate: true }
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
    stopOperationListener()
    stopSyncRequestListener()
    stopSyncStateListener()
    syncReplyTimers.forEach((timer) => clearTimeout(timer))
    syncReplyTimers.clear()
    operationCallbacks.clear()
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
    provideSyncOperations,
  }
}
