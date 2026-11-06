import { computed, reactive, type InjectionKey, type MaybeRefOrGetter, toValue } from 'vue'

/**
 * Unsent comment text, held by the entry page so it outlives the components
 * that show it: closing a popover or switching a tab unmounts those.
 */
export type CommentDrafts = Map<string, string>

export const commentDraftsKey: InjectionKey<CommentDrafts> = Symbol('commentDrafts')

export const createCommentDrafts = (): CommentDrafts => reactive(new Map<string, string>())

export const commentDraftKeys = {
  entry: (contentId: string) => `entry:${contentId}`,
  field: (contentId: string, itemId: string | undefined, field: string | undefined) =>
    `field:${contentId}:${itemId ?? ''}:${field ?? ''}`,
  reply: (parentId: string) => `reply:${parentId}`,
  edit: (commentId: string) => `edit:${commentId}`,
}

/** One draft as a model. An empty draft is no draft. */
export const commentDraftModel = (drafts: CommentDrafts, key: MaybeRefOrGetter<string>) =>
  computed({
    get: () => drafts.get(toValue(key)) ?? '',
    set: (text: string) => {
      if (text) drafts.set(toValue(key), text)
      else drafts.delete(toValue(key))
    },
  })
