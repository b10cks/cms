import { afterEach, describe, expect, it } from 'vitest'
import { effectScope, nextTick, ref, type EffectScope } from 'vue'

import {
  createEditVersionDirtyTracker,
  createSnapshotDirtyTracker,
  createVersionConflictState,
  hasServerVersionDrifted,
  type VersionConflictState,
} from '~/lib/contentEditorState'

// The trackers register watchers; without a scope they outlive their test and
// keep firing against refs a later test still mutates.
let scope: EffectScope | undefined

const run = <T>(factory: () => T): T => {
  scope = effectScope()
  return scope.run(factory) as T
}

afterEach(() => {
  scope?.stop()
  scope = undefined
})

describe('createEditVersionDirtyTracker', () => {
  it('starts clean and turns dirty on a nested mutation', () => {
    const source = ref({ content: { title: 'Hello' } })
    const tracker = run(() => createEditVersionDirtyTracker(source))

    expect(tracker.isDirty.value).toBe(false)

    source.value.content.title = 'Hi'

    // flush: 'sync' — a persist path re-baselines in the same tick as the write.
    expect(tracker.isDirty.value).toBe(true)
  })

  it('is clean again after markSaved, and dirty again on the next edit', () => {
    const source = ref({ title: 'Hello' })
    const tracker = run(() => createEditVersionDirtyTracker(source))

    source.value.title = 'Hi'
    tracker.markSaved()
    expect(tracker.isDirty.value).toBe(false)

    source.value.title = 'Hello again'
    expect(tracker.isDirty.value).toBe(true)
  })

  it('stays dirty when an edit is undone by hand', () => {
    const source = ref({ title: 'Hello' })
    const tracker = run(() => createEditVersionDirtyTracker(source))

    source.value.title = 'Hi'
    source.value.title = 'Hello'

    // Counting mutations, not comparing documents: restoring the old value is
    // still two edits the editor has not persisted.
    expect(tracker.isDirty.value).toBe(true)
  })

  it('counts a wholesale replacement of the document', () => {
    const source = ref<{ title: string } | null>(null)
    const tracker = run(() => createEditVersionDirtyTracker(source))

    source.value = { title: 'Hello' }

    expect(tracker.isDirty.value).toBe(true)
  })
})

describe('createSnapshotDirtyTracker', () => {
  it('is clean while either side is missing', () => {
    const current = ref<{ title: string } | null>(null)
    const baseline = ref<{ title: string } | null>({ title: 'Hello' })
    const tracker = run(() => createSnapshotDirtyTracker(current, baseline))

    expect(tracker.isDirty.value).toBe(false)

    current.value = { title: 'Hello' }
    baseline.value = null
    expect(tracker.isDirty.value).toBe(false)
  })

  it('compares against the baseline, so undoing an edit is clean again', () => {
    const current = ref({ title: 'Hello' })
    const baseline = ref({ title: 'Hello' })
    const tracker = run(() => createSnapshotDirtyTracker(current, baseline))

    expect(tracker.isDirty.value).toBe(false)

    current.value.title = 'Hi'
    expect(tracker.isDirty.value).toBe(true)

    current.value.title = 'Hello'
    expect(tracker.isDirty.value).toBe(false)
  })

  it('goes clean when the baseline is replaced with the edited document', () => {
    const current = ref({ title: 'Hi' })
    const baseline = ref({ title: 'Hello' })
    const tracker = run(() => createSnapshotDirtyTracker(current, baseline))

    expect(tracker.isDirty.value).toBe(true)

    // Replacing the baseline *is* the save here, which is why markSaved is a no-op.
    baseline.value = { title: 'Hi' }
    tracker.markSaved()
    expect(tracker.isDirty.value).toBe(false)
  })
})

describe('hasServerVersionDrifted', () => {
  it('reports no drift while either version is unknown', () => {
    expect(hasServerVersionDrifted(null, 'v2')).toBe(false)
    expect(hasServerVersionDrifted(undefined, 'v2')).toBe(false)
    expect(hasServerVersionDrifted('v1', null)).toBe(false)
    expect(hasServerVersionDrifted('v1', undefined)).toBe(false)
  })

  it('reports drift only when the server moved to another version', () => {
    expect(hasServerVersionDrifted('v1', 'v1')).toBe(false)
    expect(hasServerVersionDrifted('v1', 'v2')).toBe(true)
  })
})

describe('createVersionConflictState', () => {
  const setup = (initial: { server?: string | null; persisted?: string | null } = {}) => {
    const contentId = ref<string | null>('content-1')
    const serverVersionId = ref<string | null>(initial.server ?? null)
    const persistedVersionId = ref<string | null>(initial.persisted ?? null)
    const state = run(() =>
      createVersionConflictState({ contentId, serverVersionId, persistedVersionId })
    )

    return { contentId, serverVersionId, persistedVersionId, state }
  }

  it('anchors to the first version the server reports and ignores later ones', async () => {
    const { serverVersionId, state } = setup({ server: 'v1' })

    expect(state.editingFromVersionId.value).toBe('v1')

    // A peer saving mid-session must not silently re-anchor this session.
    serverVersionId.value = 'v2'
    await nextTick()
    expect(state.editingFromVersionId.value).toBe('v1')
  })

  it('drifts once the persisted baseline moves past the anchor', async () => {
    const { persistedVersionId, state } = setup({ server: 'v1', persisted: 'v1' })

    expect(state.hasDrifted.value).toBe(false)

    persistedVersionId.value = 'v2'
    await nextTick()
    expect(state.hasDrifted.value).toBe(true)
  })

  it('clears the drift when the session re-anchors to the committed version', async () => {
    const { persistedVersionId, state } = setup({ server: 'v1', persisted: 'v2' })

    expect(state.hasDrifted.value).toBe(true)

    state.anchor(persistedVersionId.value)
    await nextTick()
    expect(state.editingFromVersionId.value).toBe('v2')
    expect(state.hasDrifted.value).toBe(false)
  })

  it('treats a commit without a version as unanchored', () => {
    const { state }: { state: VersionConflictState } = setup({ server: 'v1', persisted: 'v2' })

    state.anchor(undefined)

    expect(state.editingFromVersionId.value).toBeNull()
    expect(state.hasDrifted.value).toBe(false)
  })

  it('re-anchors from the server after a reset', async () => {
    const { serverVersionId, persistedVersionId, state } = setup({ server: 'v1', persisted: 'v2' })

    // Reloading the server content drops the anchor so the reloaded version wins.
    state.reset()
    expect(state.hasDrifted.value).toBe(false)

    serverVersionId.value = 'v2'
    await nextTick()
    expect(state.editingFromVersionId.value).toBe('v2')
    expect(persistedVersionId.value).toBe('v2')
    expect(state.hasDrifted.value).toBe(false)
  })

  it('drops the anchor when the edited document changes identity', async () => {
    const { contentId, serverVersionId, state } = setup({ server: 'v1' })

    // Switching language navigates to a different content row.
    contentId.value = 'content-2'
    await nextTick()
    expect(state.editingFromVersionId.value).toBeNull()

    serverVersionId.value = 'v9'
    await nextTick()
    expect(state.editingFromVersionId.value).toBe('v9')
  })
})
