import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const update = vi.fn()
const current = vi.fn()
const publish = vi.fn()
const contentVersions = vi.fn((_spaceId: string, _contentId: string) => ({
  index,
  get,
  update,
  current,
  publish,
}))

vi.mock('~/api', () => ({
  api: {
    forSpace: (spaceId: string) => ({
      contentVersions: (id: string) => contentVersions(spaceId, id),
    }),
  },
}))

const toastSuccess = vi.fn()
const toastError = vi.fn()

vi.mock('vue-sonner', () => ({ toast: { success: toastSuccess, error: toastError } }))

const { useContentVersions } = await import('~/composables/useContentVersions')

const SPACE = 'space-1'
const CONTENT = 'content-1'

const version = (id: string) => ({ id, message: null })

// Every hook the composable hands back calls useQuery/useMutation itself, so the
// call has to happen inside the harness component's setup, not on its result.
let harness: Harness<unknown> | undefined

const setup = <T>(build: () => T, seed: Array<[readonly unknown[], unknown]> = []): T => {
  const mounted = withSetup(build, { seed })
  harness = mounted as Harness<unknown>
  return mounted.result
}

const queryClient = () => harness!.queryClient

const flush = async () => {
  await nextTick()
  await Promise.resolve()
  await nextTick()
}

type InvalidateSpy = { mock: { calls: Array<[{ queryKey?: unknown } | undefined]> } }

const invalidatedKeys = (invalidate: unknown) =>
  (invalidate as InvalidateSpy).mock.calls.map(([options]) => options?.queryKey)

beforeEach(() => {
  index.mockReset()
  get.mockReset()
  update.mockReset()
  current.mockReset()
  publish.mockReset()
  contentVersions.mockClear()
  toastSuccess.mockReset()
  toastError.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useContentVersionsQuery', () => {
  it('serves the list seeded under the versions list key', () => {
    const versions = [version('v1')]
    const { data } = setup(
      () => useContentVersions(SPACE, CONTENT).useContentVersionsQuery(),
      [[queryKeys.contentVersions(SPACE, CONTENT).list({}), versions]]
    )

    expect(data.value).toEqual(versions)
    expect(index).not.toHaveBeenCalled()
  })

  it('always sorts newest first and keeps the caller params', async () => {
    index.mockResolvedValue({ data: [version('v1')] })

    const { data } = setup(() =>
      useContentVersions(SPACE, CONTENT).useContentVersionsQuery({ page: 2 })
    )
    await flush()

    expect(index).toHaveBeenCalledWith({ page: 2, sort: '-created_at' })
    expect(data.value).toEqual([version('v1')])
  })

  it('lets a caller-supplied sort win over the newest-first default', async () => {
    // The sort is part of the query key, so the request has to honour it or two
    // cache entries would issue the identical call.
    index.mockResolvedValue({ data: [] })

    setup(() => useContentVersions(SPACE, CONTENT).useContentVersionsQuery({ sort: '+created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+created_at' })
  })

  it('keys the list by the params so a different page is a different entry', () => {
    const { second, first } = setup(
      () => {
        const { useContentVersionsQuery } = useContentVersions(SPACE, CONTENT)
        return {
          second: useContentVersionsQuery({ page: 2 }),
          first: useContentVersionsQuery({ page: 1 }),
        }
      },
      [[queryKeys.contentVersions(SPACE, CONTENT).list({ page: 2 }), [version('v9')]]]
    )

    expect(second.data.value).toEqual([version('v9')])
    expect(first.data.value).toBeUndefined()
  })

  it('does not query without a content id', async () => {
    setup(() => useContentVersions(SPACE, null).useContentVersionsQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()
  })

  it('starts querying once the content id arrives', async () => {
    const contentId = ref<string | null>(null)
    index.mockResolvedValue({ data: [version('v1')] })

    const { data } = setup(() =>
      useContentVersions(SPACE, contentId).useContentVersionsQuery()
    )
    await flush()

    expect(index).not.toHaveBeenCalled()

    contentId.value = CONTENT
    await flush()

    expect(index).toHaveBeenCalledTimes(1)
    expect(data.value).toEqual([version('v1')])
  })

  it('addresses the versions endpoint of the resolved space and content', async () => {
    index.mockResolvedValue({ data: [] })

    setup(() => useContentVersions(SPACE, CONTENT).useContentVersionsQuery())
    await flush()

    expect(contentVersions).toHaveBeenCalledWith(SPACE, CONTENT)
  })
})

describe('useContentVersionQuery', () => {
  it('serves the detail seeded under the version detail key', () => {
    const { data } = setup(
      () => useContentVersions(SPACE, CONTENT).useContentVersionQuery('v1'),
      [[queryKeys.contentVersions(SPACE, CONTENT).detail('v1'), version('v1')]]
    )

    expect(data.value).toEqual(version('v1'))
  })

  it('fetches the single version by id', async () => {
    get.mockResolvedValue({ data: version('v1') })

    const { data } = setup(() => useContentVersions(SPACE, CONTENT).useContentVersionQuery('v1'))
    await flush()

    expect(get).toHaveBeenCalledWith('v1')
    expect(data.value).toEqual(version('v1'))
  })

  it.each([null, undefined, ''])('does not query for a %o version id', async (versionId) => {
    setup(() => useContentVersions(SPACE, CONTENT).useContentVersionQuery(versionId))
    await flush()

    expect(get).not.toHaveBeenCalled()
  })

  it('does not query without a content id', async () => {
    setup(() => useContentVersions(SPACE, null).useContentVersionQuery('v1'))
    await flush()

    expect(get).not.toHaveBeenCalled()
  })

  it('follows the version id as it changes', async () => {
    const versionId = ref('v1')
    get.mockImplementation(async (id: string) => ({ data: version(id) }))

    const { data } = setup(() =>
      useContentVersions(SPACE, CONTENT).useContentVersionQuery(versionId)
    )
    await flush()

    versionId.value = 'v2'
    await flush()

    expect(get).toHaveBeenLastCalledWith('v2')
    expect(data.value).toEqual(version('v2'))
  })
})

describe('useSetCurrentVersionMutation', () => {
  it('marks the version current and invalidates the version and content caches', async () => {
    current.mockResolvedValue(true)
    const mutation = setup(() =>
      useContentVersions(SPACE, CONTENT).useSetCurrentVersionMutation()
    )
    const invalidate = vi.spyOn(queryClient(), 'invalidateQueries')

    await expect(mutation.mutateAsync('v1')).resolves.toEqual({ id: 'v1' })

    expect(current).toHaveBeenCalledWith('v1')
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.contentVersions(SPACE, CONTENT).lists(),
      queryKeys.contents(SPACE).lists(),
      queryKeys.contents(SPACE).detail(CONTENT),
      queryKeys.contentVersions(SPACE, CONTENT).detail('v1'),
    ])
    expect(toastSuccess).toHaveBeenCalledWith('Version set as current successfully')
  })

  it('reports the failure reason', async () => {
    current.mockRejectedValue(new Error('nope'))
    const mutation = setup(() =>
      useContentVersions(SPACE, CONTENT).useSetCurrentVersionMutation()
    )

    await expect(mutation.mutateAsync('v1')).rejects.toThrow('nope')

    expect(toastError).toHaveBeenCalledWith('Failed to set version as current: nope')
    expect(toastSuccess).not.toHaveBeenCalled()
  })

  it('falls back to a generic reason for an error without a message', async () => {
    current.mockRejectedValue(new Error(''))
    const mutation = setup(() =>
      useContentVersions(SPACE, CONTENT).useSetCurrentVersionMutation()
    )

    await expect(mutation.mutateAsync('v1')).rejects.toThrow()

    expect(toastError).toHaveBeenCalledWith('Failed to set version as current: Unknown error')
  })
})

describe('useUpdateVersionMutation', () => {
  it('updates the message and invalidates down to the updated version', async () => {
    update.mockResolvedValue({ data: version('v1') })
    const mutation = setup(() => useContentVersions(SPACE, CONTENT).useUpdateVersionMutation())
    const invalidate = vi.spyOn(queryClient(), 'invalidateQueries')

    await expect(
      mutation.mutateAsync({ id: 'v1', payload: { message: 'Reviewed' } })
    ).resolves.toEqual(version('v1'))

    expect(update).toHaveBeenCalledWith('v1', { message: 'Reviewed' })
    expect(invalidate).toHaveBeenCalledTimes(4)
    expect(toastSuccess).toHaveBeenCalledWith('Version updated successfully')
  })

  it('invalidates the id the server returned, not the id it was asked for', async () => {
    // The success handler reads `data.id` off the response, so a server-side
    // remap follows through to the cache.
    update.mockResolvedValue({ data: version('v-server') })
    const mutation = setup(() => useContentVersions(SPACE, CONTENT).useUpdateVersionMutation())
    const invalidate = vi.spyOn(queryClient(), 'invalidateQueries')

    await mutation.mutateAsync({ id: 'v1', payload: {} })

    expect(invalidatedKeys(invalidate).at(-1)).toEqual(
      queryKeys.contentVersions(SPACE, CONTENT).detail('v-server')
    )
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('boom'))
    const mutation = setup(() => useContentVersions(SPACE, CONTENT).useUpdateVersionMutation())

    await expect(mutation.mutateAsync({ id: 'v1', payload: {} })).rejects.toThrow('boom')

    expect(toastError).toHaveBeenCalledWith('Failed to update version: boom')
  })
})

describe('usePublishVersionMutation', () => {
  it('publishes the version and invalidates its caches', async () => {
    publish.mockResolvedValue(true)
    const mutation = setup(() => useContentVersions(SPACE, CONTENT).usePublishVersionMutation())
    const invalidate = vi.spyOn(queryClient(), 'invalidateQueries')

    await expect(mutation.mutateAsync('v1')).resolves.toEqual({ id: 'v1' })

    expect(publish).toHaveBeenCalledWith('v1')
    expect(invalidatedKeys(invalidate).at(-1)).toEqual(
      queryKeys.contentVersions(SPACE, CONTENT).detail('v1')
    )
    expect(toastSuccess).toHaveBeenCalledWith('Version published successfully')
  })

  it('reports the failure reason', async () => {
    publish.mockRejectedValue(new Error('rejected'))
    const mutation = setup(() => useContentVersions(SPACE, CONTENT).usePublishVersionMutation())

    await expect(mutation.mutateAsync('v1')).rejects.toThrow('rejected')

    expect(toastError).toHaveBeenCalledWith('Failed to publish version: rejected')
  })
})

describe('mutations without a content id', () => {
  it('refuse to run, exactly like the queries', async () => {
    publish.mockResolvedValue(true)
    const mutation = setup(() => useContentVersions(SPACE, null).usePublishVersionMutation())
    const invalidate = vi.spyOn(queryClient(), 'invalidateQueries')

    // Otherwise the request would address `/contents//versions/v1/publish` and
    // invalidate `contents.detail('')`.
    await expect(mutation.mutateAsync('v1')).rejects.toThrow('Content ID is required')

    expect(publish).not.toHaveBeenCalled()
    expect(invalidate).not.toHaveBeenCalled()
  })

  it.each([
    ['useSetCurrentVersionMutation', 'v1'],
    ['usePublishVersionMutation', 'v1'],
  ] as const)('%s rejects', async (hook, payload) => {
    const mutation = setup(() => useContentVersions(SPACE, undefined)[hook]())

    await expect(mutation.mutateAsync(payload)).rejects.toThrow('Content ID is required')
  })

  it('useUpdateVersionMutation rejects', async () => {
    const mutation = setup(() => useContentVersions(SPACE, '').useUpdateVersionMutation())

    await expect(mutation.mutateAsync({ id: 'v1', payload: {} })).rejects.toThrow(
      'Content ID is required'
    )
    expect(update).not.toHaveBeenCalled()
  })
})
