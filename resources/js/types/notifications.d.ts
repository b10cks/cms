import type { BaseQueryParams } from '~/types'

export type NotificationType =
  | 'comment.mention'
  | 'comment.reply'
  | 'invite.space'
  | 'invite.team'
  | 'usage.warning'
  | 'usage.exceeded'

export interface NotificationRef {
  id: string
  name?: string | null
}

export interface NotificationActor {
  id: string
  display_name: string
}

export interface NotificationData {
  space?: NotificationRef
  content?: NotificationRef
  team?: NotificationRef | null
  invite?: { id: string }
  inviter?: NotificationActor
  author?: NotificationActor
  item_id?: string | null
  field?: string | null
  excerpt?: string
  metric?: string
  threshold?: number
  percentage?: number
  used?: string
  limit?: string
  [key: string]: unknown
}

export interface NotificationResource {
  id: string
  type: NotificationType | string
  data: NotificationData
  read_at: string | null
  created_at: string
}

export interface NotificationQueryParams extends BaseQueryParams {
  unread_only?: boolean
  type?: string
  per_page?: number
}

export interface UnreadCountResponse {
  count: number
}
