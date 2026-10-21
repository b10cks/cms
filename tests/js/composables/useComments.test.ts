import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const comments = {
  index: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  delete: vi.fn(),
  resolve: vi.fn(),
  unresolve: vi.fn(),
  addReaction: vi.fn(),
  removeReaction: vi.fn(),
}

const forContent = vi.fn(() => comments)

const success = vi.fn()
const failure = vi.fn()

vi.mock('~/api', () => ({ api: { forSpace: () => ({ comments: forContent }) } }))
vi.mock('vue-sonner', () => ({ toast: { success, error: failure } }))

const { useComments } = await import('~/composables/useComments')

const SPACE = 'space-1'
const CONTENT = 'content-1'

const comment = (id = 'cm1') => ({ id, message: 'hi' })

let harness: Harness<ReturnType<typeof mountComments>> | undefined

const mountComments = (contentId: Parameters<typeof useComments>[1] = CONTENT) => {
  const composable = useComments(SPACE, contentId)

  return {
    ...composable,
    create: composable.useCreateCommentMutation(),
    update: composable.useUpdateCommentMutation(),
    remove: composable.useDeleteCommentMutation(),
    resolve: composable.useResolveCommentMutation(),
    unresolve: composable.useUnresolveCommentMutation(),
    addReaction: composable.useAddReactionMutation(),
    removeReaction: composable.useRemoveReactionMutation(),
  }
}

const setup = () => {
  harness = withSetup(() => mountComments())
  return harness
}

const mutations = () => setup().result

const spyInvalidate = () => vi.spyOn((harness as Harness<unknown>).queryClient, 'invalidateQueries')

const invalidatedKeys = (spy: ReturnType<typeof spyInvalidate>) =>
  spy.mock.calls.map(([filters]) => (typeof filters === 'function' ? filters() : filters)?.queryKey)

/**
 * withSetup has no way to app-provide `broadcastCommentUpdate`, and a `provide`
 * inside the same component is invisible to its own `inject` — so the
 * collaborator-notification path needs its own mount.
 */
const withBroadcast = (broadcast: () => void) => {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  let result: ReturnType<typeof mountComments> | undefined

  const wrapper = mount(
    defineComponent({
      setup() {
        result = mountComments()
        return () => h('div')
      },
    }),
    {
      global: {
        plugins: [[VueQueryPlugin, { queryClient }]],
        provide: { queryClient, broadcastCommentUpdate: broadcast },
      },
    }
  )

  return { result: result as ReturnType<typeof mountComments>, unmount: () => wrapper.unmount() }
}

beforeEach(() => {
  for (const fn of Object.values(comments)) fn.mockReset()
  forContent.mockClear()
  success.mockReset()
  failure.mockReset()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('useCommentsQuery', () => {
  it('scopes the api client to the content id and caches the unwrapped list', async () => {
    comments.index.mockResolvedValue({ data: [comment()] })

    const { queryClient } = withSetup(() => useComments(SPACE, CONTENT).useCommentsQuery())

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.comments(SPACE, CONTENT).list({}))).toEqual([
        comment(),
      ])
    )
    expect(forContent).toHaveBeenCalledWith(CONTENT)
    expect(comments.index).toHaveBeenCalledWith({})
    queryClient.clear()
  })

  it('forwards the caller params and keys by them', async () => {
    comments.index.mockResolvedValue({ data: [] })
    const params = { filter: { is_resolved: false } }

    const { queryClient } = withSetup(() => useComments(SPACE, CONTENT).useCommentsQuery(params))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.comments(SPACE, CONTENT).list(params))).toEqual([])
    )
    expect(comments.index).toHaveBeenCalledWith(params)
    queryClient.clear()
  })

  it('stays disabled without a content id', () => {
    const { queryClient } = withSetup(() => useComments(SPACE, null).useCommentsQuery())

    expect(comments.index).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('runs as soon as the content id arrives', async () => {
    comments.index.mockResolvedValue({ data: [] })
    const contentId = ref<string | null>(null)

    const { queryClient } = withSetup(() => useComments(SPACE, contentId).useCommentsQuery())

    contentId.value = CONTENT

    await vi.waitFor(() => expect(comments.index).toHaveBeenCalledTimes(1))
    // The key follows the id, so nothing was ever cached under the empty one.
    expect(queryClient.getQueryData(queryKeys.comments(SPACE, '').list({}))).toBeUndefined()
    queryClient.clear()
  })
})

describe('useCommentQuery', () => {
  it('unwraps the envelope under the detail key', async () => {
    comments.get.mockResolvedValue({ data: comment() })

    const { queryClient } = withSetup(() => useComments(SPACE, CONTENT).useCommentQuery('cm1'))

    await vi.waitFor(() =>
      expect(queryClient.getQueryData(queryKeys.comments(SPACE, CONTENT).detail('cm1'))).toEqual(
        comment()
      )
    )
    queryClient.clear()
  })

  it('stays disabled without a comment id', () => {
    const { queryClient } = withSetup(() => useComments(SPACE, CONTENT).useCommentQuery(''))

    expect(comments.get).not.toHaveBeenCalled()
    queryClient.clear()
  })

  it('stays disabled without a content id', () => {
    const { queryClient } = withSetup(() => useComments(SPACE, null).useCommentQuery('cm1'))

    expect(comments.get).not.toHaveBeenCalled()
    queryClient.clear()
  })
})

describe('useCreateCommentMutation', () => {
  it('appends the server comment to matching cached lists without a refetch', async () => {
    const all = queryKeys.comments(SPACE, CONTENT).list({})
    const sameVersion = queryKeys.comments(SPACE, CONTENT).list({ filter: { content_version_id: 'v1' } })
    const otherVersion = queryKeys.comments(SPACE, CONTENT).list({ filter: { content_version_id: 'v2' } })
    comments.create.mockResolvedValue({ data: { ...comment('cm2'), content_version_id: 'v1' } })

    harness = withSetup(() => mountComments(), {
      seed: [
        [all, [comment()]],
        [sameVersion, [comment()]],
        [otherVersion, [comment()]],
      ],
    })
    const invalidate = spyInvalidate()

    await harness.result.create.mutateAsync({ body: 'hi' } as never)

    expect(harness.queryClient.getQueryData(all)).toEqual([
      comment(),
      { ...comment('cm2'), content_version_id: 'v1' },
    ])
    expect(harness.queryClient.getQueryData(sameVersion)).toHaveLength(2)
    expect(harness.queryClient.getQueryData(otherVersion)).toHaveLength(1)
    expect(invalidate).not.toHaveBeenCalled()
    expect(success).toHaveBeenCalledWith('Comment added successfully')
  })

  it('nests a reply under its parent', async () => {
    const list = queryKeys.comments(SPACE, CONTENT).list({})
    const reply = { ...comment('cm2'), parent_id: 'cm1' }
    comments.create.mockResolvedValue({ data: reply })

    harness = withSetup(() => mountComments(), { seed: [[list, [comment()]]] })

    await harness.result.create.mutateAsync({ body: 'hi' } as never)

    expect(harness.queryClient.getQueryData(list)).toEqual([{ ...comment(), replies: [reply] }])
  })

  it('reports failure', async () => {
    comments.create.mockRejectedValue(new Error('too long'))

    await expect(mutations().create.mutateAsync({} as never)).rejects.toThrow('too long')
    expect(failure).toHaveBeenCalledWith('Failed to add comment: too long')
  })

  it('falls back to "Unknown error" for an empty message', async () => {
    comments.create.mockRejectedValue(new Error(''))

    await expect(mutations().create.mutateAsync({} as never)).rejects.toThrow()
    expect(failure).toHaveBeenCalledWith('Failed to add comment: Unknown error')
  })
})

describe('useUpdateCommentMutation', () => {
  it('invalidates the lists and the edited comment', async () => {
    comments.update.mockResolvedValue({ data: comment() })
    const { update } = mutations()
    const invalidate = spyInvalidate()

    await update.mutateAsync({ id: 'cm1', payload: { message: 'edited' } as never })

    expect(comments.update).toHaveBeenCalledWith('cm1', { message: 'edited' })
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.comments(SPACE, CONTENT).lists(),
      queryKeys.comments(SPACE, CONTENT).detail('cm1'),
    ])
    expect(success).toHaveBeenCalledWith('Comment updated successfully')
  })

  it('reports failure', async () => {
    comments.update.mockRejectedValue(new Error('forbidden'))

    await expect(
      mutations().update.mutateAsync({ id: 'cm1', payload: {} as never })
    ).rejects.toThrow('forbidden')
    expect(failure).toHaveBeenCalledWith('Failed to update comment: forbidden')
  })
})

describe('useDeleteCommentMutation', () => {
  it('drops the detail cache and keeps the lists out of a refetch', async () => {
    comments.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync('cm1')

    expect(invalidate).not.toHaveBeenCalled()
    expect(removeQueries).toHaveBeenCalledWith({
      queryKey: queryKeys.comments(SPACE, CONTENT).detail('cm1'),
    })
    expect(success).toHaveBeenCalledWith('Comment deleted successfully')
  })

  it('removes the comment from cached lists before the server answers', async () => {
    const list = queryKeys.comments(SPACE, CONTENT).list({})
    let finish!: () => void
    comments.delete.mockReturnValue(new Promise<void>((resolve) => (finish = resolve)))

    harness = withSetup(() => mountComments(), { seed: [[list, [comment(), comment('cm2')]]] })

    const pending = harness.result.remove.mutateAsync('cm1')
    await vi.waitFor(() => expect(harness?.queryClient.getQueryData(list)).toEqual([comment('cm2')]))

    finish()
    await pending
  })

  it('restores the lists when the delete fails', async () => {
    const list = queryKeys.comments(SPACE, CONTENT).list({})
    comments.delete.mockRejectedValue(new Error('forbidden'))

    harness = withSetup(() => mountComments(), { seed: [[list, [comment(), comment('cm2')]]] })

    await expect(harness.result.remove.mutateAsync('cm1')).rejects.toThrow('forbidden')

    expect(harness.queryClient.getQueryData(list)).toEqual([comment(), comment('cm2')])
  })

  it('really evicts the seeded detail', async () => {
    comments.delete.mockResolvedValue(undefined)
    const detail = queryKeys.comments(SPACE, CONTENT).detail('cm1')

    harness = withSetup(() => mountComments(), { seed: [[detail, comment()]] })

    await harness.result.remove.mutateAsync('cm1')

    expect(harness.queryClient.getQueryData(detail)).toBeUndefined()
  })

  it('leaves a deleted comment reply in the cache', async () => {
    // Pinned: deleting a thread parent cascades server-side, but only the
    // requested id is evicted from the cache.
    comments.delete.mockResolvedValue(undefined)
    const reply = queryKeys.comments(SPACE, CONTENT).detail('cm2')

    harness = withSetup(() => mountComments(), { seed: [[reply, comment('cm2')]] })

    await harness.result.remove.mutateAsync('cm1')

    expect(harness.queryClient.getQueryData(reply)).toEqual(comment('cm2'))
  })

  it('reports failure', async () => {
    comments.delete.mockRejectedValue(new Error('forbidden'))

    await expect(mutations().remove.mutateAsync('cm1')).rejects.toThrow('forbidden')
    expect(failure).toHaveBeenCalledWith('Failed to delete comment: forbidden')
  })
})

describe('resolve and unresolve', () => {
  const cases = [
    ['resolve', 'resolve', 'Comment resolved', 'Failed to resolve comment: nope'],
    ['unresolve', 'unresolve', 'Comment unresolved', 'Failed to unresolve comment: nope'],
  ] as const

  it.each(cases)('%s invalidates only the comment detail', async (name, endpoint) => {
    comments[endpoint].mockResolvedValue({ data: comment() })
    const mutation = mutations()[name]
    const invalidate = spyInvalidate()

    await mutation.mutateAsync('cm1')

    expect(comments[endpoint]).toHaveBeenCalledWith('cm1')
    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.comments(SPACE, CONTENT).detail('cm1')])
  })

  it('resolve flips the cached comment at once, replies included, and unresolve flips it back', async () => {
    const list = queryKeys.comments(SPACE, CONTENT).list({})
    const seeded = [{ ...comment(), is_resolved: false, replies: [{ ...comment('cm2'), is_resolved: false }] }]
    let finish!: (value: { data: unknown }) => void
    comments.resolve.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    comments.unresolve.mockResolvedValue({ data: { ...comment('cm2'), is_resolved: false } })

    harness = withSetup(() => mountComments(), { seed: [[list, seeded]] })
    const cached = () =>
      harness?.queryClient.getQueryData<(typeof seeded)[number][]>(list)?.[0]?.replies?.[0]

    const pending = harness.result.resolve.mutateAsync('cm2')
    await vi.waitFor(() => expect(cached()?.is_resolved).toBe(true))

    finish({ data: { ...comment('cm2'), is_resolved: true } })
    await pending
    expect(cached()?.is_resolved).toBe(true)

    await harness.result.unresolve.mutateAsync('cm2')
    expect(cached()?.is_resolved).toBe(false)
  })

  it('resolve rolls back when the server rejects it', async () => {
    const list = queryKeys.comments(SPACE, CONTENT).list({})
    const seeded = [{ ...comment(), is_resolved: false }]
    comments.resolve.mockRejectedValue(new Error('nope'))

    harness = withSetup(() => mountComments(), { seed: [[list, seeded]] })

    await expect(harness.result.resolve.mutateAsync('cm1')).rejects.toThrow('nope')

    expect(harness.queryClient.getQueryData(list)).toEqual(seeded)
  })

  it.each(cases)('%s keys the detail off the response, not the argument', async (name, endpoint) => {
    comments[endpoint].mockResolvedValue({ data: comment('server-id') })
    const mutation = mutations()[name]
    const invalidate = spyInvalidate()

    await mutation.mutateAsync('cm1')

    expect(invalidatedKeys(invalidate)).toContainEqual(
      queryKeys.comments(SPACE, CONTENT).detail('server-id')
    )
  })

  it.each(cases)('%s reports success', async (name, endpoint, message) => {
    comments[endpoint].mockResolvedValue({ data: comment() })

    await mutations()[name].mutateAsync('cm1')

    expect(success).toHaveBeenCalledWith(message)
  })

  it.each(cases)('%s reports failure', async (name, endpoint, _message, error) => {
    comments[endpoint].mockRejectedValue(new Error('nope'))

    await expect(mutations()[name].mutateAsync('cm1')).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith(error)
  })
})

describe('reactions', () => {
  it('adds a reaction and invalidates the lists and the reacted comment', async () => {
    comments.addReaction.mockResolvedValue({ data: comment() })
    const { addReaction } = mutations()
    const invalidate = spyInvalidate()

    await addReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })

    expect(comments.addReaction).toHaveBeenCalledWith('cm1', '👍')
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.comments(SPACE, CONTENT).lists(),
      queryKeys.comments(SPACE, CONTENT).detail('cm1'),
    ])
    // Reactions are high-frequency, so they stay silent on success.
    expect(success).not.toHaveBeenCalled()
  })

  it('keys the reaction invalidation off the variables, so it works without a response', async () => {
    comments.removeReaction.mockResolvedValue(undefined)
    const { removeReaction } = mutations()
    const invalidate = spyInvalidate()

    await removeReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })

    expect(comments.removeReaction).toHaveBeenCalledWith('cm1', '👍')
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.comments(SPACE, CONTENT).lists(),
      queryKeys.comments(SPACE, CONTENT).detail('cm1'),
    ])
    expect(success).not.toHaveBeenCalled()
  })

  it('does not patch the cache optimistically, so a reaction only shows after a refetch', async () => {
    comments.addReaction.mockResolvedValue({ data: comment() })
    const list = queryKeys.comments(SPACE, CONTENT).list({})

    harness = withSetup(() => mountComments(), { seed: [[list, [comment()]]] })

    await harness.result.addReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })

    // Pinned: no onMutate, so the seeded list is untouched — the UI waits for
    // the invalidated query to come back.
    expect(harness.queryClient.getQueryData(list)).toEqual([comment()])
  })

  it('reports an add failure', async () => {
    comments.addReaction.mockRejectedValue(new Error('nope'))

    await expect(
      mutations().addReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })
    ).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to add reaction: nope')
  })

  it('reports a remove failure', async () => {
    comments.removeReaction.mockRejectedValue(new Error('nope'))

    await expect(
      mutations().removeReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })
    ).rejects.toThrow('nope')
    expect(failure).toHaveBeenCalledWith('Failed to remove reaction: nope')
  })
})

describe('collaborator notification', () => {
  it('notifies the other editors after every successful write', async () => {
    const broadcast = vi.fn()
    const { result, unmount } = withBroadcast(broadcast)

    comments.create.mockResolvedValue({ data: comment() })
    comments.update.mockResolvedValue({ data: comment() })
    comments.delete.mockResolvedValue(undefined)
    comments.resolve.mockResolvedValue({ data: comment() })
    comments.unresolve.mockResolvedValue({ data: comment() })
    comments.addReaction.mockResolvedValue({ data: comment() })
    comments.removeReaction.mockResolvedValue(undefined)

    await result.create.mutateAsync({ message: 'hi' } as never)
    await result.update.mutateAsync({ id: 'cm1', payload: {} as never })
    await result.remove.mutateAsync('cm1')
    await result.resolve.mutateAsync('cm1')
    await result.unresolve.mutateAsync('cm1')
    await result.addReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })
    await result.removeReaction.mutateAsync({ commentId: 'cm1', emoji: '👍' })

    expect(broadcast).toHaveBeenCalledTimes(7)
    unmount()
  })

  it('does not notify when a write fails', async () => {
    const broadcast = vi.fn()
    const { result, unmount } = withBroadcast(broadcast)

    comments.create.mockRejectedValue(new Error('nope'))

    await expect(result.create.mutateAsync({} as never)).rejects.toThrow('nope')
    expect(broadcast).not.toHaveBeenCalled()
    unmount()
  })

  it('works outside the content editor, where nothing provides the broadcast', async () => {
    comments.create.mockResolvedValue({ data: comment() })

    await mutations().create.mutateAsync({ message: 'hi' } as never)

    expect(success).toHaveBeenCalledWith('Comment added successfully')
  })
})
