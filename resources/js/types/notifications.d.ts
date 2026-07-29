type NotificationType =
  | 'comment.mention'
  | 'comment.reply'
  | 'invite.space'
  | 'invite.team'
  | 'usage.warning'
  | 'usage.exceeded'

interface NotificationRef {
  id: string
  name?: string | null
}

interface NotificationActor {
  id: string
  display_name: string
}

interface NotificationData {
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
  requester?: string
  plan?: { name: string; price: string; interval: string }
  [key: string]: unknown
}

interface NotificationResource {
  id: string
  type: NotificationType | string
  data: NotificationData
  read_at: string | null
  created_at: string
}

interface NotificationQueryParams extends BaseQueryParams {
  unread_only?: boolean
  type?: string
  per_page?: number
}

interface UnreadCountResponse {
  count: number
}
