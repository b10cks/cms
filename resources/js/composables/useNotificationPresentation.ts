import type { RouteLocationRaw } from 'vue-router'

import { runtimeConfig } from '~/lib/runtime-config'

// Maps a raw notification `type` to the i18n/icon key used for presentation.
const TYPE_KEY_MAP: Record<string, string> = {
  'comment.mention': 'commentMention',
  'comment.reply': 'commentReply',
  'invite.space': 'inviteToSpace',
  'invite.team': 'inviteToTeam',
  'usage.warning': 'usageWarning',
  'usage.exceeded': 'usageExceeded',
  'billing.payment_requested': 'paymentRequested',
}

const TYPE_ICON_MAP: Record<string, string> = {
  commentMention: 'lucide:at-sign',
  commentReply: 'lucide:reply',
  inviteToSpace: 'lucide:user-plus',
  inviteToTeam: 'lucide:users',
  usageWarning: 'lucide:gauge',
  usageExceeded: 'lucide:triangle-alert',
  paymentRequested: 'lucide:credit-card',
  unknown: 'lucide:bell',
}

const typeKey = (type: string): string => TYPE_KEY_MAP[type] ?? 'unknown'

// The metrics that have a message under `notifications.metrics`; anything else
// would render its raw i18n key mid-sentence.
const KNOWN_METRICS = ['storage', 'traffic', 'ai']

/**
 * Resolves the icon, localised title/body and navigation target for an in-app
 * notification. Kept separate from the bell component so the (testable) mapping
 * logic can be reused anywhere notifications are rendered.
 */
export function useNotificationPresentation() {
  const { t } = useI18n()

  const iconFor = (n: NotificationResource): string => TYPE_ICON_MAP[typeKey(n.type)]

  const metricLabel = (d: NotificationData): string => {
    if (!d.metric) return ''
    return KNOWN_METRICS.includes(d.metric)
      ? (t(`notifications.metrics.${d.metric}`) as string)
      : d.metric
  }

  // "Someone mentioned you" beats " mentioned you" when the actor is missing.
  const person = (name?: string | null): string =>
    name || (t('labels.invites.page.inviterFallback') as string)

  const titleFor = (n: NotificationResource): string => {
    const d: NotificationData = n.data ?? {}
    return t(`notifications.items.${typeKey(n.type)}.title`, {
      author: person(d.author?.display_name),
      inviter: person(d.inviter?.display_name),
      content: d.content?.name ?? d.space?.name ?? '',
      space: d.space?.name ?? '',
      team: d.team?.name ?? '',
      metric: metricLabel(d),
      percentage: d.percentage ?? 0,
      requester: person(d.requester),
      plan: d.plan?.name ?? '',
    }) as string
  }

  const bodyFor = (n: NotificationResource): string => {
    const d: NotificationData = n.data ?? {}
    return t(`notifications.items.${typeKey(n.type)}.body`, {
      content: d.content?.name ?? d.space?.name ?? '',
      space: d.space?.name ?? '',
      team: d.team?.name ?? '',
      inviter: person(d.inviter?.display_name),
      metric: metricLabel(d),
      percentage: d.percentage ?? 0,
      requester: person(d.requester),
      plan: d.plan?.name ?? '',
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

    if (
      (n.type === 'usage.warning' ||
        n.type === 'usage.exceeded' ||
        n.type === 'billing.payment_requested') &&
      d.space?.id
    ) {
      // Without billing the subscription route is not registered — the usage
      // page is the closest destination for quota notifications.
      return {
        name: runtimeConfig.public.features.billing
          ? 'space-settings-subscription'
          : 'space-settings-usage',
        params: { space: d.space.id },
      }
    }

    return null
  }

  return { iconFor, titleFor, bodyFor, routeFor }
}
