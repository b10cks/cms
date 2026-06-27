import type { RouteLocationRaw } from 'vue-router'

import type { NotificationData, NotificationResource } from '~/types/notifications'

// Maps a raw notification `type` to the i18n/icon key used for presentation.
const TYPE_KEY_MAP: Record<string, string> = {
  'comment.mention': 'commentMention',
  'comment.reply': 'commentReply',
  'invite.space': 'inviteToSpace',
  'invite.team': 'inviteToTeam',
}

const TYPE_ICON_MAP: Record<string, string> = {
  commentMention: 'lucide:at-sign',
  commentReply: 'lucide:reply',
  inviteToSpace: 'lucide:user-plus',
  inviteToTeam: 'lucide:users',
  unknown: 'lucide:bell',
}

const typeKey = (type: string): string => TYPE_KEY_MAP[type] ?? 'unknown'

/**
 * Resolves the icon, localised title/body and navigation target for an in-app
 * notification. Kept separate from the bell component so the (testable) mapping
 * logic can be reused anywhere notifications are rendered.
 */
export function useNotificationPresentation() {
  const { t } = useI18n()

  const iconFor = (n: NotificationResource): string => TYPE_ICON_MAP[typeKey(n.type)]

  const titleFor = (n: NotificationResource): string => {
    const d: NotificationData = n.data ?? {}
    return t(`notifications.items.${typeKey(n.type)}.title`, {
      author: d.author?.display_name ?? '',
      inviter: d.inviter?.display_name ?? '',
      content: d.content?.name ?? d.space?.name ?? '',
      space: d.space?.name ?? '',
      team: d.team?.name ?? '',
    }) as string
  }

  const bodyFor = (n: NotificationResource): string => {
    const d: NotificationData = n.data ?? {}
    return t(`notifications.items.${typeKey(n.type)}.body`, {
      content: d.content?.name ?? d.space?.name ?? '',
      space: d.space?.name ?? '',
      team: d.team?.name ?? '',
      inviter: d.inviter?.display_name ?? '',
    }) as string
  }

  const routeFor = (n: NotificationResource): RouteLocationRaw | null => {
    const d: NotificationData = n.data ?? {}

    if (
      (n.type === 'comment.mention' || n.type === 'comment.reply') &&
      d.space?.id &&
      d.content?.id
    ) {
      return {
        name: 'space-content-contentId',
        params: { space: d.space.id, contentId: d.content.id },
      }
    }

    if (n.type === 'invite.space' || n.type === 'invite.team') {
      return { name: 'account-settings-invites' }
    }

    return null
  }

  return { iconFor, titleFor, bodyFor, routeFor }
}
