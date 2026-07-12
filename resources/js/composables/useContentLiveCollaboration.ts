import { useQueryClient } from '@tanstack/vue-query'
import { toRaw } from 'vue'
import type { Ref } from 'vue'

import { getPresenceColor } from '~/components/ui/presence-colors'
import type { ContentResource } from '~/types/contents'

import type { PresenceUser } from './usePresence'
import { queryKeys } from './useQueryClient'

const FIELD_UPDATE_EVENT = 'content-field-update'
const FIELD_FOCUS_EVENT = 'content-field-focus'
const CONTENT_COMMIT_EVENT = 'content-commit'
const CONTENT_DISCARD_EVENT = 'content-discard'
const BLOCK_OPERATION_EVENT = 'content-block-operation'
const SYNC_REQUEST_EVENT = 'content-sync-request'
const SYNC_STATE_EVENT = 'content-sync-state'
const COMMENT_UPDATE_EVENT = 'content-comment-update'

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
  // Opaque routing info carried through whispers for custom field adapters
  // (e.g. the localization editor whispers path + block stamps here).
  meta?: unknown
}

export interface ContentFieldRef {
  itemId: string
  field: string
  meta?: unknown
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

interface ContentCommentUpdateWhisperPayload {
  userId: string
}

interface UseContentLiveCollaborationOptions {
  content: Ref<ContentResource | null>
  hasLocalUnsavedChanges?: () => boolean
  syncPersistedContent?: (content: ContentResource, mode: 'replace' | 'preserve-local') => void
  syncPreviewItem?: (item: Record<string, unknown>) => void
  syncInterval?: number
  // Overrides how field values are read from / written into content. Editors
  // whose fields aren't addressable as (itemId, field) on the content tree
  // (e.g. path-based localization fields) provide their own resolution via
  // the payload's `meta`.
  fieldValueAdapter?: {
    get: (source: ContentResource, field: ContentFieldRef) => unknown
    apply: (field: ContentFieldRef, value: unknown) => void
  }
}

interface DraftFieldSnapshot {
  field: string
  itemId: string
  previousValue: unknown
  meta?: unknown
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

// Structural equality, used to detect when a field has been edited back to its
// clean value so its dirty indicator can be cleared. Object comparison is
// key-order independent; values here are scalars, arrays or plain objects.
const valuesEqual = (a: unknown, b: unknown): boolean => {
  if (a === b) return true
  if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') {
    return false
  }

  if (Array.isArray(a) || Array.isArray(b)) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false
    return a.every((value, index) => valuesEqual(value, b[index]))
  }

  const aObj = a as Record<string, unknown>
  const bObj = b as Record<string, unknown>
  const aKeys = Object.keys(aObj)
  if (aKeys.length !== Object.keys(bObj).length) return false

  return aKeys.every((key) => Object.hasOwn(bObj, key) && valuesEqual(aObj[key], bObj[key]))
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
    fieldValueAdapter,
  }: UseContentLiveCollaborationOptions
) {
  const presence = useContentPresence(spaceIdRef, contentIdRef)
  const queryClient = useQueryClient()
  const fieldPresence = ref<Record<string, string[]>>({})
  const pendingFieldUpdates = new Map<string, ContentFieldWhisperPayload>()
  const localDraftFields = new Map<string, DraftFieldSnapshot>()
  const remoteDraftFields = new Map<string, Map<string, DraftFieldSnapshot>>()
  const fieldFlushTimers = new Map<string, ReturnType<typeof setTimeout>>()
  const localFocusedFields = new Set<string>()
  // Reactive mirrors of the draft maps (fieldKeys) so the UI can render dirty state.
  const remoteDraftIndex = ref<Record<string, string[]>>({})
  const localDraftIndex = ref<string[]>([])
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

  // Bumped whenever the block tree gains/loses/reorders items (local broadcast or
  // remote apply). itemTrailIndex keys off this instead of deep-tracking content,
  // so plain field edits (typing) no longer invalidate presence/draft aggregation.
  const structureVersion = ref(0)
  const bumpStructureVersion = () => {
    structureVersion.value++
  }

  // Maps every content item id to its ancestor chain as (itemId, field) pairs,
  // so field-level presence/dirty state can bubble up through the blocks tree.
  const itemTrailIndex = computed(() => {
    // Structure-only dependencies: a wholesale content replace (the ref itself is
    // reassigned on navigation/save/AI) or a structural block op (structureVersion
    // bump). Field-value edits mutate leaves in place, so we walk the raw tree to
    // avoid registering a deep reactive dependency that would re-run per keystroke.
    void structureVersion.value
    const index = new Map<string, Array<{ itemId: string; field: string }>>()
    const root = content.value ? (toRaw(content.value) as ContentResource) : null
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

  const aggregatedLocalDrafts = computed(() =>
    aggregateFieldKeys(localDraftIndex.value.map((fieldKey) => [fieldKey, ['local']]))
  )

  const hasLocalDraft = (itemId: string, field?: string): boolean =>
    field
      ? aggregatedLocalDrafts.value.byField.has(getFieldKey(itemId, field))
      : aggregatedLocalDrafts.value.byItem.has(itemId)

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

  const selfCollaborator = computed<CollaborationPresenceUser | null>(() => {
    const user = presence.currentUser.value
    if (!user) return null

    const color = getPresenceColor(user.id)

    return {
      ...user,
      joined_at: '',
      color: color.value,
      colorLabel: color.label,
    }
  })

  // Unified dirty-state lookup: everyone (including the current user) who has
  // unsaved changes on the field, or anywhere in the item's subtree when no
  // field is given. Remote owners first so their color wins on shared fields.
  const getDraftOwners = (itemId: string, field?: string): CollaborationPresenceUser[] => {
    const owners = getRemoteDraftCollaborators(itemId, field)

    if (hasLocalDraft(itemId, field) && selfCollaborator.value) {
      return [...owners, selfCollaborator.value]
    }

    return owners
  }

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
    field: string,
    meta?: unknown
  ): unknown => {
    if (!source) return undefined

    if (fieldValueAdapter) {
      return fieldValueAdapter.get(source, { itemId, field, meta })
    }

    if (itemId === source.id) {
      return (source.content as Record<string, unknown>)?.[field]
    }

    return findNestedObjectById(source.content, itemId)?.[field]
  }

  const applyFieldValue = (itemId: string, field: string, value: unknown, meta?: unknown) => {
    if (!content.value) return

    const nextValue = cloneValue(value)

    if (fieldValueAdapter) {
      fieldValueAdapter.apply({ itemId, field, meta }, nextValue)
      return
    }

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

  const syncLocalDraftIndex = () => {
    if (localDraftIndex.value.length !== localDraftFields.size) {
      localDraftIndex.value = Array.from(localDraftFields.keys())
    }
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
    previousValue: unknown,
    meta?: unknown
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
        meta,
      })
      syncRemoteDraftIndex()
    }
  }

  const removeRemoteDraft = (userId: string, itemId: string, field: string) => {
    const userDrafts = remoteDraftFields.get(userId)
    if (!userDrafts) return

    if (userDrafts.delete(getFieldKey(itemId, field))) {
      if (userDrafts.size === 0) {
        remoteDraftFields.delete(userId)
      }
      syncRemoteDraftIndex()
    }
  }

  const applyRemoteFieldUpdate = (payload: ContentFieldWhisperPayload) => {
    const previousValue =
      payload.previousValue ??
      getFieldValue(content.value, payload.itemId, payload.field, payload.meta)

    // A collaborator reverting a field to its clean value clears its dirty ring.
    if (valuesEqual(payload.value, previousValue)) {
      removeRemoteDraft(payload.userId, payload.itemId, payload.field)
    } else {
      recordRemoteDraft(payload.userId, payload.itemId, payload.field, previousValue, payload.meta)
    }

    applyFieldValue(payload.itemId, payload.field, payload.value, payload.meta)
  }

  const clearLocalDraftState = () => {
    fieldFlushTimers.forEach((timer) => clearTimeout(timer))
    fieldFlushTimers.clear()
    localDraftFields.clear()
    localDraftIndex.value = []
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

    const existingDraft = localDraftFields.get(fieldKey)
    // Compare against the value captured before the first edit (the snapshot),
    // not the immediately preceding value the editor emits per keystroke.
    const cleanValue = existingDraft ? existingDraft.previousValue : cloneValue(payload.previousValue)
    const reverted = valuesEqual(payload.value, cleanValue)

    if (reverted) {
      // Edited back to the clean value: drop the dirty state. We still whisper
      // the reverted value below so collaborators clear their indicator too.
      if (existingDraft) {
        localDraftFields.delete(fieldKey)
        syncLocalDraftIndex()
      }
    } else if (!existingDraft) {
      localDraftFields.set(fieldKey, {
        itemId: payload.itemId,
        field: payload.field,
        previousValue: cloneValue(payload.previousValue),
        meta: payload.meta,
      })
      syncLocalDraftIndex()
    }

    pendingFieldUpdates.set(fieldKey, {
      ...payload,
      previousValue: cloneValue(cleanValue),
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

    // The editor has already mutated content.value in place by now; the trail
    // index depends on this bump to pick up the new/removed/reordered items.
    if (payload.type !== 'visibility') bumpStructureVersion()

    if (!localDraftFields.has(fieldKey)) {
      localDraftFields.set(fieldKey, {
        itemId: parentId,
        field,
        previousValue: cloneValue(payload.previousValue),
      })
      syncLocalDraftIndex()
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
    // A nested apply mutates the raw tree in place, so the trail index needs an
    // explicit bump; root-level applies reassign content.value and self-invalidate.
    if (op.type !== 'visibility') bumpStructureVersion()

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
        value: cloneValue(
          getFieldValue(content.value, snapshot.itemId, snapshot.field, snapshot.meta)
        ),
        meta: snapshot.meta,
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
        meta: field.meta,
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

  // Comments (incl. replies and reactions) are persisted via the REST API; the
  // whisper just tells peers to refetch, so the server stays the source of truth.
  const broadcastCommentUpdate = () => {
    const userId = presence.currentUser.value?.id
    if (!userId) return

    presence.whisper(COMMENT_UPDATE_EVENT, {
      userId,
    } satisfies ContentCommentUpdateWhisperPayload)
  }

  const stopCommentUpdateListener = presence.onWhisper<ContentCommentUpdateWhisperPayload>(
    COMMENT_UPDATE_EVENT,
    (payload) => {
      if (!payload || payload.userId === presence.currentUser.value?.id) return

      const spaceId = unref(spaceIdRef)
      const contentId = unref(contentIdRef)
      if (!spaceId || !contentId) return

      queryClient.invalidateQueries({
        queryKey: queryKeys.comments(spaceId, contentId).all(),
      })
    }
  )

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
          getFieldValue(payload.content, field.itemId, field.field, field.meta),
          field.meta
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
        meta: field.meta,
      }))

      ;(remoteFields.length > 0 ? remoteFields : fallbackFields).forEach((field) => {
        applyFieldValue(field.itemId, field.field, field.previousValue, field.meta)
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
    stopCommentUpdateListener()
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
    broadcastCommentUpdate,
    discardOwnDrafts,
    queueFieldUpdate,
    updateFieldFocus,
    getCollaboratorsForField,
    getSubtreeCollaborators,
    getAggregatedCollaboratorsForField,
    getRemoteDraftCollaborators,
    remoteDraftCollaborators,
    hasLocalDraft,
    getDraftOwners,
  }
}
