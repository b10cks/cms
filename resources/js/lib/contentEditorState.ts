import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'

/**
 * Dirty tracking and version-conflict state for the content editor pages.
 *
 * Lives in `lib/` rather than next to `useContentEditorPage` so the page
 * composable can import it explicitly without dropping anything out of the
 * auto-import map, and so the state machine can be tested without a component.
 */

export interface DirtyTracker {
  isDirty: ComputedRef<boolean>
  /** Re-baseline: the current document is now what the server holds. */
  markSaved: () => void
}

/**
 * Dirty tracking via an edit-version counter instead of stringifying the whole
 * document on every keystroke: any mutation bumps the counter, and `markSaved`
 * snapshots it as the new baseline. `flush: 'sync'` keeps the counter current so
 * a persist path can re-baseline immediately after writing.
 */
export function createEditVersionDirtyTracker(source: Ref<unknown>): DirtyTracker {
  const editVersion = ref(0)
  const savedVersion = ref(0)

  watch(source, () => editVersion.value++, { deep: true, flush: 'sync' })

  return {
    isDirty: computed(() => editVersion.value !== savedVersion.value),
    markSaved: () => {
      savedVersion.value = editVersion.value
    },
  }
}

/**
 * Dirty tracking by comparing the edited document against the persisted
 * baseline. Undoing an edit by hand makes the document clean again, which is
 * what the localization editor (a handful of fields) wants; `markSaved` is a
 * no-op there because replacing the baseline is the save.
 */
export function createSnapshotDirtyTracker(
  current: Ref<unknown>,
  baseline: Ref<unknown>
): DirtyTracker {
  // Lazy per side: each snapshot is recomputed only when its own document
  // changed and `isDirty` is actually read — never eagerly per reactive set.
  const currentSnapshot = computed(() => JSON.stringify(current.value))
  const baselineSnapshot = computed(() => JSON.stringify(baseline.value))

  return {
    isDirty: computed(() => {
      if (!current.value || !baseline.value) return false

      return currentSnapshot.value !== baselineSnapshot.value
    }),
    markSaved: () => {},
  }
}

/**
 * True once the server moved on from the version the editor started from —
 * someone else saved while this document was open. Unknown on either side means
 * no conflict: a document that was never anchored cannot have drifted.
 */
export function hasServerVersionDrifted(
  editingFromVersionId: string | null | undefined,
  serverVersionId: string | null | undefined
): boolean {
  return (
    editingFromVersionId != null && serverVersionId != null && serverVersionId !== editingFromVersionId
  )
}

export interface VersionConflictSources {
  /** Identity of the document being edited; a change re-anchors from scratch. */
  contentId: Ref<string | null | undefined>
  /** Version the server reports for that document. */
  serverVersionId: Ref<string | null | undefined>
  /** Version of the locally persisted baseline, compared against the anchor. */
  persistedVersionId: Ref<string | null | undefined>
}

export interface VersionConflictState {
  /** The version this editing session is based on; null until anchored. */
  editingFromVersionId: Ref<string | null>
  hasDrifted: ComputedRef<boolean>
  /** Anchor to a freshly committed version (save / publish). */
  anchor: (versionId: string | null | undefined) => void
  /** Drop the anchor so the next server version re-anchors the session. */
  reset: () => void
}

export function createVersionConflictState(sources: VersionConflictSources): VersionConflictState {
  const editingFromVersionId = ref<string | null>(null)

  // Reset when the content identity changes (navigation or language switch).
  watch(sources.contentId, (newId, oldId) => {
    if (newId !== oldId) {
      editingFromVersionId.value = null
    }
  })

  watch(
    sources.serverVersionId,
    (id) => {
      if (editingFromVersionId.value === null && id) {
        editingFromVersionId.value = id
      }
    },
    { immediate: true }
  )

  return {
    editingFromVersionId,
    hasDrifted: computed(() =>
      hasServerVersionDrifted(editingFromVersionId.value, sources.persistedVersionId.value)
    ),
    anchor: (versionId) => {
      editingFromVersionId.value = versionId ?? null
    },
    reset: () => {
      editingFromVersionId.value = null
    },
  }
}

/**
 * Structural equality for JSON-shaped values: key order does not matter, but
 * key presence does. Used to tell a real payload edit from a document that only
 * looks different because the editor hydrated schema defaults into it.
 */
export function isSameJsonValue(left: unknown, right: unknown): boolean {
  if (left === right) return true
  if (left == null || right == null || typeof left !== typeof right) return left === right
  if (typeof left !== 'object') return Object.is(left, right)

  if (Array.isArray(left) || Array.isArray(right)) {
    if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) {
      return false
    }

    return left.every((item, index) => isSameJsonValue(item, right[index]))
  }

  const leftRecord = left as Record<string, unknown>
  const rightRecord = right as Record<string, unknown>
  const leftKeys = Object.keys(leftRecord)
  if (leftKeys.length !== Object.keys(rightRecord).length) return false

  return leftKeys.every(
    (key) =>
      Object.prototype.hasOwnProperty.call(rightRecord, key) &&
      isSameJsonValue(leftRecord[key], rightRecord[key])
  )
}
