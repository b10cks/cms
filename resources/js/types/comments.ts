export interface SimpleUserResource {
  id: string
  external_id: string
  name: string
  initials: string
  avatar?: string
}

export interface SimpleReleaseResource {
  id: string
  name: string
  published_at: string | null
  created_at: string
}

export interface CommentPosition {
  x?: number
  y?: number
}

export interface CommentResource {
  id: string
  external_id: string
  content_id: string
  content_version_id?: string
  parent_id?: string
  body: string
  is_resolved: boolean
  item_id?: string
  field?: string
  replies?: CommentResource[]
  reactions: Record<string, SimpleUserResource[]>
  position?: CommentPosition
  author: SimpleUserResource
  mentions: SimpleUserResource[]
  resolved_at?: string
  created_at: string
  updated_at: string
}

/** Someone a comment can mention. */
export interface MentionablePerson {
  id: string
  name: string
  avatar?: string | null
}

/** The writes a thread can do. A rejected reply or edit leaves its composer open with the text. */
export interface CommentActions {
  reply: (parent: CommentResource, body: string) => Promise<unknown>
  edit: (comment: CommentResource, body: string) => Promise<unknown>
  remove: (comment: CommentResource) => void
  resolve: (commentId: string) => void
  unresolve: (commentId: string) => void
  addReaction: (commentId: string, emoji: string) => void
  removeReaction: (commentId: string, emoji: string) => void
}

export interface CommentReactionResource {
  id: string
  comment_id: string
  emoji: string
  author: SimpleUserResource
  created_at: string
}

export interface CreateCommentRequest {
  content_version_id?: string
  parent_id?: string
  body: string
  item_id?: string
  field?: string
  position?: CommentPosition
}

export interface UpdateCommentRequest {
  body?: string
  item_id?: string
  field?: string
  position?: CommentPosition
}
