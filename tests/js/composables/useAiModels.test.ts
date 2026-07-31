import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { SpaceAiConfig } from '~/api/resources/ai'

const get = vi.fn()
const patch = vi.fn()
const post = vi.fn()
const destroy = vi.fn()

const toastError = vi.fn()

// `useApiClient` imports the singleton from `@/api`; both aliases resolve to the
// same module, so one mock covers it.
vi.mock('~/api', () => ({
  api: { client: { get, patch, post, delete: destroy } },
}))

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn(), error: toastError },
}))

const { useAiModels, useAiSettings, useAiConfigs, useAiUsage } = await import(
  '~/composables/useAiModels'
)
const { queryKeys } = await import('~/composables/useQueryClient')
type AiModel = import('~/composables/useAiModels').AiModel
type SpaceAiSettings = import('~/composables/useAiModels').SpaceAiSettings
type SpaceAiUsage = import('~/composables/useAiModels').SpaceAiUsage

const { withSetup } = await import('../support/harness')
type Harness<T> = import('../support/harness').Harness<T>

const SPACE = 'space-1'

/** The composable's keys go through the shared factory, so the seeds must too. */
const keys = (spaceId: string) => queryKeys.ai(spaceId)

const model = (overrides: Partial<AiModel> = {}): AiModel => ({
  id: 'gpt-5',
  full_id: 'openai/gpt-5',
  name: 'GPT-5',
  driver: 'openai',
  description: null,
  context_window: { input: 200_000, output: 32_000 },
  input_cost: 1.25,
  output_cost: 10,
  capabilities: ['text', 'vision'],
  supports_streaming: true,
  supports_tools: true,
  supports_vision: true,
  is_favourite: false,
  ...overrides,
})

const settings = (overrides: Partial<SpaceAiSettings> = {}): SpaceAiSettings => ({
  enabled: true,
  model: 'openai/gpt-5',
  favourites: [],
  ...overrides,
})

const config = (overrides: Partial<SpaceAiConfig> = {}): SpaceAiConfig => ({
  id: 'config-1',
  name: 'Default',
  driver: 'openai',
  model: 'openai/gpt-5',
  system_prompt: null,
  temperature: 0.7,
  max_tokens: 2048,
  is_default: true,
  created_at: '2026-07-01T00:00:00Z',
  updated_at: '2026-07-01T00:00:00Z',
  ...overrides,
})

const usage = (overrides: Partial<SpaceAiUsage> = {}): SpaceAiUsage => ({
  provider: 'openrouter',
  unit: 'usd',
  available: true,
  unlimited: false,
  live: true,
  used: 4.5,
  limit: 20,
  remaining: 15.5,
  percentage: 22.5,
  reset: 'monthly',
  resets_at: '2026-08-01T00:00:00Z',
  breakdown: { daily: 0.5, weekly: 2, monthly: 4.5 },
  message: null,
  ...overrides,
})

let harness: Harness<unknown> | undefined

/** Query and mutation factories call vue-query hooks, so they must run in setup(). */
const mount = <T>(composable: () => T, seed: Array<[readonly unknown[], unknown]> = []) => {
  const created: Harness<T> = withSetup(composable, { seed })
  harness = created as Harness<unknown>
  return created
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useAiModels', () => {
  const grouped = { openai: [model()], anthropic: [model({ id: 'sonnet', driver: 'anthropic' })] }

  it('keys the model list by space', () => {
    const query = mount(() => useAiModels(SPACE).useModelsQuery(), [
      [keys(SPACE).models(), grouped],
    ]).result

    expect(query.data.value).toEqual(grouped)
    expect(get).not.toHaveBeenCalled()
  })

  it('fetches the models with the space in the query string and unwraps the envelope', async () => {
    get.mockResolvedValue({ data: grouped })

    const query = mount(() => useAiModels(SPACE).useModelsQuery()).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(get).toHaveBeenCalledWith(`/mgmt/v1/ai/models?spaceId=${SPACE}`)
    expect(query.data.value).toEqual(grouped)
  })

  it('stays disabled while there is no space', () => {
    const query = mount(() => useAiModels(null).useModelsQuery()).result

    expect(query.fetchStatus.value).toBe('idle')
    expect(query.data.value).toBeUndefined()
    expect(get).not.toHaveBeenCalled()
  })

  it('switches to a new cache entry when the space changes', async () => {
    const spaceId = ref<string | null>(SPACE)
    const other = { openai: [model({ id: 'gpt-5-mini' })] }

    const mounted = mount(() => useAiModels(spaceId).useModelsQuery(), [
      [keys(SPACE).models(), grouped],
      [keys('space-2').models(), other],
    ])

    expect(mounted.result.data.value).toEqual(grouped)

    spaceId.value = 'space-2'
    await vi.waitUntil(() => mounted.result.data.value?.openai?.[0]?.id === 'gpt-5-mini')

    expect(mounted.result.data.value).toEqual(other)
    expect(get).not.toHaveBeenCalled()
  })

  it('accepts a getter for the space id', () => {
    const query = mount(() => useAiModels(() => SPACE).useModelsQuery(), [
      [keys(SPACE).models(), grouped],
    ]).result

    expect(query.data.value).toEqual(grouped)
  })
})

describe('useAiSettingsQuery', () => {
  it('reads the settings out of the ai wrapper in the response', async () => {
    get.mockResolvedValue({ data: { ai: settings({ favourites: ['openai/gpt-5'] }) } })

    const query = mount(() => useAiSettings(SPACE).useAiSettingsQuery()).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(get).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`)
    expect(query.data.value).toEqual(settings({ favourites: ['openai/gpt-5'] }))
  })

  it('keys the settings by space', () => {
    const query = mount(() => useAiSettings(SPACE).useAiSettingsQuery(), [
      [keys(SPACE).settings(), settings()],
    ]).result

    expect(query.data.value).toEqual(settings())
    expect(get).not.toHaveBeenCalled()
  })

  it('stays disabled while there is no space', () => {
    const query = mount(() => useAiSettings(null).useAiSettingsQuery()).result

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('toggleFavourite', () => {
  it('adds a model to the cached favourites and sends the whole list', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE), [
      [keys(SPACE).settings(), settings({ favourites: ['anthropic/sonnet'] })],
    ])

    await mounted.result.toggleFavourite('openai/gpt-5')

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, {
      favourites: ['anthropic/sonnet', 'openai/gpt-5'],
    })
  })

  it('removes a model that is already a favourite', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE), [
      [keys(SPACE).settings(), settings({ favourites: ['anthropic/sonnet', 'openai/gpt-5'] })],
    ])

    await mounted.result.toggleFavourite('openai/gpt-5')

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, {
      favourites: ['anthropic/sonnet'],
    })
  })

  it('starts from an empty list when nothing is cached', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE))

    await mounted.result.toggleFavourite('openai/gpt-5')

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, {
      favourites: ['openai/gpt-5'],
    })
  })

  it('treats settings without a favourites array as empty', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE), [
      [keys(SPACE).settings(), { enabled: true, model: null }],
    ])

    await mounted.result.toggleFavourite('openai/gpt-5')

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, {
      favourites: ['openai/gpt-5'],
    })
  })

  it('refreshes the settings and the model list, because is_favourite lives on the model', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE))
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.toggleFavourite('openai/gpt-5')

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).settings() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).models() })
  })

  it('does nothing without a space', async () => {
    const mounted = mount(() => useAiSettings(null))

    expect(await mounted.result.toggleFavourite('openai/gpt-5')).toBeUndefined()
    expect(patch).not.toHaveBeenCalled()
  })

  it('propagates a failed patch, skips the invalidation and surfaces a toast', async () => {
    patch.mockRejectedValue(new Error('Forbidden'))

    const mounted = mount(() => useAiSettings(SPACE))
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(mounted.result.toggleFavourite('openai/gpt-5')).rejects.toThrow('Forbidden')
    expect(invalidate).not.toHaveBeenCalled()
    expect(toastError).toHaveBeenCalledWith('Failed to update favourite')
  })

  it('rolls the optimistic favourites back when the patch fails', async () => {
    patch.mockRejectedValue(new Error('Forbidden'))

    const mounted = mount(() => useAiSettings(SPACE), [
      [keys(SPACE).settings(), settings({ favourites: ['anthropic/sonnet'] })],
    ])

    await expect(mounted.result.toggleFavourite('openai/gpt-5')).rejects.toThrow('Forbidden')

    expect(mounted.queryClient.getQueryData(keys(SPACE).settings())).toEqual(
      settings({ favourites: ['anthropic/sonnet'] })
    )
  })

  it('reads the previous toggle back, so two rapid toggles do not clobber each other', async () => {
    // The list is written into the cache before the request, so a second toggle
    // fired before the refetch lands starts from the first one's result rather
    // than from the pre-toggle snapshot.
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE), [
      [keys(SPACE).settings(), settings({ favourites: [] })],
    ])

    const first = mounted.result.toggleFavourite('openai/gpt-5')
    const second = mounted.result.toggleFavourite('anthropic/sonnet')
    await Promise.all([first, second])

    expect(patch).toHaveBeenLastCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, {
      favourites: ['openai/gpt-5', 'anthropic/sonnet'],
    })
  })

  it('exposes a pending flag while the settings are being written', async () => {
    let release: () => void = () => {}
    patch.mockImplementation(
      () =>
        new Promise<Record<string, never>>((resolve) => {
          release = () => resolve({})
        })
    )

    const mounted = mount(() => useAiSettings(SPACE))

    const pending = mounted.result.toggleFavourite('openai/gpt-5')
    await vi.waitUntil(() => mounted.result.isSavingSettings.value)

    release()
    await pending

    await vi.waitUntil(() => !mounted.result.isSavingSettings.value)
  })
})

describe('setModel', () => {
  it('patches the selected model and refreshes the settings and the model list', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE))
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.setModel('anthropic/sonnet')

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, {
      model: 'anthropic/sonnet',
    })
    // Both writers share one mutation, so both refresh the same pair of keys.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).settings() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).models() })
  })

  it('clears the selection with an explicit null', async () => {
    patch.mockResolvedValue({})

    const mounted = mount(() => useAiSettings(SPACE))

    await mounted.result.setModel(null)

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-settings`, { model: null })
  })

  it('does nothing without a space', async () => {
    const mounted = mount(() => useAiSettings(null))

    expect(await mounted.result.setModel('anthropic/sonnet')).toBeUndefined()
    expect(patch).not.toHaveBeenCalled()
  })

  it('propagates a failed patch and surfaces a toast', async () => {
    patch.mockRejectedValue(new Error('Unknown model'))

    const mounted = mount(() => useAiSettings(SPACE))

    await expect(mounted.result.setModel('nope/nope')).rejects.toThrow('Unknown model')
    expect(toastError).toHaveBeenCalledWith('Failed to save model preference')
  })
})

describe('useAiConfigsQuery', () => {
  it('keys the list by space and unwraps the envelope', async () => {
    get.mockResolvedValue({ data: [config()] })

    const query = mount(() => useAiConfigs(SPACE).useAiConfigsQuery()).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(get).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-configs`)
    expect(query.data.value).toEqual([config()])
  })

  it('serves the list from the cache', () => {
    const query = mount(() => useAiConfigs(SPACE).useAiConfigsQuery(), [
      [keys(SPACE).configs(), [config()]],
    ]).result

    expect(query.data.value).toEqual([config()])
    expect(get).not.toHaveBeenCalled()
  })

  it('stays disabled while there is no space', () => {
    const query = mount(() => useAiConfigs(null).useAiConfigsQuery()).result

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })
})

describe('useAiConfigQuery', () => {
  it('keys a single config by space and config id', async () => {
    get.mockResolvedValue({ data: config() })

    const query = mount(() => useAiConfigs(SPACE).useAiConfigQuery('config-1')).result
    await vi.waitUntil(() => query.data.value !== undefined)

    expect(get).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-configs/config-1`)
    expect(query.data.value).toEqual(config())
  })

  it('serves a single config from the cache', () => {
    const query = mount(() => useAiConfigs(SPACE).useAiConfigQuery('config-1'), [
      [keys(SPACE).config('config-1'), config()],
    ]).result

    expect(query.data.value).toEqual(config())
    expect(get).not.toHaveBeenCalled()
  })

  it.each([
    ['the space', null, 'config-1'],
    ['the config', SPACE, null],
    ['both', null, null],
  ])('stays disabled while %s is missing', (_label, spaceId, configId) => {
    const query = mount(() => useAiConfigs(spaceId).useAiConfigQuery(configId)).result

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('refetches under a new key when the config id changes', async () => {
    const configId = ref<string | null>('config-1')

    const mounted = mount(() => useAiConfigs(SPACE).useAiConfigQuery(configId), [
      [keys(SPACE).config('config-1'), config()],
      [keys(SPACE).config('config-2'), config({ id: 'config-2', name: 'Drafting' })],
    ])

    expect(mounted.result.data.value?.name).toBe('Default')

    configId.value = 'config-2'
    await vi.waitUntil(() => mounted.result.data.value?.name === 'Drafting')

    expect(get).not.toHaveBeenCalled()
  })
})

describe('useCreateAiConfigMutation', () => {
  const payload = {
    name: 'Drafting',
    driver: 'openai',
    model: 'openai/gpt-5',
    system_prompt: 'Be brief.',
    temperature: 0.2,
    max_tokens: 512,
    is_default: false,
  }

  it('posts the payload to the space and returns the created config', async () => {
    post.mockResolvedValue({ data: config({ id: 'config-2', name: 'Drafting' }) })

    const mounted = mount(() => useAiConfigs(SPACE).useCreateAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    const result = await mounted.result.mutateAsync(payload)

    expect(post).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-configs`, payload)
    expect(result.id).toBe('config-2')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).configs() })
  })

  it('refuses without a space and never reaches the network', async () => {
    const mounted = mount(() => useAiConfigs(null).useCreateAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(mounted.result.mutateAsync(payload)).rejects.toThrow('No space ID')
    expect(post).not.toHaveBeenCalled()
    expect(invalidate).not.toHaveBeenCalled()
  })

  it('leaves the cache alone when the server rejects', async () => {
    post.mockRejectedValue(new Error('Validation failed'))

    const mounted = mount(() => useAiConfigs(SPACE).useCreateAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(mounted.result.mutateAsync(payload)).rejects.toThrow('Validation failed')
    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('useUpdateAiConfigMutation', () => {
  it('patches the config and refreshes both the list and that config', async () => {
    patch.mockResolvedValue({ data: config({ name: 'Renamed' }) })

    const mounted = mount(() => useAiConfigs(SPACE).useUpdateAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    const result = await mounted.result.mutateAsync({
      configId: 'config-1',
      payload: { name: 'Renamed' },
    })

    expect(patch).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-configs/config-1`, {
      name: 'Renamed',
    })
    expect(result.name).toBe('Renamed')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).configs() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).config('config-1') })
  })

  it('invalidates the id that was requested, even when the response disagrees', async () => {
    // The detail view is reading the requested id, so keying off `data.id`
    // would leave the entry it renders stale forever.
    patch.mockResolvedValue({ data: config({ id: 'config-9' }) })

    const mounted = mount(() => useAiConfigs(SPACE).useUpdateAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.mutateAsync({ configId: 'config-1', payload: { name: 'Renamed' } })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).config('config-1') })
    expect(invalidate).not.toHaveBeenCalledWith({
      queryKey: keys(SPACE).config('config-9'),
    })
  })

  it('refuses without a space', async () => {
    const mounted = mount(() => useAiConfigs(null).useUpdateAiConfigMutation())

    await expect(
      mounted.result.mutateAsync({ configId: 'config-1', payload: { name: 'x' } })
    ).rejects.toThrow('No space ID')
    expect(patch).not.toHaveBeenCalled()
  })

  it('leaves the cache alone when the server rejects', async () => {
    patch.mockRejectedValue(new Error('Validation failed'))

    const mounted = mount(() => useAiConfigs(SPACE).useUpdateAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(
      mounted.result.mutateAsync({ configId: 'config-1', payload: {} })
    ).rejects.toThrow('Validation failed')
    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('useDeleteAiConfigMutation', () => {
  it('deletes the config and refreshes the list', async () => {
    destroy.mockResolvedValue(undefined)

    const mounted = mount(() => useAiConfigs(SPACE).useDeleteAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await mounted.result.mutateAsync('config-1')

    expect(destroy).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-configs/config-1`)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: keys(SPACE).configs() })
  })

  it('evicts the deleted config entry, not just the list', async () => {
    // Removed rather than invalidated: the config is gone, so a detail route
    // revisited within `gcTime` must not render it from the cache.
    destroy.mockResolvedValue(undefined)

    const mounted = mount(() => useAiConfigs(SPACE).useDeleteAiConfigMutation(), [
      [keys(SPACE).config('config-1'), config()],
    ])
    const remove = vi.spyOn(mounted.queryClient, 'removeQueries')

    await mounted.result.mutateAsync('config-1')

    expect(remove).toHaveBeenCalledWith({ queryKey: keys(SPACE).config('config-1') })
    expect(mounted.queryClient.getQueryData(keys(SPACE).config('config-1'))).toBeUndefined()
  })

  it('leaves a different config in the cache alone', async () => {
    destroy.mockResolvedValue(undefined)

    const mounted = mount(() => useAiConfigs(SPACE).useDeleteAiConfigMutation(), [
      [keys(SPACE).config('config-1'), config()],
      [keys(SPACE).config('config-2'), config({ id: 'config-2' })],
    ])

    await mounted.result.mutateAsync('config-1')

    expect(mounted.queryClient.getQueryData(keys(SPACE).config('config-2'))).toEqual(
      config({ id: 'config-2' })
    )
  })

  it('refuses without a space', async () => {
    const mounted = mount(() => useAiConfigs(null).useDeleteAiConfigMutation())

    await expect(mounted.result.mutateAsync('config-1')).rejects.toThrow('No space ID')
    expect(destroy).not.toHaveBeenCalled()
  })

  it('leaves the cache alone when the delete fails', async () => {
    destroy.mockRejectedValue(new Error('In use'))

    const mounted = mount(() => useAiConfigs(SPACE).useDeleteAiConfigMutation())
    const invalidate = vi.spyOn(mounted.queryClient, 'invalidateQueries')

    await expect(mounted.result.mutateAsync('config-1')).rejects.toThrow('In use')
    expect(invalidate).not.toHaveBeenCalled()
  })
})

describe('useAiUsage', () => {
  it('keys usage by space and hits the plain endpoint', async () => {
    get.mockResolvedValue({ data: usage() })

    const mounted = mount(() => useAiUsage(SPACE).useAiUsageQuery())
    await vi.waitUntil(() => mounted.result.data.value !== undefined)

    expect(get).toHaveBeenCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-usage`)
    expect(mounted.result.data.value).toEqual(usage())
  })

  it('serves usage from the cache', () => {
    const query = mount(() => useAiUsage(SPACE).useAiUsageQuery(), [
      [keys(SPACE).usage(), usage()],
    ]).result

    expect(query.data.value).toEqual(usage())
    expect(get).not.toHaveBeenCalled()
  })

  it('stays disabled while there is no space', () => {
    const query = mount(() => useAiUsage(null).useAiUsageQuery()).result

    expect(query.fetchStatus.value).toBe('idle')
    expect(get).not.toHaveBeenCalled()
  })

  it('appends refresh=1 once and then falls back to the cached endpoint', async () => {
    get.mockResolvedValue({ data: usage() })

    const mounted = mount(() => {
      const instance = useAiUsage(SPACE)
      return { ...instance, query: instance.useAiUsageQuery() }
    })
    await vi.waitUntil(() => mounted.result.query.data.value !== undefined)

    mounted.result.forceRefresh.value = true
    await mounted.result.query.refetch()

    expect(get).toHaveBeenLastCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-usage?refresh=1`)
    // The flag is one-shot: it is cleared once the fetch has *settled*, so a
    // background refetch does not keep bypassing the provider-side cache.
    expect(mounted.result.forceRefresh.value).toBe(false)

    await mounted.result.query.refetch()
    expect(get).toHaveBeenLastCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-usage`)
  })

  it('does not consume the flag inside the fetcher, so a retried attempt keeps the bypass', async () => {
    // The flag used to be cleared in the `queryFn`. With retries enabled in the
    // real query client, a retried attempt would then silently fall back to the
    // cached endpoint. It stays set for the whole fetch and is cleared on settle.
    const pending: Array<() => void> = []
    get.mockImplementation(
      () =>
        new Promise<{ data: SpaceAiUsage }>((resolve) => {
          pending.push(() => resolve({ data: usage() }))
        })
    )

    const mounted = mount(() => {
      const instance = useAiUsage(SPACE)
      return { ...instance, query: instance.useAiUsageQuery() }
    })
    await vi.waitUntil(() => pending.length === 1)
    pending.shift()?.()
    await vi.waitUntil(() => mounted.result.query.data.value !== undefined)

    mounted.result.forceRefresh.value = true
    const inFlight = mounted.result.query.refetch()
    await vi.waitUntil(() => pending.length === 1)
    expect(mounted.result.forceRefresh.value).toBe(true)

    pending.shift()?.()
    await inFlight
    expect(get).toHaveBeenLastCalledWith(`/mgmt/v1/spaces/${SPACE}/ai-usage?refresh=1`)
    expect(mounted.result.forceRefresh.value).toBe(false)
  })

  it('exposes the flag as false by default', () => {
    const mounted = mount(() => useAiUsage(SPACE))

    expect(mounted.result.forceRefresh.value).toBe(false)
  })
})

describe('queryFn guards', () => {
  // Every queryFn re-checks the space id and returns an empty value. `enabled`
  // already blocks those calls, so the guards are only reachable through an
  // explicit `refetch()`, which ignores `enabled`. They are pinned here because
  // they are the composables' only stated behaviour for a missing space.
  it('resolves the model list to an empty group map', async () => {
    const spaceId = ref<string | null>(SPACE)
    const mounted = mount(() => useAiModels(spaceId).useModelsQuery(), [
      [['ai-models', SPACE], {}],
    ])

    spaceId.value = null
    get.mockClear()
    expect((await mounted.result.refetch()).data).toEqual({})
    expect(get).not.toHaveBeenCalled()
  })

  it('resolves the settings to null', async () => {
    const spaceId = ref<string | null>(SPACE)
    const mounted = mount(() => useAiSettings(spaceId).useAiSettingsQuery(), [
      [keys(SPACE).settings(), settings()],
    ])

    spaceId.value = null
    get.mockClear()
    expect((await mounted.result.refetch()).data).toBeNull()
    expect(get).not.toHaveBeenCalled()
  })

  it('resolves the config list to an empty array', async () => {
    const spaceId = ref<string | null>(SPACE)
    const mounted = mount(() => useAiConfigs(spaceId).useAiConfigsQuery(), [
      [keys(SPACE).configs(), [config()]],
    ])

    spaceId.value = null
    get.mockClear()
    expect((await mounted.result.refetch()).data).toEqual([])
    expect(get).not.toHaveBeenCalled()
  })

  it.each([
    ['the space is missing', null, 'config-1'],
    ['the config is missing', SPACE, null],
  ])('resolves a single config to null when %s', async (_label, space, configId) => {
    const spaceId = ref<string | null>(SPACE)
    const cId = ref<string | null>('config-1')
    const mounted = mount(() => useAiConfigs(spaceId).useAiConfigQuery(cId), [
      [keys(SPACE).config('config-1'), config()],
    ])

    spaceId.value = space
    cId.value = configId
    get.mockClear()
    expect((await mounted.result.refetch()).data).toBeNull()
    expect(get).not.toHaveBeenCalled()
  })

  it('resolves usage to null', async () => {
    const spaceId = ref<string | null>(SPACE)
    const mounted = mount(() => useAiUsage(spaceId).useAiUsageQuery(), [
      [keys(SPACE).usage(), usage()],
    ])

    spaceId.value = null
    get.mockClear()
    expect((await mounted.result.refetch()).data).toBeNull()
    expect(get).not.toHaveBeenCalled()
  })
})
