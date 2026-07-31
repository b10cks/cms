import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  AlertDialogProvider,
  setAlertDialogDefaultLabels,
  useAlertDialog,
} from '~/composables/useAlertDialog'

let wrapper: VueWrapper | undefined

const mountProvider = () => {
  wrapper = mount(AlertDialogProvider, { attachTo: document.body })

  return useAlertDialog()
}

const settle = async () => {
  await nextTick()
  await nextTick()
}

const buttons = () => Array.from(document.body.querySelectorAll('button'))

const labels = () => buttons().map((button) => button.textContent?.trim())

const click = async (label: string) => {
  const button = buttons().find((candidate) => candidate.textContent?.trim() === label)

  if (!button) {
    throw new Error(`No button labelled "${label}". Present: ${labels().join(', ')}`)
  }

  button.click()
  await settle()
}

const escape = async () => {
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  await settle()
}

/** Resolves to 'pending' when the promise has not settled by the next macrotask. */
const outcomeOf = <T>(promise: Promise<T>) =>
  Promise.race([
    promise,
    new Promise<'pending'>((resolve) => {
      setTimeout(() => resolve('pending'), 20)
    }),
  ])

beforeEach(() => {
  // createSharedComposable keeps one instance alive for the whole module: nothing
  // ever disposes it here, so the open dialog leaks into the next test unless the
  // state is reset by hand.
  useAlertDialog().state.value = { isOpen: false, component: null, resolve: null, reject: null }
  setAlertDialogDefaultLabels({ ok: 'OK', cancel: 'Cancel', confirm: 'Confirm' })
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  document.body.innerHTML = ''
  vi.useRealTimers()
})

describe('dialog', () => {
  it('renders the title, message and one button per action', async () => {
    const { alert } = mountProvider()

    alert.dialog({
      title: 'Delete space',
      message: 'This cannot be undone.',
      actions: [
        { type: 'cancel', label: 'Keep it' },
        { type: 'destructive', label: 'Delete' },
      ],
    })
    await settle()

    expect(document.body.textContent).toContain('Delete space')
    expect(document.body.textContent).toContain('This cannot be undone.')
    expect(labels()).toEqual(['Keep it', 'Delete'])
  })

  // ODDITY: dialog() is written to resolve with the clicked action's type, but
  // reka-ui's AlertDialogAction/AlertDialogCancel close the dialog themselves.
  // That fires 'onUpdate:open' with resolve('closed') before the action's own
  // handler runs, and the first resolve wins — so the action type is unreachable
  // for every action that does not opt out of closing.
  it('resolves with "closed" rather than the clicked action type', async () => {
    const { alert } = mountProvider()

    const promise = alert.dialog({
      message: 'Pick one',
      actions: [
        { type: 'cancel', label: 'No' },
        { type: 'destructive', label: 'Yes' },
      ],
    })
    await settle()
    await click('Yes')

    expect(await promise).toBe('closed')
  })

  it('resolves with "closed" for a cancel action too', async () => {
    const { alert } = mountProvider()

    const promise = alert.dialog({
      message: 'Pick one',
      actions: [{ type: 'cancel', label: 'No' }],
    })
    await settle()
    await click('No')

    expect(await promise).toBe('closed')
  })

  it('runs the action callback before resolving', async () => {
    const { alert } = mountProvider()
    const order: string[] = []

    const promise = alert.dialog({
      message: 'Pick one',
      actions: [{ type: 'primary', label: 'Go', click: () => order.push('click') }],
    })
    await settle()
    await click('Go')
    await promise
    order.push('resolved')

    expect(order).toEqual(['click', 'resolved'])
  })

  it('closes by default', async () => {
    const { alert, state } = mountProvider()

    alert.dialog({ message: 'Pick one', actions: [{ type: 'primary', label: 'Go' }] })
    await settle()
    await click('Go')

    expect(state.value.isOpen).toBe(false)
  })

  // ODDITY: `autoClose: false` only skips the composable's own closeDialog call.
  // reka-ui's AlertDialogAction closes the dialog regardless, so the option
  // cannot keep a dialog open — it just changes who closed it.
  it('closes anyway for an action that opts out of auto-closing', async () => {
    const { alert, state } = mountProvider()

    alert.dialog({
      message: 'Pick one',
      actions: [{ type: 'primary', label: 'Go', autoClose: false }],
    })
    await settle()
    await click('Go')

    expect(state.value.isOpen).toBe(false)
  })

  it('resolves with "closed" when dismissed without choosing an action', async () => {
    const { alert } = mountProvider()

    const promise = alert.dialog({
      message: 'Pick one',
      actions: [{ type: 'primary', label: 'Go' }],
    })
    await settle()
    await escape()

    expect(await promise).toBe('closed')
  })

  it('renders without a title when none is given', async () => {
    const { alert } = mountProvider()

    alert.dialog({ message: 'Body only', actions: [{ type: 'primary', label: 'Go' }] })
    await settle()

    expect(document.body.textContent).toContain('Body only')
  })

  // A dialog with no actions has no button to settle it: only a dismissal
  // (Escape, overlay) resolves the promise.
  it('renders a dialog without actions that only a dismissal can settle', async () => {
    const { alert } = mountProvider()

    const promise = alert.dialog({ message: 'Working…', actions: [] })
    await settle()

    expect(buttons()).toHaveLength(0)
    expect(await outcomeOf(promise)).toBe('pending')

    await escape()

    expect(await promise).toBe('closed')
  })

  it('clears the rendered component a moment after closing', async () => {
    vi.useFakeTimers()
    const { alert, state } = mountProvider()

    alert.dialog({ message: 'Pick one', actions: [{ type: 'primary', label: 'Go' }] })
    await settle()
    await click('Go')

    expect(state.value.component).not.toBeNull()

    vi.advanceTimersByTime(300)

    expect(state.value.component).toBeNull()
  })

  it('keeps the component mounted when a new dialog opens during the close delay', async () => {
    vi.useFakeTimers()
    const { alert, state } = mountProvider()

    alert.dialog({ message: 'First', actions: [{ type: 'primary', label: 'Go' }] })
    await settle()
    await click('Go')

    alert.dialog({ message: 'Second', actions: [{ type: 'primary', label: 'Go' }] })
    await settle()
    vi.advanceTimersByTime(300)
    await settle()

    expect(state.value.isOpen).toBe(true)
    expect(state.value.component).not.toBeNull()
    expect(document.body.textContent).toContain('Second')
  })
})

describe('message', () => {
  it('shows a single OK button and settles once it is clicked', async () => {
    const { alert } = mountProvider()

    const promise = alert.message('Saved.')
    await settle()

    expect(labels()).toEqual(['OK'])

    await click('OK')

    // 'closed', not 'primary' — see the dialog() oddity above.
    expect(await promise).toBe('closed')
  })

  it('adds a cancel button on request, before the OK button', async () => {
    const { alert } = mountProvider()

    alert.message('Saved.', { cancelButton: true })
    await settle()

    expect(labels()).toEqual(['Cancel', 'OK'])
  })

  it('honours custom labels and a title', async () => {
    const { alert } = mountProvider()

    alert.message('Saved.', {
      title: 'Done',
      cancelButton: true,
      cancelLabel: 'Later',
      okLabel: 'Got it',
    })
    await settle()

    expect(document.body.textContent).toContain('Done')
    expect(labels()).toEqual(['Later', 'Got it'])
  })

  // ODDITY: onClose is wired to *both* buttons and the resolved value is 'closed'
  // either way, so nothing lets a caller tell an acknowledgement from a
  // cancellation — message({ cancelButton: true }) is indistinguishable from OK.
  it('calls onClose for the cancel button as well as for OK', async () => {
    const { alert } = mountProvider()
    const onClose = vi.fn()

    const promise = alert.message('Saved.', { cancelButton: true, onClose })
    await settle()
    await click('Cancel')

    expect(onClose).toHaveBeenCalledTimes(1)
    expect(await promise).toBe('closed')
  })

  it('does not call onClose when the dialog is dismissed instead of acknowledged', async () => {
    const { alert } = mountProvider()
    const onClose = vi.fn()

    const promise = alert.message('Saved.', { onClose })
    await settle()
    await escape()

    expect(onClose).not.toHaveBeenCalled()
    expect(await promise).toBe('closed')
  })
})

describe('confirm', () => {
  it('resolves true and calls onConfirm on the confirm button', async () => {
    const { alert } = mountProvider()
    const onConfirm = vi.fn()

    const promise = alert.confirm('Sure?', { onConfirm })
    await settle()
    await click('Confirm')

    expect(await promise).toBe(true)
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('resolves false and calls onCancel on the cancel button', async () => {
    const { alert } = mountProvider()
    const onCancel = vi.fn()

    const promise = alert.confirm('Sure?', { onCancel })
    await settle()
    await click('Cancel')

    expect(await promise).toBe(false)
    expect(onCancel).toHaveBeenCalledTimes(1)
  })

  it('puts cancel first and confirm second', async () => {
    const { alert } = mountProvider()

    alert.confirm('Sure?')
    await settle()

    expect(labels()).toEqual(['Cancel', 'Confirm'])
  })

  it('honours custom labels, a title and a destructive variant', async () => {
    const { alert } = mountProvider()

    alert.confirm('Sure?', {
      title: 'Delete',
      cancelLabel: 'Keep',
      confirmLabel: 'Delete it',
      variant: 'destructive',
    })
    await settle()

    expect(document.body.textContent).toContain('Delete')
    expect(labels()).toEqual(['Keep', 'Delete it'])
  })

  it('calls only the chosen callback', async () => {
    const { alert } = mountProvider()
    const onConfirm = vi.fn()
    const onCancel = vi.fn()

    await settle()
    const promise = alert.confirm('Sure?', { onConfirm, onCancel })
    await settle()
    await click('Confirm')
    await promise

    expect(onCancel).not.toHaveBeenCalled()
  })

  // A dismissal — Escape, an overlay click — settles only the inner dialog
  // promise, which confirm() used to discard: every `if (await confirm(…))` the
  // user escaped out of hung forever, along with whatever the caller was doing.
  it('resolves false when the dialog is dismissed instead of answered', async () => {
    const { alert, state } = mountProvider()

    const promise = alert.confirm('Sure?')
    await settle()
    await escape()

    expect(state.value.isOpen).toBe(false)
    expect(await outcomeOf(promise)).toBe(false)
  })

  it('does not run either callback on a dismissal', async () => {
    const { alert } = mountProvider()
    const onConfirm = vi.fn()
    const onCancel = vi.fn()

    const promise = alert.confirm('Sure?', { onConfirm, onCancel })
    await settle()
    await escape()
    await promise

    expect(onConfirm).not.toHaveBeenCalled()
    expect(onCancel).not.toHaveBeenCalled()
  })

  // The click handlers run synchronously, before the dismissal fallback's
  // microtask, so a real answer still wins over the close that reka-ui fires.
  it('keeps the clicked answer even though closing resolves the inner dialog', async () => {
    const { alert } = mountProvider()

    const promise = alert.confirm('Sure?')
    await settle()
    await click('Confirm')

    expect(await promise).toBe(true)
  })
})

describe('overlapping dialogs', () => {
  /** The provider drops the closed dialog's markup 300ms after it closes. */
  const afterCloseAnimation = async () => {
    await new Promise((resolve) => {
      setTimeout(resolve, 350)
    })
    await settle()
  }

  // A second dialog waits its turn. Overwriting state.component tore the first
  // one's markup down while its resolvers stayed captured in a closure nobody
  // could reach any more, so the first promise never settled.
  it('queues a second dialog instead of abandoning the first', async () => {
    const { alert } = mountProvider()

    const first = alert.confirm('First?')
    await settle()

    const second = alert.confirm('Second?')
    await settle()

    expect(document.body.textContent).toContain('First?')
    expect(document.body.textContent).not.toContain('Second?')

    await click('Confirm')

    expect(await first).toBe(true)

    await afterCloseAnimation()
    expect(document.body.textContent).toContain('Second?')

    await click('Cancel')

    expect(await second).toBe(false)
  })

  it('shows only one dialog at a time', async () => {
    const { alert } = mountProvider()

    const first = alert.message('First')
    await settle()
    const second = alert.message('Second')
    await settle()

    expect(labels()).toEqual(['OK'])

    // Drain the queue, otherwise the pending dialog outlives this test — the
    // composable is shared for the whole module.
    await click('OK')
    await first
    await afterCloseAnimation()
    await click('OK')
    await second
    await afterCloseAnimation()
  })
})

describe('labels', () => {
  it('uses the translated defaults', async () => {
    const { alert } = mountProvider()

    alert.confirm('Sure?')
    await settle()

    expect(labels()).toEqual(['Cancel', 'Confirm'])
  })

  // ODDITY: getLabel prefers the i18n lookup, and `alertDialog.ok|cancel|confirm`
  // always resolve, so the `defaultLabels` fallback — and both setters that write
  // to it, setAlertDialogDefaultLabels and the returned setLabels — can never
  // change a rendered label. Dead code.
  it('ignores setAlertDialogDefaultLabels entirely', async () => {
    const { alert } = mountProvider()

    setAlertDialogDefaultLabels({ confirm: 'Yes please', cancel: 'No thanks' })
    alert.confirm('Sure?')
    await settle()

    expect(labels()).toEqual(['Cancel', 'Confirm'])
  })

  it('ignores the setLabels helper it returns as well', async () => {
    const { alert, setLabels } = mountProvider()

    setLabels({ ok: 'Understood' })
    alert.message('Saved.')
    await settle()

    expect(labels()).toEqual(['OK'])
  })
})

describe('state', () => {
  it('is closed and empty before anything is opened', () => {
    const { state } = mountProvider()

    expect(state.value).toEqual({ isOpen: false, component: null, resolve: null, reject: null })
  })

  it('is shared between every caller', async () => {
    const { alert } = mountProvider()
    const other = useAlertDialog()

    alert.message('Saved.')
    await settle()

    expect(other.state.value.isOpen).toBe(true)
  })

  // The resolve/reject slots on DialogState are never assigned — every promise
  // resolver lives in the dialog() closure instead. Dead fields.
  it('never populates the resolve and reject slots', async () => {
    const { alert, state } = mountProvider()

    alert.confirm('Sure?')
    await settle()

    expect(state.value.resolve).toBeNull()
    expect(state.value.reject).toBeNull()
  })
})
