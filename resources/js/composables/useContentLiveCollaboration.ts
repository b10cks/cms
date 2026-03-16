import type { Ref } from 'vue'

import { getPresenceColor } from '~/components/ui/presence-colors'
import type { ContentResource } from '~/types/contents'

import type { PresenceUser } from './usePresence'

const FIELD_UPDATE_EVENT = 'content-field-update'
const FIELD_FOCUS_EVENT = 'content-field-focus'
const CONTENT_COMMIT_EVENT = 'content-commit'
const CONTENT_DISCARD_EVENT = 'content-discard'

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
  syncPersistedContent?: (
    content: ContentResource,
    mode: 'replace' | 'preserve-local'
  ) => void
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

  const applyRemoteFieldUpdate = (payload: ContentFieldWhisperPayload) => {
    const fieldKey = getFieldKey(payload.itemId, payload.field)
    const userDrafts = remoteDraftFields.get(payload.userId) ?? new Map<string, DraftFieldSnapshot>()

    if (!remoteDraftFields.has(payload.userId)) {
      remoteDraftFields.set(payload.userId, userDrafts)
    }

    if (!userDrafts.has(fieldKey)) {
      userDrafts.set(fieldKey, {
        itemId: payload.itemId,
        field: payload.field,
        previousValue: cloneValue(
          payload.previousValue ?? getFieldValue(content.value, payload.itemId, payload.field)
        ),
      })
    }

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

    if (!payload.focused) {
      flushFieldUpdate(getFieldKey(payload.itemId, payload.field))
    }

    presence.whisper(FIELD_FOCUS_EVENT, {
      ...payload,
      userId,
    })
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
      }
    },
    { immediate: true }
  )

  watch(collaborators, (nextCollaborators) => {
    const activeUserIds = new Set(nextCollaborators.map((user) => user.id))
    const nextFieldPresence = Object.fromEntries(
      Object.entries(fieldPresence.value)
        .map(([fieldKey, userIds]) => [fieldKey, userIds.filter((userId) => activeUserIds.has(userId))])
        .filter(([, userIds]) => userIds.length > 0)
    ) as Record<string, string[]>

    fieldPresence.value = nextFieldPresence
  })

  watch([() => unref(spaceIdRef), () => unref(contentIdRef)], () => {
    fieldPresence.value = {}
    clearLocalDraftState()
    remoteDraftFields.clear()
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

  onBeforeUnmount(() => {
    flushFieldUpdates()
    stopFieldUpdateListener()
    stopFieldFocusListener()
    stopCommitListener()
    stopDiscardListener()
  })

  return {
    collaborators,
    currentUser: presence.currentUser,
    isConnected: presence.isConnected,
    count: presence.count,
    broadcastPersistedContent,
    discardOwnDrafts,
    queueFieldUpdate,
    updateFieldFocus,
    getCollaboratorsForField,
  }
}
