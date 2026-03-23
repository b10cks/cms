import type { ContentWizardDraftTree } from '~/types/content-wizard'

export interface ContentCanvasCommand<TResult = void> {
  label: string
  execute: () => TResult
  onCommitted?: (payload: {
    before: ContentWizardDraftTree
    after: ContentWizardDraftTree
    result: TResult
  }) => void
}

export interface ContentCanvasHistoryEntry {
  label: string
  before: ContentWizardDraftTree
  after: ContentWizardDraftTree
}

export function useContentCanvasCommands(options: {
  createSnapshot: () => ContentWizardDraftTree
  restoreSnapshot: (snapshot: ContentWizardDraftTree) => void
  onHistoryRestore?: (payload: {
    snapshot: ContentWizardDraftTree
    entry: ContentCanvasHistoryEntry
    direction: 'undo' | 'redo'
  }) => void
  serializeSnapshot?: (snapshot: ContentWizardDraftTree) => string
}) {
  const undoStack = ref<ContentCanvasHistoryEntry[]>([])
  const redoStack = ref<ContentCanvasHistoryEntry[]>([])

  const serializeSnapshot = (snapshot: ContentWizardDraftTree) =>
    options.serializeSnapshot?.(snapshot) || JSON.stringify(snapshot)

  const clearHistory = () => {
    undoStack.value = []
    redoStack.value = []
  }

  const recordSnapshotChange = (entry: ContentCanvasHistoryEntry) => {
    if (serializeSnapshot(entry.before) === serializeSnapshot(entry.after)) {
      return false
    }

    undoStack.value = [...undoStack.value, entry]
    redoStack.value = []

    return true
  }

  const executeCommand = <TResult>(command: ContentCanvasCommand<TResult>) => {
    const before = options.createSnapshot()
    const result = command.execute()
    const after = options.createSnapshot()

    if (recordSnapshotChange({ label: command.label, before, after })) {
      command.onCommitted?.({ before, after, result })

      return {
        changed: true,
        result,
      }
    }

    return {
      changed: false,
      result,
    }
  }

  const undo = () => {
    const entry = undoStack.value.at(-1)
    if (!entry) {
      return false
    }

    undoStack.value = undoStack.value.slice(0, -1)
    redoStack.value = [...redoStack.value, entry]
    options.restoreSnapshot(entry.before)
    options.onHistoryRestore?.({
      snapshot: entry.before,
      entry,
      direction: 'undo',
    })

    return true
  }

  const redo = () => {
    const entry = redoStack.value.at(-1)
    if (!entry) {
      return false
    }

    redoStack.value = redoStack.value.slice(0, -1)
    undoStack.value = [...undoStack.value, entry]
    options.restoreSnapshot(entry.after)
    options.onHistoryRestore?.({
      snapshot: entry.after,
      entry,
      direction: 'redo',
    })

    return true
  }

  return {
    canRedo: computed(() => redoStack.value.length > 0),
    canUndo: computed(() => undoStack.value.length > 0),
    clearHistory,
    executeCommand,
    recordSnapshotChange,
    redo,
    undo,
  }
}
