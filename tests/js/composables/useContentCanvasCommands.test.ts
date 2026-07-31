import { describe, expect, it, vi } from 'vitest'

import type { ContentWizardDraftNode, ContentWizardDraftTree } from '~/types/content-wizard'

import { useContentCanvasCommands } from '~/composables/useContentCanvasCommands'

const node = (id: string): ContentWizardDraftNode => ({ id }) as ContentWizardDraftNode

const treeOf = (...nodeIds: string[]): ContentWizardDraftTree => ({
  rootId: 'root',
  nodes: Object.fromEntries(nodeIds.map((id) => [id, node(id)])),
})

/**
 * Stands in for useContentWizardTree: `state` is the live tree, createSnapshot
 * copies it and restoreSnapshot writes it back, so history is exercised against
 * something that actually changes.
 */
const createTreeStub = (initial: string[] = []) => {
  const state = { current: treeOf(...initial) }

  return {
    state,
    ids: () => Object.keys(state.current.nodes),
    createSnapshot: () => treeOf(...Object.keys(state.current.nodes)),
    restoreSnapshot: (snapshot: ContentWizardDraftTree) => {
      state.current = treeOf(...Object.keys(snapshot.nodes))
    },
    add: (id: string) => {
      state.current = treeOf(...Object.keys(state.current.nodes), id)
    },
  }
}

const setup = (initial: string[] = []) => {
  const stub = createTreeStub(initial)
  const onHistoryRestore = vi.fn()
  const history = useContentCanvasCommands({
    createSnapshot: stub.createSnapshot,
    restoreSnapshot: stub.restoreSnapshot,
    onHistoryRestore,
  })

  return { ...stub, history, onHistoryRestore }
}

describe('executeCommand', () => {
  it('runs the command and reports the change', () => {
    const { history, add, ids } = setup(['a'])

    const outcome = history.executeCommand({
      label: 'add-node',
      execute: () => {
        add('b')
        return 'b'
      },
    })

    expect(outcome).toEqual({ changed: true, result: 'b' })
    expect(ids()).toEqual(['a', 'b'])
    expect(history.canUndo.value).toBe(true)
  })

  it('reports no change and records nothing when the tree is untouched', () => {
    const { history } = setup(['a'])

    const outcome = history.executeCommand({ label: 'noop', execute: () => 42 })

    expect(outcome).toEqual({ changed: false, result: 42 })
    expect(history.canUndo.value).toBe(false)
  })

  it('still returns the command result when nothing changed', () => {
    const { history } = setup()

    expect(history.executeCommand({ label: 'noop', execute: () => 'value' }).result).toBe('value')
  })

  it('calls onCommitted with both snapshots and the result', () => {
    const { history, add } = setup(['a'])
    const onCommitted = vi.fn()

    history.executeCommand({ label: 'add-node', execute: () => add('b'), onCommitted })

    expect(onCommitted).toHaveBeenCalledTimes(1)
    expect(Object.keys(onCommitted.mock.calls[0][0].before.nodes)).toEqual(['a'])
    expect(Object.keys(onCommitted.mock.calls[0][0].after.nodes)).toEqual(['a', 'b'])
  })

  it('does not call onCommitted for a no-op — nothing to broadcast', () => {
    const { history } = setup(['a'])
    const onCommitted = vi.fn()

    history.executeCommand({ label: 'noop', execute: () => undefined, onCommitted })

    expect(onCommitted).not.toHaveBeenCalled()
  })

  it('stacks one entry per changing command', () => {
    const { history, add } = setup()

    history.executeCommand({ label: 'first', execute: () => add('a') })
    history.executeCommand({ label: 'second', execute: () => add('b') })
    history.undo()
    history.undo()

    expect(history.canUndo.value).toBe(false)
  })

  it('drops the redo stack once a new command lands', () => {
    const { history, add } = setup()

    history.executeCommand({ label: 'first', execute: () => add('a') })
    history.undo()
    expect(history.canRedo.value).toBe(true)

    history.executeCommand({ label: 'second', execute: () => add('b') })

    expect(history.canRedo.value).toBe(false)
  })

  // A throwing command escaped before the after-snapshot, so its partial
  // mutation stayed with no history entry to undo it. The error still reaches
  // the caller, but the tree is back where it started.
  it('rolls a throwing command back and re-throws', () => {
    const { history, add, ids } = setup()

    expect(() =>
      history.executeCommand({
        label: 'half',
        execute: () => {
          add('a')
          throw new Error('nope')
        },
      })
    ).toThrow('nope')

    expect(ids()).toEqual([])
    expect(history.canUndo.value).toBe(false)
  })
})

describe('recordSnapshotChange', () => {
  it('records a differing pair', () => {
    const { history } = setup()

    expect(
      history.recordSnapshotChange({ label: 'manual', before: treeOf('a'), after: treeOf('a', 'b') })
    ).toBe(true)
    expect(history.canUndo.value).toBe(true)
  })

  it('rejects an identical pair', () => {
    const { history } = setup()

    expect(
      history.recordSnapshotChange({ label: 'manual', before: treeOf('a'), after: treeOf('a') })
    ).toBe(false)
    expect(history.canUndo.value).toBe(false)
  })

  it('compares by serialized value, not by identity', () => {
    const { history } = setup()
    const before = treeOf('a')

    expect(history.recordSnapshotChange({ label: 'manual', before, after: treeOf('a') })).toBe(false)
  })

  it('treats a key reorder as a change — JSON.stringify is order sensitive', () => {
    const { history } = setup()

    expect(
      history.recordSnapshotChange({
        label: 'manual',
        before: treeOf('a', 'b'),
        after: treeOf('b', 'a'),
      })
    ).toBe(true)
  })
})

describe('serializeSnapshot option', () => {
  it('uses the supplied serializer, so volatile fields can be ignored', () => {
    const history = useContentCanvasCommands({
      createSnapshot: () => treeOf('a'),
      restoreSnapshot: () => undefined,
      serializeSnapshot: (snapshot) => Object.keys(snapshot.nodes).sort().join(','),
    })

    expect(
      history.recordSnapshotChange({
        label: 'manual',
        before: treeOf('a', 'b'),
        after: treeOf('b', 'a'),
      })
    ).toBe(false)
  })

  // ODDITY: the result is combined with `|| JSON.stringify(snapshot)`, so a
  // serializer that legitimately returns '' (an empty tree, say) silently falls
  // back to the full JSON on that side of the comparison.
  it('falls back to JSON when the serializer returns an empty string', () => {
    const serialize = vi.fn((snapshot: ContentWizardDraftTree) =>
      Object.keys(snapshot.nodes).join(',')
    )
    const history = useContentCanvasCommands({
      createSnapshot: () => treeOf(),
      restoreSnapshot: () => undefined,
      serializeSnapshot: serialize,
    })

    // Both sides serialize to '' yet the pair is recorded, because both sides
    // fell through to JSON.stringify and the rootIds differ.
    expect(
      history.recordSnapshotChange({
        label: 'manual',
        before: { rootId: 'one', nodes: {} },
        after: { rootId: 'two', nodes: {} },
      })
    ).toBe(true)
    expect(serialize).toHaveBeenCalledTimes(2)
  })
})

describe('undo', () => {
  it('restores the state from before the last command', () => {
    const { history, add, ids } = setup(['a'])

    history.executeCommand({ label: 'add-node', execute: () => add('b') })

    expect(history.undo()).toBe(true)
    expect(ids()).toEqual(['a'])
  })

  it('unwinds several commands in reverse order', () => {
    const { history, add, ids } = setup()

    history.executeCommand({ label: 'first', execute: () => add('a') })
    history.executeCommand({ label: 'second', execute: () => add('b') })

    history.undo()
    expect(ids()).toEqual(['a'])

    history.undo()
    expect(ids()).toEqual([])
  })

  it('reports false and does nothing on an empty stack', () => {
    const { history, onHistoryRestore } = setup(['a'])

    expect(history.undo()).toBe(false)
    expect(onHistoryRestore).not.toHaveBeenCalled()
  })

  it('notifies the listener with the restored snapshot and the direction', () => {
    const { history, add, onHistoryRestore } = setup(['a'])

    history.executeCommand({ label: 'add-node', execute: () => add('b') })
    history.undo()

    expect(onHistoryRestore).toHaveBeenCalledTimes(1)
    expect(onHistoryRestore.mock.calls[0][0].direction).toBe('undo')
    expect(onHistoryRestore.mock.calls[0][0].entry.label).toBe('add-node')
    expect(Object.keys(onHistoryRestore.mock.calls[0][0].snapshot.nodes)).toEqual(['a'])
  })

  it('moves the entry onto the redo stack', () => {
    const { history, add } = setup()

    history.executeCommand({ label: 'add-node', execute: () => add('a') })
    history.undo()

    expect(history.canUndo.value).toBe(false)
    expect(history.canRedo.value).toBe(true)
  })
})

describe('redo', () => {
  it('reapplies the undone state', () => {
    const { history, add, ids } = setup(['a'])

    history.executeCommand({ label: 'add-node', execute: () => add('b') })
    history.undo()

    expect(history.redo()).toBe(true)
    expect(ids()).toEqual(['a', 'b'])
  })

  it('reports false and does nothing on an empty stack', () => {
    const { history, onHistoryRestore } = setup(['a'])

    expect(history.redo()).toBe(false)
    expect(onHistoryRestore).not.toHaveBeenCalled()
  })

  it('notifies the listener with the after snapshot and the direction', () => {
    const { history, add, onHistoryRestore } = setup(['a'])

    history.executeCommand({ label: 'add-node', execute: () => add('b') })
    history.undo()
    history.redo()

    expect(onHistoryRestore.mock.calls[1][0].direction).toBe('redo')
    expect(Object.keys(onHistoryRestore.mock.calls[1][0].snapshot.nodes)).toEqual(['a', 'b'])
  })

  it('survives a full undo/redo cycle over several commands', () => {
    const { history, add, ids } = setup()

    history.executeCommand({ label: 'first', execute: () => add('a') })
    history.executeCommand({ label: 'second', execute: () => add('b') })
    history.undo()
    history.undo()
    history.redo()
    history.redo()

    expect(ids()).toEqual(['a', 'b'])
    expect(history.canRedo.value).toBe(false)
  })
})

describe('clearHistory', () => {
  it('empties both stacks', () => {
    const { history, add } = setup()

    history.executeCommand({ label: 'first', execute: () => add('a') })
    history.undo()
    history.executeCommand({ label: 'second', execute: () => add('b') })
    history.clearHistory()

    expect(history.canUndo.value).toBe(false)
    expect(history.canRedo.value).toBe(false)
    expect(history.undo()).toBe(false)
    expect(history.redo()).toBe(false)
  })

  it('leaves the tree itself untouched', () => {
    const { history, add, ids } = setup(['a'])

    history.executeCommand({ label: 'add-node', execute: () => add('b') })
    history.clearHistory()

    expect(ids()).toEqual(['a', 'b'])
  })
})
