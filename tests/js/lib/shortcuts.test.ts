import { describe, expect, it, vi } from 'vitest'

import { formatKeys, listShortcuts, parseKeys, registerShortcut } from '~/lib/shortcuts'

/**
 * jsdom reports a non-mac user agent, so `mod` normalises to Ctrl throughout.
 */

const press = (init: KeyboardEventInit & { key: string }) => {
  window.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }))
}

describe('parseKeys', () => {
  it('normalises modifiers and aliases', () => {
    expect(parseKeys('shift+mod+z')).toEqual({ key: 'z', mod: true, alt: false, shift: true })
    expect(parseKeys('alt+up')).toEqual({ key: 'arrowup', mod: false, alt: true, shift: false })
    expect(parseKeys('esc')).toEqual({ key: 'escape', mod: false, alt: false, shift: false })
  })

  it('leaves shift undecided for symbol keys, so layouts that need it still match', () => {
    expect(parseKeys('?').shift).toBeNull()
    expect(parseKeys('mod+shift+.').shift).toBe(true)
  })
})

describe('formatKeys', () => {
  it('renders platform tokens', () => {
    expect(formatKeys('mod+s')).toEqual(['Ctrl', 'S'])
    expect(formatKeys('alt+arrowdown')).toEqual(['Alt', '↓'])
    expect(formatKeys('?')).toEqual(['?'])
  })
})

describe('dispatch', () => {
  it('matches `?` on the produced character, not the code', () => {
    const handler = vi.fn()
    const dispose = registerShortcut({ keys: '?', description: () => '', handler })

    press({ key: '?', code: 'Slash', shiftKey: true })

    expect(handler).toHaveBeenCalledOnce()
    dispose()
  })

  it('resolves shifted punctuation through the physical key', () => {
    const handler = vi.fn()
    const dispose = registerShortcut({
      keys: 'mod+shift+.',
      description: () => '',
      handler,
    })

    press({ key: '>', code: 'Period', ctrlKey: true, shiftKey: true })

    expect(handler).toHaveBeenCalledOnce()
    dispose()
  })

  it('lets the most recently registered scope shadow global', () => {
    const globalHandler = vi.fn()
    const scopedHandler = vi.fn()
    const disposeGlobal = registerShortcut({
      keys: 'mod+s',
      description: () => '',
      handler: globalHandler,
    })
    const disposeScoped = registerShortcut({
      keys: 'mod+s',
      scope: 'editor',
      description: () => '',
      handler: scopedHandler,
    })

    press({ key: 's', code: 'KeyS', ctrlKey: true })
    expect(scopedHandler).toHaveBeenCalledOnce()
    expect(globalHandler).not.toHaveBeenCalled()

    disposeScoped()
    press({ key: 's', code: 'KeyS', ctrlKey: true })
    expect(globalHandler).toHaveBeenCalledOnce()

    disposeGlobal()
  })

  it('stays out of text entry unless the binding opts in', () => {
    const guarded = vi.fn()
    const opted = vi.fn()
    const disposeGuarded = registerShortcut({ keys: '/', description: () => '', handler: guarded })
    const disposeOpted = registerShortcut({
      keys: 'mod+s',
      description: () => '',
      allowInInput: true,
      handler: opted,
    })

    const input = document.createElement('input')
    document.body.append(input)
    input.focus()
    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: '/', code: 'Slash', bubbles: true, cancelable: true })
    )
    input.dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 's',
        code: 'KeyS',
        ctrlKey: true,
        bubbles: true,
        cancelable: true,
      })
    )

    expect(guarded).not.toHaveBeenCalled()
    expect(opted).toHaveBeenCalledOnce()

    input.remove()
    disposeGuarded()
    disposeOpted()
  })

  it('never fires a documentation-only entry, but still lists it', () => {
    const dispose = registerShortcut({
      keys: 'enter',
      scope: 'canvas',
      description: () => 'Create a sibling node',
      handler: null,
    })

    const event = new KeyboardEvent('keydown', {
      key: 'Enter',
      code: 'Enter',
      bubbles: true,
      cancelable: true,
    })
    window.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
    expect(listShortcuts()).toContainEqual({
      keys: 'enter',
      description: 'Create a sibling node',
      scope: 'canvas',
      active: true,
      tokens: ['Enter'],
    })

    dispose()
  })
})
