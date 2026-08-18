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

const confirm = vi.fn(async () => true)

vi.mock('~/composables/useAlertDialog', async () => {
  const actual = await vi.importActual<typeof import('~/composables/useAlertDialog')>(
    '~/composables/useAlertDialog'
  )

  return { ...actual, useAlertDialog: () => ({ ...actual.useAlertDialog(), alert: { confirm } }) }
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

const setup = (dirty = true) => {
  const isDirty = ref(dirty)
  const onDiscardChanges = vi.fn()

  const wrapper = mount(
    defineComponent({
      setup() {
        useUnsavedChangesGuard({ isDirty, onDiscardChanges })
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
  confirm.mockReset()
  confirm.mockResolvedValue(true)
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

    expect(confirm).toHaveBeenCalledTimes(1)
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

    expect(confirm).not.toHaveBeenCalled()
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
    confirm.mockResolvedValue(false)
    const { onDiscardChanges } = setup()

    const { next, result } = navigate(
      route('/spaces/s/content/c'),
      route('/spaces/s/content/c', { lang: 'de' })
    )
    await result

    expect(onDiscardChanges).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith(false)
  })
})
