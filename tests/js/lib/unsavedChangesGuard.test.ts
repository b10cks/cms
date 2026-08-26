import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import type {
  NavigationGuard,
  NavigationGuardNext,
  RouteLocationNormalized,
} from 'vue-router'

const leaveGuards: NavigationGuard[] = []
const updateGuards: NavigationGuard[] = []

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return {
    ...actual,
    onBeforeRouteLeave: (guard: NavigationGuard) => leaveGuards.push(guard),
    onBeforeRouteUpdate: (guard: NavigationGuard) => updateGuards.push(guard),
  }
})

type DialogOptions = import('~/composables/useAlertDialog').DialogOptions

/** Which button the fake user presses; `stay` also covers dismissing the dialog. */
let answer: 'stay' | 'discard' | 'save' = 'discard'

const actionTypeFor = { stay: 'cancel', discard: 'destructive', save: 'primary' } as const

const dialog = vi.fn(async (options: DialogOptions) => {
  const action = options.actions.find((candidate) => candidate.type === actionTypeFor[answer])
  action?.click?.()

  return action?.type ?? 'closed'
})

vi.mock('~/composables/useAlertDialog', async () => {
  const actual = await vi.importActual<typeof import('~/composables/useAlertDialog')>(
    '~/composables/useAlertDialog'
  )

  return { ...actual, useAlertDialog: () => ({ ...actual.useAlertDialog(), alert: { dialog } }) }
})

const { useUnsavedChangesGuard } = await import('~/lib/unsavedChangesGuard')

const route = (path: string, query: Record<string, string> = {}): RouteLocationNormalized =>
  ({ path, query }) as RouteLocationNormalized

const navigate = (from: RouteLocationNormalized, to: RouteLocationNormalized) => {
  const next = vi.fn()
  const result = updateGuards[0](to, from, next as unknown as NavigationGuardNext)

  return { next, result }
}

let wrappers: VueWrapper[] = []

const setup = (
  dirty = true,
  defaultLanguage?: string,
  onSave?: () => Promise<boolean> | boolean
) => {
  const isDirty = ref(dirty)
  const onDiscardChanges = vi.fn()

  const wrapper = mount(
    defineComponent({
      setup() {
        useUnsavedChangesGuard({
          isDirty,
          onDiscardChanges,
          onSave,
          defaultLanguage: ref(defaultLanguage),
        })
        return () => h('div')
      },
    })
  )
  wrappers.push(wrapper)

  return { isDirty, onDiscardChanges }
}

beforeEach(() => {
  leaveGuards.length = 0
  updateGuards.length = 0
  dialog.mockClear()
  answer = 'discard'
})

afterEach(() => {
  wrappers.forEach((wrapper) => wrapper.unmount())
  wrappers = []
})

describe('useUnsavedChangesGuard', () => {
  it('prompts when the path stays the same but lang changes while dirty', async () => {
    const { onDiscardChanges } = setup()

    const { next, result } = navigate(
      route('/spaces/s/content/c'),
      route('/spaces/s/content/c', { lang: 'de' })
    )
    await result

    expect(dialog).toHaveBeenCalledTimes(1)
    expect(onDiscardChanges).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith()
  })

  it('does not prompt for an unrelated same-path query while dirty', async () => {
    const { onDiscardChanges } = setup()

    const { next, result } = navigate(
      route('/spaces/s/content/c', { mode: 'edit' }),
      route('/spaces/s/content/c', { mode: 'comments' })
    )
    await result

    expect(dialog).not.toHaveBeenCalled()
    expect(onDiscardChanges).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith()
  })

  it('does not prompt when lang normalizes to the default language', async () => {
    const { onDiscardChanges } = setup(true, 'de')

    const { next, result } = navigate(
      route('/spaces/s/content/c', { lang: 'de' }),
      route('/spaces/s/content/c')
    )
    await result

    expect(dialog).not.toHaveBeenCalled()
    expect(onDiscardChanges).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith()
  })

  it('discards after the user confirms a language switch', async () => {
    const { onDiscardChanges } = setup()

    const { next, result } = navigate(
      route('/spaces/s/content/c', { lang: 'de' }),
      route('/spaces/s/content/c')
    )
    await result

    expect(onDiscardChanges).toHaveBeenCalledTimes(1)
    expect(next).toHaveBeenCalledWith()
  })

  it('stays on the current language when the user cancels', async () => {
    answer = 'stay'
    const { onDiscardChanges } = setup()

    const { next, result } = navigate(
      route('/spaces/s/content/c'),
      route('/spaces/s/content/c', { lang: 'de' })
    )
    await result

    expect(onDiscardChanges).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith(false)
  })

  it('offers saving only when the page can save, and leaves once it succeeded', async () => {
    answer = 'save'
    const onSave = vi.fn(async () => true)
    const { onDiscardChanges } = setup(true, undefined, onSave)

    const { next, result } = navigate(
      route('/spaces/s/content/c'),
      route('/spaces/s/content/c', { lang: 'de' })
    )
    await result

    expect(dialog.mock.calls[0][0].actions.map((action) => action.type)).toEqual([
      'cancel',
      'destructive',
      'primary',
    ])
    expect(onSave).toHaveBeenCalledTimes(1)
    expect(onDiscardChanges).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith(undefined)
  })

  it('keeps the user on the page when the save fails', async () => {
    answer = 'save'
    const onSave = vi.fn(async () => false)
    setup(true, undefined, onSave)

    const { next, result } = navigate(
      route('/spaces/s/content/c'),
      route('/spaces/s/content/c', { lang: 'de' })
    )
    await result

    expect(next).toHaveBeenCalledWith(false)
  })

  it('leaves saving out of the prompt when the page cannot save', async () => {
    setup()

    const { result } = navigate(
      route('/spaces/s/content/c'),
      route('/spaces/s/content/c', { lang: 'de' })
    )
    await result

    expect(dialog.mock.calls[0][0].actions.map((action) => action.type)).toEqual([
      'cancel',
      'destructive',
    ])
  })
})
