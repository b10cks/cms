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
  mentions: SimpleReleaseResource[]
  resolved_at?: string
  created_at: string
  updated_at: string
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
