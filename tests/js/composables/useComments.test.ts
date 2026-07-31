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
  it('invalidates the comment lists only', async () => {
    comments.create.mockResolvedValue({ data: comment() })
    const { create } = mutations()
    const invalidate = spyInvalidate()

    await create.mutateAsync({ message: 'hi' } as never)

    expect(comments.create).toHaveBeenCalledWith({ message: 'hi' })
    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.comments(SPACE, CONTENT).lists()])
    expect(success).toHaveBeenCalledWith('Comment added successfully')
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
  it('drops the detail cache and invalidates the lists', async () => {
    comments.delete.mockResolvedValue(undefined)
    const { remove } = mutations()
    const invalidate = spyInvalidate()
    const removeQueries = vi.spyOn((harness as Harness<unknown>).queryClient, 'removeQueries')

    await remove.mutateAsync('cm1')

    expect(invalidatedKeys(invalidate)).toEqual([queryKeys.comments(SPACE, CONTENT).lists()])
    expect(removeQueries).toHaveBeenCalledWith({
      queryKey: queryKeys.comments(SPACE, CONTENT).detail('cm1'),
    })
    expect(success).toHaveBeenCalledWith('Comment deleted successfully')
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

  it.each(cases)('%s invalidates the lists and the comment detail', async (name, endpoint) => {
    comments[endpoint].mockResolvedValue({ data: comment() })
    const mutation = mutations()[name]
    const invalidate = spyInvalidate()

    await mutation.mutateAsync('cm1')

    expect(comments[endpoint]).toHaveBeenCalledWith('cm1')
    expect(invalidatedKeys(invalidate)).toEqual([
      queryKeys.comments(SPACE, CONTENT).lists(),
      queryKeys.comments(SPACE, CONTENT).detail('cm1'),
    ])
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
