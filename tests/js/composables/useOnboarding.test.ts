import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { MaybeRef } from 'vue'
import { nextTick, ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

// Neither jsdom nor node exposes a usable window.localStorage under vitest here
// (only sessionStorage is present), and useStorage silently degrades to a plain
// ref without one — which would make every persistence assertion vacuous.
class MemoryStorage implements Storage {
  private entries = new Map<string, string>()

  get length() {
    return this.entries.size
  }

  key(index: number) {
    return [...this.entries.keys()][index] ?? null
  }

  getItem(key: string) {
    return this.entries.get(key) ?? null
  }

  setItem(key: string, value: string) {
    this.entries.set(key, String(value))
  }

  removeItem(key: string) {
    this.entries.delete(key)
  }

  clear() {
    this.entries.clear()
  }
}

Object.defineProperty(window, 'localStorage', {
  value: new MemoryStorage(),
  configurable: true,
  writable: true,
})

const updateOnboarding = vi.fn()

vi.mock('~/api', () => ({
  api: { spaces: { updateOnboarding } },
}))

const error = vi.fn()

vi.mock('vue-sonner', () => ({ toast: { error } }))

const { useOnboarding } = await import('~/composables/useOnboarding')

type Onboarding = ReturnType<typeof useOnboarding>

const SPACE = 'space-1'
const KEY = `space-${SPACE}-onboarding`

const DEFAULTS = {
  framework: null,
  packageManager: 'bun',
  directory: '',
  commandCopied: false,
}

let harness: { unmount: () => void } | undefined

const setup = <T>(build: (api: Onboarding) => T, spaceId: MaybeRef<string> = SPACE): Harness<T> => {
  const instance = withSetup(() => build(useOnboarding(spaceId)))
  harness = instance

  return instance
}

const stored = (key = KEY) => JSON.parse(window.localStorage.getItem(key) ?? 'null')

beforeEach(() => {
  updateOnboarding.mockReset()
  error.mockReset()
  window.localStorage.clear()

  // `defaultChoices` is handed to useStorage by reference and ends up *being* the
  // ref's value whenever nothing is stored, so edits mutate that module-level
  // object for good (see the oddity below). Normalise it before every test.
  const reset = withSetup(() => useOnboarding(SPACE).choices)
  Object.assign(reset.result.value, DEFAULTS)
  reset.unmount()
  window.localStorage.clear()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('choices', () => {
  it('starts from the defaults, with bun as the package manager', () => {
    const { result } = setup((api) => api.choices)

    expect(result.value).toEqual(DEFAULTS)
  })

  it('persists a change under a space-scoped key', async () => {
    const { result } = setup((api) => api.choices)

    result.value.framework = 'nuxt'
    await nextTick()

    expect(stored()).toMatchObject({ framework: 'nuxt' })
  })

  it('reads a previously stored choice back', () => {
    window.localStorage.setItem(KEY, JSON.stringify({ ...DEFAULTS, framework: 'next', directory: 'web' }))
    const { result } = setup((api) => api.choices)

    expect(result.value.framework).toBe('next')
    expect(result.value.directory).toBe('web')
  })

  it('merges the defaults into a partial stored payload, so a new field is not undefined', () => {
    window.localStorage.setItem(KEY, JSON.stringify({ framework: 'nuxt' }))
    const { result } = setup((api) => api.choices)

    expect(result.value).toEqual({ ...DEFAULTS, framework: 'nuxt' })
  })

  it('keeps two spaces apart', () => {
    window.localStorage.setItem(`space-other-onboarding`, JSON.stringify({ ...DEFAULTS, directory: 'x' }))
    const { result } = setup((api) => api.choices)

    expect(result.value.directory).toBe('')
  })

  it('writes to a new storage key when the space id ref changes', async () => {
    const spaceId = ref(SPACE)
    const { result } = setup((api) => api.choices, spaceId)

    result.value.directory = 'first'
    await nextTick()

    spaceId.value = 'space-2'
    await nextTick()

    result.value.directory = 'second'
    await nextTick()

    expect(stored()).toMatchObject({ directory: 'first' })
    expect(stored('space-space-2-onboarding')).toMatchObject({ directory: 'second' })
  })

  // Within one composable instance the ref keeps its in-memory value when the
  // key changes, so switching space re-persists the answers under the new key.
  // That is useStorage's own behaviour, not shared module state — see the next
  // test, where a fresh instance starts clean.
  it('carries an edited choice over to a space that has none', async () => {
    const spaceId = ref(SPACE)
    const { result } = setup((api) => api.choices, spaceId)

    result.value.directory = 'first'
    result.value.framework = 'nuxt'
    await nextTick()

    spaceId.value = 'space-2'
    await nextTick()

    expect(result.value.directory).toBe('first')
    expect(stored('space-space-2-onboarding')).toMatchObject({
      directory: 'first',
      framework: 'nuxt',
    })
  })

  // The defaults come from a factory: a single shared object would be handed
  // back by useStorage and mutated in place, so the next space with nothing
  // stored would start from — and immediately persist — the previous answers.
  it('does not leak that mutation into a fresh composable instance', async () => {
    const first = setup((api) => api.choices)

    first.result.value.framework = 'nuxt'
    await nextTick()
    first.unmount()
    window.localStorage.clear()

    const second = setup((api) => api.choices, 'space-untouched')

    expect(second.result.value.framework).toBeNull()
    expect(second.result.value.packageManager).toBe('bun')
  })

  it('tracks the scaffold step through commandCopied — nothing else signals it', async () => {
    const { result } = setup((api) => api.choices)

    result.value.commandCopied = true
    await nextTick()

    expect(stored()).toMatchObject({ commandCopied: true })
  })
})

describe('useDismissOnboardingMutation', () => {
  it('patches the space onboarding flag', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: SPACE } })
    const { result } = setup((api) => api.useDismissOnboardingMutation())

    await result.mutateAsync(true)

    expect(updateOnboarding).toHaveBeenCalledWith(SPACE, true)
  })

  it('passes false through to undismiss', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: SPACE } })
    const { result } = setup((api) => api.useDismissOnboardingMutation())

    await result.mutateAsync(false)

    expect(updateOnboarding).toHaveBeenCalledWith(SPACE, false)
  })

  it('returns the updated space', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: SPACE, name: 'Space' } })
    const { result } = setup((api) => api.useDismissOnboardingMutation())

    expect(await result.mutateAsync(true)).toEqual({ id: SPACE, name: 'Space' })
  })

  it('resolves the space id from a ref at call time', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: 'space-2' } })
    const spaceId = ref(SPACE)
    const { result } = setup((api) => api.useDismissOnboardingMutation(), spaceId)

    spaceId.value = 'space-2'
    await result.mutateAsync(true)

    expect(updateOnboarding).toHaveBeenCalledWith('space-2', true)
  })

  it('invalidates the space lists and the space detail', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: SPACE } })
    const { result, queryClient } = setup((api) => api.useDismissOnboardingMutation())
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.mutateAsync(true)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaces.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaces.detail(SPACE) })
  })

  // The detail key comes from the composable's space id, not from the response:
  // a response naming another space used to invalidate that one and leave the
  // space we actually mutated stale.
  it('keys the detail invalidation off the composable space id', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: 'other' } })
    const { result, queryClient } = setup((api) => api.useDismissOnboardingMutation())
    const invalidate = vi.spyOn(queryClient, 'invalidateQueries')

    await result.mutateAsync(true)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spaces.detail(SPACE) })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.spaces.detail('other') })
  })

  it('toasts the failure and rejects', async () => {
    updateOnboarding.mockRejectedValue(new Error('Forbidden'))
    const { result } = setup((api) => api.useDismissOnboardingMutation())

    await expect(result.mutateAsync(true)).rejects.toThrow('Forbidden')
    expect(error).toHaveBeenCalledWith('Failed to update onboarding: Forbidden')
  })

  it('falls back to "Unknown error" for a message-less failure', async () => {
    updateOnboarding.mockRejectedValue(new Error(''))
    const { result } = setup((api) => api.useDismissOnboardingMutation())

    await expect(result.mutateAsync(true)).rejects.toThrow()
    expect(error).toHaveBeenCalledWith('Failed to update onboarding: Unknown error')
  })

  it('leaves the local choices untouched — dismissal is server side only', async () => {
    updateOnboarding.mockResolvedValue({ data: { id: SPACE } })
    const { result } = setup((api) => ({
      choices: api.choices,
      mutation: api.useDismissOnboardingMutation(),
    }))

    result.choices.value.framework = 'nuxt'
    await result.mutation.mutateAsync(true)

    expect(result.choices.value).toEqual({ ...DEFAULTS, framework: 'nuxt' })
  })
})
