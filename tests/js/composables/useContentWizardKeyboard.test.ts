import { beforeEach, describe, expect, it, vi } from 'vitest'

import { CONTENT_WIZARD_ROOT_ID } from '~/types/content-wizard'

import { useContentWizardKeyboard } from '~/composables/useContentWizardKeyboard'

type KeyboardOptions = Parameters<typeof useContentWizardKeyboard>[0]
type WizardNode = NonNullable<ReturnType<KeyboardOptions['getNode']>>

const node = (id: string, overrides: Partial<WizardNode> = {}): WizardNode => ({
  id,
  parentId: CONTENT_WIZARD_ROOT_ID,
  childrenIds: [],
  isRootVirtual: false,
  ...overrides,
})

const rootNode = (childrenIds: string[] = []): WizardNode =>
  node(CONTENT_WIZARD_ROOT_ID, { parentId: null, childrenIds, isRootVirtual: true })

const handlers = () => ({
  focusNode: vi.fn(),
  createNodeFromPreferredBlock: vi.fn(() => true),
  openAddMenu: vi.fn(() => true),
  toggleDelete: vi.fn(),
  startEditing: vi.fn(),
  clearTransientState: vi.fn(),
})

let options: ReturnType<typeof handlers>

/**
 * Mirrors the real tree's getNode: a null/undefined id resolves to the virtual
 * root, which is what makes root-level siblings navigable at all.
 */
const setup = (...nodes: WizardNode[]) => {
  const byId = new Map(nodes.map((entry) => [entry.id, entry]))

  return useContentWizardKeyboard({
    ...options,
    getNode: (nodeId) =>
      (nodeId ? byId.get(nodeId) : byId.get(CONTENT_WIZARD_ROOT_ID)) ?? null,
  })
}

const element = (html: string) => {
  const host = document.createElement('div')
  host.innerHTML = html

  return host.firstElementChild as HTMLElement
}

interface KeyOptions {
  altKey?: boolean
  metaKey?: boolean
  ctrlKey?: boolean
  shiftKey?: boolean
  target?: HTMLElement
}

const key = (name: string, { target, ...modifiers }: KeyOptions = {}) => {
  const event = {
    key: name,
    altKey: false,
    metaKey: false,
    ctrlKey: false,
    shiftKey: false,
    ...modifiers,
    target: target ?? document.createElement('div'),
    preventDefault: vi.fn(),
  }

  return event as unknown as KeyboardEvent & { preventDefault: ReturnType<typeof vi.fn> }
}

beforeEach(() => {
  options = handlers()
})

describe('registration', () => {
  it('registers no global listeners, so there is nothing to leak on unmount', () => {
    const addWindow = vi.spyOn(window, 'addEventListener')
    const addDocument = vi.spyOn(document, 'addEventListener')

    setup(rootNode())

    expect(addWindow).not.toHaveBeenCalled()
    expect(addDocument).not.toHaveBeenCalled()

    addWindow.mockRestore()
    addDocument.mockRestore()
  })
})

describe('Tab', () => {
  it('creates a child from the preferred block', () => {
    const event = key('Tab')

    setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

    expect(options.createNodeFromPreferredBlock).toHaveBeenCalledWith('a', 'child')
    expect(options.openAddMenu).not.toHaveBeenCalled()
    expect(event.preventDefault).toHaveBeenCalled()
  })

  it('opens the add menu when no preferred block applies', () => {
    options.createNodeFromPreferredBlock.mockReturnValue(false)

    setup(rootNode(['a']), node('a')).handleKeydown(key('Tab'), 'a')

    expect(options.openAddMenu).toHaveBeenCalledWith('a', 'child')
  })

  it('skips the preferred block and opens the menu when Alt is held', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(key('Tab', { altKey: true }), 'a')

    expect(options.createNodeFromPreferredBlock).not.toHaveBeenCalled()
    expect(options.openAddMenu).toHaveBeenCalledWith('a', 'child')
  })

  // Alt forces the block picker on the root as well, matching Alt+Enter there.
  it('honours Alt on the virtual root', () => {
    setup(rootNode()).handleKeydown(key('Tab', { altKey: true }), CONTENT_WIZARD_ROOT_ID)

    expect(options.openAddMenu).toHaveBeenCalledWith(CONTENT_WIZARD_ROOT_ID, 'child')
    expect(options.createNodeFromPreferredBlock).not.toHaveBeenCalled()
  })

  it('still adds a child for an unknown node id', () => {
    setup(rootNode()).handleKeydown(key('Tab'), 'ghost')

    expect(options.createNodeFromPreferredBlock).toHaveBeenCalledWith('ghost', 'child')
  })
})

describe('Enter', () => {
  it('creates a sibling of an ordinary node', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(key('Enter'), 'a')

    expect(options.createNodeFromPreferredBlock).toHaveBeenCalledWith('a', 'sibling')
  })

  it('falls back to the sibling add menu', () => {
    options.createNodeFromPreferredBlock.mockReturnValue(false)

    setup(rootNode(['a']), node('a')).handleKeydown(key('Enter'), 'a')

    expect(options.openAddMenu).toHaveBeenCalledWith('a', 'sibling')
  })

  it('opens the sibling add menu directly with Alt', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(key('Enter', { altKey: true }), 'a')

    expect(options.createNodeFromPreferredBlock).not.toHaveBeenCalled()
    expect(options.openAddMenu).toHaveBeenCalledWith('a', 'sibling')
  })

  it('creates a child on the virtual root, which has no siblings', () => {
    setup(rootNode()).handleKeydown(key('Enter'), CONTENT_WIZARD_ROOT_ID)

    expect(options.createNodeFromPreferredBlock).toHaveBeenCalledWith(
      CONTENT_WIZARD_ROOT_ID,
      'child'
    )
  })

  it('opens the child add menu on the virtual root with Alt', () => {
    setup(rootNode()).handleKeydown(key('Enter', { altKey: true }), CONTENT_WIZARD_ROOT_ID)

    expect(options.openAddMenu).toHaveBeenCalledWith(CONTENT_WIZARD_ROOT_ID, 'child')
    expect(options.createNodeFromPreferredBlock).not.toHaveBeenCalled()
  })
})

describe('Delete and Backspace', () => {
  it.each(['Delete', 'Backspace'])('toggles the deleted state on %s', (name) => {
    const event = key(name)

    setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

    expect(options.toggleDelete).toHaveBeenCalledWith('a')
    expect(event.preventDefault).toHaveBeenCalled()
  })

  it.each([
    ['an input', '<input />'],
    ['a textarea', '<textarea></textarea>'],
    ['a contenteditable', '<div contenteditable="true"></div>'],
  ])('leaves the keystroke to %s', (_label, html) => {
    const event = key('Backspace', { target: element(html) })

    setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

    expect(options.toggleDelete).not.toHaveBeenCalled()
    expect(event.preventDefault).not.toHaveBeenCalled()
  })

  it('recognises an editing field the target only sits inside', () => {
    const field = element('<div contenteditable="true"><span>text</span></div>')

    setup(rootNode(['a']), node('a')).handleKeydown(
      key('Backspace', { target: field.firstElementChild as HTMLElement }),
      'a'
    )

    expect(options.toggleDelete).not.toHaveBeenCalled()
  })

  it('deletes from inside an editing field when Alt is held', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(
      key('Backspace', { altKey: true, target: element('<input />') }),
      'a'
    )

    expect(options.toggleDelete).toHaveBeenCalledWith('a')
  })
})

describe('Escape and F2', () => {
  it('clears transient state on Escape', () => {
    const event = key('Escape')

    setup(rootNode()).handleKeydown(event, CONTENT_WIZARD_ROOT_ID)

    expect(options.clearTransientState).toHaveBeenCalled()
    expect(event.preventDefault).toHaveBeenCalled()
  })

  // Escape is deliberately exempt from the editing-field guard: it is how an
  // in-place edit is abandoned, so it has to reach the canvas from inside one.
  it('clears transient state on Escape even inside an input', () => {
    setup(rootNode()).handleKeydown(
      key('Escape', { target: element('<input />') }),
      CONTENT_WIZARD_ROOT_ID
    )

    expect(options.clearTransientState).toHaveBeenCalled()
  })

  it('starts a title edit on F2', () => {
    const event = key('F2')

    setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

    expect(options.startEditing).toHaveBeenCalledWith('a', 'title')
    expect(event.preventDefault).toHaveBeenCalled()
  })
})

describe('arrow navigation', () => {
  // root > a, b; a > a1, a2
  const forest = () => [
    rootNode(['a', 'b']),
    node('a', { childrenIds: ['a1', 'a2'] }),
    node('a1', { parentId: 'a' }),
    node('a2', { parentId: 'a' }),
    node('b'),
  ]

  it('moves down to the next sibling', () => {
    setup(...forest()).handleKeydown(key('ArrowDown'), 'a1')

    expect(options.focusNode).toHaveBeenCalledWith('a2')
  })

  it('moves up to the previous sibling', () => {
    setup(...forest()).handleKeydown(key('ArrowUp'), 'a2')

    expect(options.focusNode).toHaveBeenCalledWith('a1')
  })

  it('navigates root-level siblings through the virtual root', () => {
    setup(...forest()).handleKeydown(key('ArrowDown'), 'a')

    expect(options.focusNode).toHaveBeenCalledWith('b')
  })

  it('stays put at the first and last sibling', () => {
    const keyboard = setup(...forest())

    keyboard.handleKeydown(key('ArrowUp'), 'a1')
    keyboard.handleKeydown(key('ArrowDown'), 'a2')

    expect(options.focusNode).not.toHaveBeenCalled()
  })

  it('moves left to the parent', () => {
    setup(...forest()).handleKeydown(key('ArrowLeft'), 'a1')

    expect(options.focusNode).toHaveBeenCalledWith('a')
  })

  it('moves left to the virtual root from a root-level node', () => {
    setup(rootNode(['a']), node('a', { parentId: null })).handleKeydown(key('ArrowLeft'), 'a')

    expect(options.focusNode).toHaveBeenCalledWith(CONTENT_WIZARD_ROOT_ID)
  })

  it('does not move left off the virtual root', () => {
    setup(...forest()).handleKeydown(key('ArrowLeft'), CONTENT_WIZARD_ROOT_ID)

    expect(options.focusNode).not.toHaveBeenCalled()
  })

  it('moves right into the first child', () => {
    setup(...forest()).handleKeydown(key('ArrowRight'), 'a')

    expect(options.focusNode).toHaveBeenCalledWith('a1')
  })

  it('does not move right into a collapsed node', () => {
    setup(
      rootNode(['a']),
      node('a', { childrenIds: ['a1'], isCollapsed: true }),
      node('a1', { parentId: 'a' })
    ).handleKeydown(key('ArrowRight'), 'a')

    expect(options.focusNode).not.toHaveBeenCalled()
  })

  it('does not move right from a leaf', () => {
    setup(...forest()).handleKeydown(key('ArrowRight'), 'b')

    expect(options.focusNode).not.toHaveBeenCalled()
  })

  it('does nothing for an unknown node', () => {
    setup(...forest()).handleKeydown(key('ArrowRight'), 'ghost')

    expect(options.focusNode).not.toHaveBeenCalled()
  })

  it('does nothing when the node is missing from its parent children', () => {
    setup(rootNode([]), node('orphan')).handleKeydown(key('ArrowDown'), 'orphan')

    expect(options.focusNode).not.toHaveBeenCalled()
  })

  it('leaves caret movement to an editing field', () => {
    const event = key('ArrowLeft', { target: element('<input />') })

    setup(...forest()).handleKeydown(event, 'a1')

    expect(options.focusNode).not.toHaveBeenCalled()
    expect(event.preventDefault).not.toHaveBeenCalled()
  })

  it('navigates out of an editing field when Alt is held', () => {
    setup(...forest()).handleKeydown(
      key('ArrowLeft', { altKey: true, target: element('<input />') }),
      'a1'
    )

    expect(options.focusNode).toHaveBeenCalledWith('a')
  })

  it('claims the event even when there is nowhere to go', () => {
    const event = key('ArrowRight')

    setup(...forest()).handleKeydown(event, 'b')

    expect(event.preventDefault).toHaveBeenCalled()
  })
})

describe('type-to-edit', () => {
  it('starts a title edit seeded with the typed character', () => {
    const event = key('h')

    setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

    expect(options.startEditing).toHaveBeenCalledWith('a', 'title', 'h')
    expect(event.preventDefault).toHaveBeenCalled()
  })

  it('accepts a shifted character', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(key('H', { shiftKey: true }), 'a')

    expect(options.startEditing).toHaveBeenCalledWith('a', 'title', 'H')
  })

  it('accepts a space, which is a single-character key', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(key(' '), 'a')

    expect(options.startEditing).toHaveBeenCalledWith('a', 'title', ' ')
  })

  it.each(['metaKey', 'ctrlKey', 'altKey'] as const)('ignores %s shortcuts', (modifier) => {
    const event = key('a', { [modifier]: true })

    setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

    expect(options.startEditing).not.toHaveBeenCalled()
    expect(event.preventDefault).not.toHaveBeenCalled()
  })

  it.each(['Shift', 'F5', 'PageDown', 'Home'])('ignores the non-printable key %s', (name) => {
    setup(rootNode(['a']), node('a')).handleKeydown(key(name), 'a')

    expect(options.startEditing).not.toHaveBeenCalled()
  })

  it('does not hijack typing inside an editing field', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(
      key('h', { target: element('<input />') }),
      'a'
    )

    expect(options.startEditing).not.toHaveBeenCalled()
  })

  it('does not hijack typing inside the block select, which does its own search', () => {
    setup(rootNode(['a']), node('a')).handleKeydown(
      key('h', { target: element('<div data-block-select><span>x</span></div>') }),
      'a'
    )

    expect(options.startEditing).not.toHaveBeenCalled()
  })

})

describe('guards', () => {
  const blockSelect = () => element('<div data-block-select></div>')

  // The picker owns every key while it is open: Enter chooses the highlighted
  // block and Escape closes the popover. Running the canvas shortcut underneath
  // created a sibling node instead, and wiped all transient state on Escape.
  it.each(['Enter', 'Tab', 'Escape', 'Delete', 'ArrowDown', 'F2'])(
    'lets the block select keep %s',
    (name) => {
      const event = key(name, { target: blockSelect() })

      setup(rootNode(['a']), node('a')).handleKeydown(event, 'a')

      expect(options.createNodeFromPreferredBlock).not.toHaveBeenCalled()
      expect(options.openAddMenu).not.toHaveBeenCalled()
      expect(options.clearTransientState).not.toHaveBeenCalled()
      expect(options.toggleDelete).not.toHaveBeenCalled()
      expect(options.focusNode).not.toHaveBeenCalled()
      expect(options.startEditing).not.toHaveBeenCalled()
      expect(event.preventDefault).not.toHaveBeenCalled()
    }
  )

  // Deliberately not guarded: the title input has no Enter or Tab handler of its
  // own, so this is the outliner flow — type a title, continue into the next
  // node without leaving the keyboard.
  it.each([
    ['Enter', 'sibling'],
    ['Tab', 'child'],
  ])('lets %s reach the canvas from inside a title field', (name, position) => {
    setup(rootNode(['a']), node('a')).handleKeydown(
      key(name, { target: element('<input />') }),
      'a'
    )

    expect(options.createNodeFromPreferredBlock).toHaveBeenCalledWith('a', position)
  })
})
