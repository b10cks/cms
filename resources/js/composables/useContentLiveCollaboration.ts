import type { Ref } from 'vue'

import { getPresenceColor } from '~/components/ui/presence-colors'
import type { ContentResource } from '~/types/contents'

import type { PresenceUser } from './usePresence'

const FIELD_UPDATE_EVENT = 'content-field-update'
const FIELD_FOCUS_EVENT = 'content-field-focus'
const CONTENT_COMMIT_EVENT = 'content-commit'
const CONTENT_DISCARD_EVENT = 'content-discard'
const BLOCK_OPERATION_EVENT = 'content-block-operation'
const SYNC_REQUEST_EVENT = 'content-sync-request'
const SYNC_STATE_EVENT = 'content-sync-state'

const SYNC_REPLY_MAX_DELAY_MS = 400
const MAX_QUEUED_BLOCK_OPERATIONS = 100

export interface CollaborationPresenceUser extends PresenceUser {
  color: string
  colorLabel: string
}

export interface ContentFieldUpdatePayload {
  debounceMs?: number
  itemId: string
  field: string
  previousValue?: unknown
  value: unknown
}

export interface ContentFieldFocusPayload {
  itemId: string
  field: string
  focused: boolean
}

interface ContentFieldWhisperPayload extends ContentFieldUpdatePayload {
  userId: string
}

interface ContentFieldFocusWhisperPayload extends ContentFieldFocusPayload {
  userId: string
}

// parentId/field are filled in by FieldEditor; BlocksBlock emits ops without them.
export type ContentBlockOperationPayload = {
  parentId?: string
  field?: string
  previousValue?: unknown
} & (
  | { type: 'add'; index: number; items: Record<string, unknown>[] }
  | { type: 'remove'; itemIds: string[] }
  | { type: 'reorder'; order: string[] }
  | { type: 'visibility'; itemId: string; hidden: boolean }
  | { type: 'replace'; items: Record<string, unknown>[] }
)

type ContentBlockOperationWhisperPayload = ContentBlockOperationPayload & {
  parentId: string
  field: string
  userId: string
}

interface ContentSyncRequestWhisperPayload {
  userId: string
}

interface ContentSyncStateWhisperPayload {
  userId: string
  requesterId: string
  fields: ContentFieldUpdatePayload[]
  focusedFields: Array<{ itemId: string; field: string }>
}

export type ContentCommitAction = 'save' | 'publish' | 'schedule' | 'unpublish'

interface ContentCommitWhisperPayload {
  action: ContentCommitAction
  content: ContentResource
  userId: string
}

interface ContentDiscardWhisperPayload {
  fields: ContentFieldUpdatePayload[]
  userId: string
}

interface UseContentLiveCollaborationOptions {
  content: Ref<ContentResource | null>
  hasLocalUnsavedChanges?: () => boolean
  syncPersistedContent?: (content: ContentResource, mode: 'replace' | 'preserve-local') => void
  syncPreviewItem?: (item: Record<string, unknown>) => void
  syncInterval?: number
}

interface DraftFieldSnapshot {
  field: string
  itemId: string
  previousValue: unknown
}

const cloneValue = <T>(value: T): T => {
  try {
    return structuredClone(value)
  } catch {
    if (value === undefined || value === null) {
      return value
    }

    return JSON.parse(JSON.stringify(value)) as T
  }
}

const getFieldKey = (itemId: string, field: string) => `${itemId}:${field}`

const findNestedObjectById = (data: unknown, id: string): Record<string, unknown> | null => {
  if (typeof data !== 'object' || data === null) return null

  if (Array.isArray(data)) {
    for (const item of data) {
      const result = findNestedObjectById(item, id)
      if (result) return result
    }

    return null
  }

  const obj = data as Record<string, unknown>
  if (obj.id === id) return obj

  for (const key in obj) {
    if (Object.hasOwn(obj, key) && typeof obj[key] === 'object' && obj[key] !== null) {
      const result = findNestedObjectById(obj[key], id)
      if (result) return result
    }
  }

  return null
}

export function useContentLiveCollaboration(
  spaceIdRef: MaybeRefOrComputed<string | null>,
  contentIdRef: MaybeRefOrComputed<string | null>,
  {
    content,
    hasLocalUnsavedChanges,
    syncPersistedContent,
    syncPreviewItem,
    syncInterval = 750,
  }: UseContentLiveCollaborationOptions
) {
  const presence = useContentPresence(spaceIdRef, contentIdRef)
  const fieldPresence = ref<Record<string, string[]>>({})
  const pendingFieldUpdates = new Map<string, ContentFieldWhisperPayload>()
  const localDraftFields = new Map<string, DraftFieldSnapshot>()
  const remoteDraftFields = new Map<string, Map<string, DraftFieldSnapshot>>()
  const fieldFlushTimers = new Map<string, ReturnType<typeof setTimeout>>()
  const localFocusedFields = new Set<string>()
  // Reactive mirror of remoteDraftFields (userId -> fieldKeys) so the UI can render dirty state.
  const remoteDraftIndex = ref<Record<string, string[]>>({})
  // Last-known identity of every collaborator ever seen, so remote-draft indicators
  // survive the draft owner leaving the channel.
  const knownUsers = ref<Record<string, CollaborationPresenceUser>>({})
  let queuedBlockOperations: ContentBlockOperationWhisperPayload[] = []
  const syncReplyTimers = new Map<string, ReturnType<typeof setTimeout>>()

  const collaborators = computed<CollaborationPresenceUser[]>(() =>
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

  const resolveUser = (userId: string): CollaborationPresenceUser | undefined =>
    collaboratorMap.value.get(userId) ?? knownUsers.value[userId]

  // Maps every content item id to its ancestor chain as (itemId, field) pairs,
  // so field-level presence/dirty state can bubble up through the blocks tree.
  const itemTrailIndex = computed(() => {
    const index = new Map<string, Array<{ itemId: string; field: string }>>()
    const root = content.value
    if (!root) return index

    const visit = (value: unknown, trail: Array<{ itemId: string; field: string }>) => {
      if (!value || typeof value !== 'object') return

      if (Array.isArray(value)) {
        for (const entry of value) visit(entry, trail)
        return
      }

      const obj = value as Record<string, unknown>

      if (typeof obj.id === 'string' && obj.id) {
        index.set(obj.id, trail)
        for (const [key, child] of Object.entries(obj)) {
          if (child && typeof child === 'object') {
            visit(child, [...trail, { itemId: obj.id, field: key }])
          }
        }
        return
      }

      for (const child of Object.values(obj)) {
        if (child && typeof child === 'object') visit(child, trail)
      }
    }

    index.set(root.id, [])
    for (const [key, child] of Object.entries((root.content as Record<string, unknown>) || {})) {
      if (child && typeof child === 'object') visit(child, [{ itemId: root.id, field: key }])
    }

    return index
  })

  const aggregateFieldKeys = (entries: Array<[string, string[]]>) => {
    const byItem = new Map<string, Set<string>>()
    const byField = new Map<string, Set<string>>()
    const add = (map: Map<string, Set<string>>, key: string, userIds: string[]) => {
      const set = map.get(key) ?? new Set<string>()
      if (!map.has(key)) map.set(key, set)
      userIds.forEach((userId) => set.add(userId))
    }

    for (const [fieldKey, userIds] of entries) {
      if (userIds.length === 0) continue

      const separator = fieldKey.indexOf(':')
      const itemId = fieldKey.slice(0, separator)

      add(byField, fieldKey, userIds)
      add(byItem, itemId, userIds)

      for (const trailEntry of itemTrailIndex.value.get(itemId) ?? []) {
        add(byItem, trailEntry.itemId, userIds)
        add(byField, getFieldKey(trailEntry.itemId, trailEntry.field), userIds)
      }
    }

    return { byItem, byField }
  }

  const aggregatedPresence = computed(() => aggregateFieldKeys(Object.entries(fieldPresence.value)))

  const aggregatedRemoteDrafts = computed(() => {
    const byFieldKey = new Map<string, string[]>()
    for (const [userId, fieldKeys] of Object.entries(remoteDraftIndex.value)) {
      for (const fieldKey of fieldKeys) {
        const userIds = byFieldKey.get(fieldKey) ?? []
        if (!byFieldKey.has(fieldKey)) byFieldKey.set(fieldKey, userIds)
        userIds.push(userId)
      }
    }
    return aggregateFieldKeys(Array.from(byFieldKey.entries()))
  })

  const resolveUsers = (userIds: Iterable<string> | undefined): CollaborationPresenceUser[] =>
    Array.from(userIds ?? [])
      .map(resolveUser)
      .filter((user): user is CollaborationPresenceUser => user !== undefined)

  const getSubtreeCollaborators = (itemId: string): CollaborationPresenceUser[] =>
    resolveUsers(aggregatedPresence.value.byItem.get(itemId))

  const getAggregatedCollaboratorsForField = (
    itemId: string,
    field: string
  ): CollaborationPresenceUser[] =>
    resolveUsers(aggregatedPresence.value.byField.get(getFieldKey(itemId, field)))

  const getRemoteDraftCollaborators = (
    itemId: string,
    field?: string
  ): CollaborationPresenceUser[] =>
    resolveUsers(
      field
        ? aggregatedRemoteDrafts.value.byField.get(getFieldKey(itemId, field))
        : aggregatedRemoteDrafts.value.byItem.get(itemId)
    )

  const remoteDraftCollaborators = computed(() =>
    resolveUsers(
      Object.entries(remoteDraftIndex.value)
        .filter(([, fieldKeys]) => fieldKeys.length > 0)
        .map(([userId]) => userId)
    )
  )

  const setFieldPresence = (payload: ContentFieldFocusWhisperPayload) => {
    const key = getFieldKey(payload.itemId, payload.field)
    const activeUserIds = fieldPresence.value[key] || []
    const nextUserIds = payload.focused
      ? Array.from(new Set([...activeUserIds, payload.userId]))
      : activeUserIds.filter((userId) => userId !== payload.userId)

    if (nextUserIds.length === 0) {
      const { [key]: _, ...rest } = fieldPresence.value
      fieldPresence.value = rest
      return
    }

    fieldPresence.value = {
      ...fieldPresence.value,
      [key]: nextUserIds,
    }
  }

  const clearFieldPresence = (userId: string, fields?: DraftFieldSnapshot[]) => {
    if (!fields) {
      const nextFieldPresence = Object.fromEntries(
        Object.entries(fieldPresence.value)
          .map(([fieldKey, userIds]) => [fieldKey, userIds.filter((id) => id !== userId)])
          .filter(([, userIds]) => userIds.length > 0)
      ) as Record<string, string[]>

      fieldPresence.value = nextFieldPresence
      return
    }

    fields.forEach((field) => {
      setFieldPresence({
        itemId: field.itemId,
        field: field.field,
        focused: false,
        userId,
      })
    })
  }

  const getFieldValue = (
    source: ContentResource | null,
    itemId: string,
    field: string
  ): unknown => {
    if (!source) return undefined

    if (itemId === source.id) {
      return (source.content as Record<string, unknown>)?.[field]
    }

    return findNestedObjectById(source.content, itemId)?.[field]
  }

  const applyFieldValue = (itemId: string, field: string, value: unknown) => {
    if (!content.value) return

    const nextValue = cloneValue(value)

    if (itemId === content.value.id) {
      const nextContent = {
        ...(content.value.content as Record<string, unknown>),
        [field]: nextValue,
      }

      content.value = {
        ...content.value,
        content: nextContent,
      }

      syncPreviewItem?.({
        id: content.value.id,
        ...nextContent,
      })
      return
    }

    const target = findNestedObjectById(content.value.content, itemId)
    if (!target) return

    target[field] = nextValue
    syncPreviewItem?.({ ...target })
  }

  const syncRemoteDraftIndex = () => {
    remoteDraftIndex.value = Object.fromEntries(
      Array.from(remoteDraftFields.entries()).map(([userId, drafts]) => [
        userId,
        Array.from(drafts.keys()),
      ])
    )
  }

  const recordRemoteDraft = (
    userId: string,
    itemId: string,
    field: string,
    previousValue: unknown
  ) => {
    const userDrafts = remoteDraftFields.get(userId) ?? new Map<string, DraftFieldSnapshot>()

    if (!remoteDraftFields.has(userId)) {
      remoteDraftFields.set(userId, userDrafts)
    }

    const fieldKey = getFieldKey(itemId, field)
    if (!userDrafts.has(fieldKey)) {
      userDrafts.set(fieldKey, {
        itemId,
        field,
        previousValue: cloneValue(previousValue),
      })
      syncRemoteDraftIndex()
    }
  }

  const applyRemoteFieldUpdate = (payload: ContentFieldWhisperPayload) => {
    recordRemoteDraft(
      payload.userId,
      payload.itemId,
      payload.field,
      payload.previousValue ?? getFieldValue(content.value, payload.itemId, payload.field)
    )

    applyFieldValue(payload.itemId, payload.field, payload.value)
  }

  const clearLocalDraftState = () => {
    fieldFlushTimers.forEach((timer) => clearTimeout(timer))
    fieldFlushTimers.clear()
    localDraftFields.clear()
    pendingFieldUpdates.clear()
  }

  const clearRemoteDraftState = (userId: string) => {
    const userDrafts = remoteDraftFields.get(userId)
    if (!userDrafts) return []

    const fields = Array.from(userDrafts.values())
    remoteDraftFields.delete(userId)
    syncRemoteDraftIndex()
    clearFieldPresence(userId, fields)

    return fields
  }

  const flushFieldUpdate = (fieldKey: string) => {
    if (!presence.isConnected.value) return

    const payload = pendingFieldUpdates.get(fieldKey)
    if (!payload) return

    presence.whisper(FIELD_UPDATE_EVENT, payload)
    pendingFieldUpdates.delete(fieldKey)
  }

  const flushFieldUpdates = () => {
    Array.from(pendingFieldUpdates.keys()).forEach(flushFieldUpdate)
  }

  const queueFieldUpdate = (payload: ContentFieldUpdatePayload) => {
    const userId = presence.currentUser.value?.id
    if (!userId) return

    const fieldKey = getFieldKey(payload.itemId, payload.field)

    if (!localDraftFields.has(fieldKey)) {
      localDraftFields.set(fieldKey, {
        itemId: payload.itemId,
        field: payload.field,
        previousValue: cloneValue(payload.previousValue),
      })
    }

    pendingFieldUpdates.set(fieldKey, {
      ...payload,
      previousValue: cloneValue(localDraftFields.get(fieldKey)?.previousValue),
      value: cloneValue(payload.value),
      userId,
    })

    const debounceMs = payload.debounceMs ?? syncInterval
    const existingTimer = fieldFlushTimers.get(fieldKey)
    if (existingTimer) {
      clearTimeout(existingTimer)
    }

    fieldFlushTimers.set(
      fieldKey,
      setTimeout(() => {
        fieldFlushTimers.delete(fieldKey)
        flushFieldUpdate(fieldKey)
      }, debounceMs)
    )
  }

  const updateFieldFocus = (payload: ContentFieldFocusPayload) => {
    const userId = presence.currentUser.value?.id
    if (!userId) return

    const fieldKey = getFieldKey(payload.itemId, payload.field)

    if (payload.focused) {
      localFocusedFields.add(fieldKey)
    } else {
      localFocusedFields.delete(fieldKey)
      flushFieldUpdate(fieldKey)
    }

    presence.whisper(FIELD_FOCUS_EVENT, {
      ...payload,
      userId,
    })
  }

  const broadcastBlockOperation = (payload: ContentBlockOperationPayload) => {
    const userId = presence.currentUser.value?.id
    const { parentId, field } = payload
    if (!userId || !parentId || !field) return

    const fieldKey = getFieldKey(parentId, field)

    if (!localDraftFields.has(fieldKey)) {
      localDraftFields.set(fieldKey, {
        itemId: parentId,
        field,
        previousValue: cloneValue(payload.previousValue),
      })
    }

    presence.whisper(BLOCK_OPERATION_EVENT, {
      ...payload,
      parentId,
      field,
      previousValue: cloneValue(payload.previousValue),
      userId,
    } satisfies ContentBlockOperationWhisperPayload)
  }

  const tryApplyBlockOperation = (op: ContentBlockOperationWhisperPayload): boolean => {
    const source = content.value
    if (!source) return false

    const parent =
      op.parentId === source.id
        ? ((source.content as Record<string, unknown>) ?? null)
        : findNestedObjectById(source.content, op.parentId)
    if (!parent) return false

    const rawValue = parent[op.field]
    const currentItems = Array.isArray(rawValue) ? (rawValue as Record<string, unknown>[]) : null

    let nextItems: Record<string, unknown>[] | null = null

    switch (op.type) {
      case 'add': {
        const existing = new Set((currentItems ?? []).map((item) => item.id))
        const newItems = op.items.filter((item) => !existing.has(item.id))
        if (newItems.length === 0) return true

        nextItems = [...(currentItems ?? [])]
        nextItems.splice(Math.min(Math.max(op.index, 0), nextItems.length), 0, ...newItems)
        break
      }
      case 'remove': {
        if (!currentItems) return true

        const ids = new Set<unknown>(op.itemIds)
        nextItems = currentItems.filter((item) => !ids.has(item.id))
        if (nextItems.length === currentItems.length) return true
        break
      }
      case 'reorder': {
        if (!currentItems) return true

        const byId = new Map(
          currentItems.filter((item) => typeof item.id === 'string').map((item) => [item.id, item])
        )
        const ordered = op.order
          .map((id) => byId.get(id))
          .filter((item): item is Record<string, unknown> => item !== undefined)
        const rest = currentItems.filter(
          (item) => typeof item.id !== 'string' || !op.order.includes(item.id as string)
        )

        nextItems = [...ordered, ...rest]
        if (nextItems.every((item, index) => item === currentItems[index])) return true
        break
      }
      case 'visibility': {
        if (!currentItems) return false

        const target = currentItems.find((item) => item.id === op.itemId)
        if (!target) return false

        nextItems = currentItems.map((item) =>
          item === target ? { ...item, hidden: op.hidden } : item
        )
        break
      }
      case 'replace': {
        nextItems = op.items
        break
      }
    }

    recordRemoteDraft(op.userId, op.parentId, op.field, currentItems ?? op.previousValue)
    applyFieldValue(op.parentId, op.field, nextItems)

    return true
  }

  // Out-of-order whispers (e.g. an op targeting a block whose "add" hasn't arrived
  // yet) are queued and re-drained after every successful apply — same pattern as
  // the canvas dependency queue.
  const drainQueuedBlockOperations = () => {
    let progressed = true
    while (progressed && queuedBlockOperations.length > 0) {
      progressed = false
      queuedBlockOperations = queuedBlockOperations.filter((op) => {
        if (tryApplyBlockOperation(op)) {
          progressed = true
          return false
        }
        return true
      })
    }
  }

  const applyRemoteBlockOperation = (payload: ContentBlockOperationWhisperPayload) => {
    if (tryApplyBlockOperation(payload)) {
      drainQueuedBlockOperations()
      return
    }

    queuedBlockOperations.push(payload)
    if (queuedBlockOperations.length > MAX_QUEUED_BLOCK_OPERATIONS) {
      queuedBlockOperations.shift()
    }
  }

  // Late-joiner dirty-state sync: joiners whisper a request, peers with local
  // drafts reply (jittered to avoid a burst) with their current draft values.
  const sendSyncState = (requesterId: string) => {
    const userId = presence.currentUser.value?.id
    if (!userId || localDraftFields.size === 0) return

    presence.whisper(SYNC_STATE_EVENT, {
      userId,
      requesterId,
      fields: Array.from(localDraftFields.values()).map((snapshot) => ({
        itemId: snapshot.itemId,
        field: snapshot.field,
        previousValue: cloneValue(snapshot.previousValue),
        value: cloneValue(getFieldValue(content.value, snapshot.itemId, snapshot.field)),
      })),
      focusedFields: Array.from(localFocusedFields).map((fieldKey) => {
        const separator = fieldKey.indexOf(':')
        return { itemId: fieldKey.slice(0, separator), field: fieldKey.slice(separator + 1) }
      }),
    } satisfies ContentSyncStateWhisperPayload)
  }

  const requestSyncState = () => {
    const userId = presence.currentUser.value?.id
    if (!userId) return

    presence.whisper(SYNC_REQUEST_EVENT, { userId } satisfies ContentSyncRequestWhisperPayload)
  }

  const getCollaboratorsForField = (itemId: string, field: string): CollaborationPresenceUser[] => {
    const activeUserIds = fieldPresence.value[getFieldKey(itemId, field)] || []

    return activeUserIds
      .map((userId) => collaboratorMap.value.get(userId))
      .filter((user): user is CollaborationPresenceUser => user !== undefined)
  }

  watch(
    () => presence.isConnected.value,
    (isConnected) => {
      if (isConnected) {
        flushFieldUpdates()
        requestSyncState()
      }
    },
    { immediate: true }
  )

  watch(collaborators, (nextCollaborators) => {
    if (nextCollaborators.length > 0) {
      knownUsers.value = {
        ...knownUsers.value,
        ...Object.fromEntries(nextCollaborators.map((user) => [user.id, user])),
      }
    }

    const activeUserIds = new Set(nextCollaborators.map((user) => user.id))
    const nextFieldPresence = Object.fromEntries(
      Object.entries(fieldPresence.value)
        .map(([fieldKey, userIds]) => [
          fieldKey,
          userIds.filter((userId) => activeUserIds.has(userId)),
        ])
        .filter(([, userIds]) => userIds.length > 0)
    ) as Record<string, string[]>

    fieldPresence.value = nextFieldPresence
  })

  watch([() => unref(spaceIdRef), () => unref(contentIdRef)], () => {
    fieldPresence.value = {}
    clearLocalDraftState()
    remoteDraftFields.clear()
    remoteDraftIndex.value = {}
    localFocusedFields.clear()
    queuedBlockOperations = []
  })

  const discardOwnDrafts = () => {
    const userId = presence.currentUser.value?.id
    if (!userId || localDraftFields.size === 0) return

    presence.whisper(CONTENT_DISCARD_EVENT, {
      fields: Array.from(localDraftFields.values()).map((field) => ({
        itemId: field.itemId,
        field: field.field,
        previousValue: cloneValue(field.previousValue),
        value: cloneValue(field.previousValue),
      })),
      userId,
    } satisfies ContentDiscardWhisperPayload)

    clearLocalDraftState()
    clearFieldPresence(userId)
  }

  const broadcastPersistedContent = (
    nextContent: ContentResource,
    action: ContentCommitAction = 'save'
  ) => {
    const userId = presence.currentUser.value?.id
    if (!userId) return

    clearLocalDraftState()
    clearFieldPresence(userId)

    presence.whisper(CONTENT_COMMIT_EVENT, {
      action,
      content: cloneValue(nextContent),
      userId,
    } satisfies ContentCommitWhisperPayload)
  }

  const stopFieldUpdateListener = presence.onWhisper<ContentFieldWhisperPayload>(
    FIELD_UPDATE_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return
      applyRemoteFieldUpdate(payload)
    }
  )

  const stopFieldFocusListener = presence.onWhisper<ContentFieldFocusWhisperPayload>(
    FIELD_FOCUS_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return
      setFieldPresence(payload)
    }
  )

  const stopCommitListener = presence.onWhisper<ContentCommitWhisperPayload>(
    CONTENT_COMMIT_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return

      const affectedFields = clearRemoteDraftState(payload.userId)

      affectedFields.forEach((field) => {
        applyFieldValue(
          field.itemId,
          field.field,
          getFieldValue(payload.content, field.itemId, field.field)
        )
      })

      syncPersistedContent?.(
        payload.content,
        hasLocalUnsavedChanges?.() ? 'preserve-local' : 'replace'
      )
    }
  )

  const stopDiscardListener = presence.onWhisper<ContentDiscardWhisperPayload>(
    CONTENT_DISCARD_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return

      const remoteFields = clearRemoteDraftState(payload.userId)
      const fallbackFields = payload.fields.map((field) => ({
        itemId: field.itemId,
        field: field.field,
        previousValue: cloneValue(field.previousValue),
      }))

      ;(remoteFields.length > 0 ? remoteFields : fallbackFields).forEach((field) => {
        applyFieldValue(field.itemId, field.field, field.previousValue)
      })
    }
  )

  const stopBlockOperationListener = presence.onWhisper<ContentBlockOperationWhisperPayload>(
    BLOCK_OPERATION_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return
      applyRemoteBlockOperation(payload)
    }
  )

  const stopSyncRequestListener = presence.onWhisper<ContentSyncRequestWhisperPayload>(
    SYNC_REQUEST_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return

      const existingTimer = syncReplyTimers.get(payload.userId)
      if (existingTimer) clearTimeout(existingTimer)

      syncReplyTimers.set(
        payload.userId,
        setTimeout(
          () => {
            syncReplyTimers.delete(payload.userId)
            sendSyncState(payload.userId)
          },
          Math.random() * SYNC_REPLY_MAX_DELAY_MS
        )
      )
    }
  )

  const stopSyncStateListener = presence.onWhisper<ContentSyncStateWhisperPayload>(
    SYNC_STATE_EVENT,
    (payload) => {
      const currentUserId = presence.currentUser.value?.id
      if (!payload || payload.userId === currentUserId) return
      if (payload.requesterId !== currentUserId) return

      payload.fields.forEach((field) => {
        applyRemoteFieldUpdate({ ...field, userId: payload.userId })
      })

      payload.focusedFields.forEach((field) => {
        setFieldPresence({ ...field, focused: true, userId: payload.userId })
      })
    }
  )

  onBeforeUnmount(() => {
    flushFieldUpdates()
    syncReplyTimers.forEach((timer) => clearTimeout(timer))
    syncReplyTimers.clear()
    stopFieldUpdateListener()
    stopFieldFocusListener()
    stopCommitListener()
    stopDiscardListener()
    stopBlockOperationListener()
    stopSyncRequestListener()
    stopSyncStateListener()
  })

  return {
    collaborators,
    currentUser: presence.currentUser,
    isConnected: presence.isConnected,
    count: presence.count,
    broadcastPersistedContent,
    broadcastBlockOperation,
    discardOwnDrafts,
    queueFieldUpdate,
    updateFieldFocus,
    getCollaboratorsForField,
    getSubtreeCollaborators,
    getAggregatedCollaboratorsForField,
    getRemoteDraftCollaborators,
    remoteDraftCollaborators,
  }
}
