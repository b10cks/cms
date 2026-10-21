import type { CommentsQueryParams } from '~/api/resources/comments'
import type { CommentResource } from '~/types/comments'

/** Whether a root comment belongs in a cached list fetched with `params`. */
const matchesFilter = (comment: CommentResource, params: CommentsQueryParams | undefined) => {
  const filter = params?.filter
  if (!filter) return true

  return (
    (filter.content_version_id === undefined ||
      filter.content_version_id === comment.content_version_id) &&
    (filter.item_id === undefined || filter.item_id === (comment.item_id ?? null)) &&
    (filter.field === undefined || filter.field === comment.field) &&
    (filter.is_resolved === undefined || filter.is_resolved === comment.is_resolved)
  )
}

/** Lists hold root comments with their replies nested, oldest first. */
export const addComment = (
  list: CommentResource[],
  comment: CommentResource,
  params?: CommentsQueryParams
): CommentResource[] => {
  if (!comment.parent_id) {
    return matchesFilter(comment, params) ? [...list, comment] : list
  }

  return list.map((root) =>
    root.id === comment.parent_id ? { ...root, replies: [...(root.replies ?? []), comment] } : root
  )
}

export const patchComment = (
  list: CommentResource[],
  id: string,
  patch: Partial<CommentResource>
): CommentResource[] =>
  list.map((root) => {
    if (root.id === id) return { ...root, ...patch }
    if (!root.replies?.some((reply) => reply.id === id)) return root

    return {
      ...root,
      replies: root.replies.map((reply) => (reply.id === id ? { ...reply, ...patch } : reply)),
    }
  })

export const removeComment = (list: CommentResource[], id: string): CommentResource[] =>
  list
    .filter((root) => root.id !== id)
    .map((root) =>
      root.replies?.some((reply) => reply.id === id)
        ? { ...root, replies: root.replies.filter((reply) => reply.id !== id) }
        : root
    )
