import { afterEach, describe, expect, it, vi } from 'vitest'

const billing = { enabled: true }

vi.mock('~/lib/runtime-config', async () => {
  const actual =
    await vi.importActual<typeof import('~/lib/runtime-config')>('~/lib/runtime-config')

  return {
    runtimeConfig: {
      public: {
        ...actual.runtimeConfig.public,
        features: {
          ...actual.runtimeConfig.public.features,
          get billing() {
            return billing.enabled
          },
        },
      },
    },
  }
})

const { useNotificationPresentation } = await import('~/composables/useNotificationPresentation')

const { iconFor, titleFor, bodyFor, routeFor } = useNotificationPresentation()

const notification = (type: string, data: NotificationData = {}): NotificationResource =>
  ({ id: 'n1', type, data, read_at: null, created_at: '2026-03-15T12:00:00Z' }) as NotificationResource

afterEach(() => {
  billing.enabled = true
})

describe('iconFor', () => {
  it.each([
    ['comment.mention', 'lucide:at-sign'],
    ['comment.reply', 'lucide:reply'],
    ['invite.space', 'lucide:user-plus'],
    ['invite.team', 'lucide:users'],
    ['usage.warning', 'lucide:gauge'],
    ['usage.exceeded', 'lucide:triangle-alert'],
    ['billing.payment_requested', 'lucide:credit-card'],
  ])('maps %s to %s', (type, icon) => {
    expect(iconFor(notification(type))).toBe(icon)
  })

  it.each(['something.else', '', 'commentMention'])('falls back to the bell for %s', (type) => {
    expect(iconFor(notification(type))).toBe('lucide:bell')
  })
})

describe('titleFor', () => {
  it('names the comment author', () => {
    expect(
      titleFor(notification('comment.mention', { author: { id: 'u1', display_name: 'Ada' } }))
    ).toBe('Ada mentioned you')
    expect(titleFor(notification('comment.reply', { author: { id: 'u1', display_name: 'Ada' } }))).toBe(
      'Ada replied to your comment'
    )
  })

  it('names the space or team an invite targets', () => {
    expect(titleFor(notification('invite.space', { space: { id: 's1', name: 'Acme' } }))).toBe(
      'Invitation to Acme'
    )
    expect(titleFor(notification('invite.team', { team: { id: 't1', name: 'Crew' } }))).toBe(
      'Invitation to Crew'
    )
  })

  it('names the space and metric for quota notifications', () => {
    const data = { space: { id: 's1', name: 'Acme' }, metric: 'storage' }

    expect(titleFor(notification('usage.warning', data))).toBe('Acme is nearing its storage limit')
    expect(titleFor(notification('usage.exceeded', data))).toBe('Acme exceeded its storage limit')
  })

  it.each([
    ['storage', 'storage'],
    ['traffic', 'traffic'],
    ['ai', 'AI credit'],
  ])('translates the %s metric', (metric, label) => {
    expect(
      titleFor(notification('usage.warning', { space: { id: 's1', name: 'Acme' }, metric }))
    ).toBe(`Acme is nearing its ${label} limit`)
  })

  it('names the requester and space for a payment request', () => {
    expect(
      titleFor(
        notification('billing.payment_requested', {
          requester: 'Ada',
          space: { id: 's1', name: 'Acme' },
        })
      )
    ).toBe('Ada requests a payment for Acme')
  })

  it('uses the generic title for an unknown type', () => {
    expect(titleFor(notification('something.else'))).toBe('Notification')
  })

  it('names a missing actor rather than leaving a gap in the sentence', () => {
    expect(titleFor(notification('comment.mention'))).toBe('Someone mentioned you')
  })

  it('falls back to the raw metric for one with no message', () => {
    // notifications.metrics only covers storage/traffic/ai, so a new backend
    // quota metric would otherwise render its raw i18n key mid-sentence.
    expect(
      titleFor(notification('usage.warning', { space: { id: 's1', name: 'Acme' }, metric: 'seats' }))
    ).toBe('Acme is nearing its seats limit')
  })

  it('tolerates a missing data payload', () => {
    const bare = { id: 'n1', type: 'comment.mention' } as unknown as NotificationResource

    expect(titleFor(bare)).toBe('Someone mentioned you')
  })
})

describe('bodyFor', () => {
  it('names the content a comment lives in', () => {
    expect(bodyFor(notification('comment.mention', { content: { id: 'c1', name: 'Home' } }))).toBe(
      'in Home'
    )
  })

  it('falls back to the space name when no content is given', () => {
    expect(bodyFor(notification('comment.reply', { space: { id: 's1', name: 'Acme' } }))).toBe(
      'in Acme'
    )
  })

  it('names the inviter for invites', () => {
    const inviter = { inviter: { id: 'u1', display_name: 'Ada' } }

    expect(bodyFor(notification('invite.space', inviter))).toBe('Ada invited you to collaborate')
    expect(bodyFor(notification('invite.team', inviter))).toBe('Ada invited you to join')
  })

  it('reports the used percentage, defaulting to zero', () => {
    expect(bodyFor(notification('usage.warning', { percentage: 92 }))).toBe(
      '92% of the monthly allowance used'
    )
    expect(bodyFor(notification('usage.exceeded'))).toBe('0% of the monthly allowance used')
  })

  it('names the plan of a payment request', () => {
    expect(
      bodyFor(
        notification('billing.payment_requested', {
          plan: { name: 'Pro', price: '29.00', interval: 'month' },
        })
      )
    ).toBe('Take over the Pro plan to become the billing owner')
  })

  it('is empty for an unknown type', () => {
    expect(bodyFor(notification('something.else'))).toBe('')
  })
})

describe('routeFor', () => {
  it('opens the content a comment refers to', () => {
    const route = routeFor(
      notification('comment.mention', {
        space: { id: 's1', name: 'Acme' },
        content: { id: 'c1', name: 'Home' },
      })
    )

    expect(route).toEqual({
      name: 'space-content-contentId',
      params: { space: 's1', contentId: 'c1' },
    })
  })

  it.each([
    [{ space: { id: 's1', name: 'Acme' } }],
    [{ content: { id: 'c1', name: 'Home' } }],
    [{}],
  ])('has no target for a comment notification missing ids', (data) => {
    expect(routeFor(notification('comment.reply', data as NotificationData))).toBeNull()
  })

  it.each(['invite.space', 'invite.team'])('sends %s to the invites page', (type) => {
    expect(routeFor(notification(type))).toEqual({ name: 'account-settings-invites' })
  })

  it('needs no space id for invites', () => {
    expect(routeFor(notification('invite.team'))).not.toBeNull()
  })

  it.each(['usage.warning', 'usage.exceeded', 'billing.payment_requested'])(
    'sends %s to the subscription page when billing is on',
    (type) => {
      expect(routeFor(notification(type, { space: { id: 's1', name: 'Acme' } }))).toEqual({
        name: 'space-settings-subscription',
        params: { space: 's1' },
      })
    }
  )

  it('sends quota notifications to the usage page without billing', () => {
    billing.enabled = false

    expect(routeFor(notification('usage.warning', { space: { id: 's1', name: 'Acme' } }))).toEqual({
      name: 'space-settings-usage',
      params: { space: 's1' },
    })
  })

  it('has no target for a quota notification without a space', () => {
    expect(routeFor(notification('usage.exceeded'))).toBeNull()
  })

  it('has no target for an unknown type', () => {
    expect(routeFor(notification('something.else', { space: { id: 's1', name: 'Acme' } }))).toBeNull()
  })
})
