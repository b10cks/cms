<script setup lang="ts">
import { cn } from '@/lib/utils'
import Logo from '~/assets/logo.svg'
import Markdown from '~/components/Markdown.vue'
import { Alert } from '~/components/ui/alert'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { InviteStatus } from '~/types/invites.d'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const inviteId = computed(() => route.params.id as string)

const { selectTeam } = useGlobalTeam()
const { isAuthenticated } = useAuth()
const { usePublicInviteQuery, useAcceptInviteMutation, useDeclineInviteMutation } = useInvites()

useSeoMeta({
  title: computed(() => t('labels.invites.page.title')),
})

const token = computed(() => {
  const inviteToken = route.query.invite_token
  const legacyToken = route.query.token

  if (typeof inviteToken === 'string' && inviteToken.length > 0) {
    return inviteToken
  }

  if (typeof legacyToken === 'string' && legacyToken.length > 0) {
    return legacyToken
  }

  return ''
})

const { data: invite, isPending, error } = usePublicInviteQuery(inviteId, token)
const { mutate: acceptInvite, isPending: isAccepting } = useAcceptInviteMutation()
const { mutate: declineInvite, isPending: isDeclining } = useDeclineInviteMutation()

const invitePath = computed(() => {
  const query = token.value ? `?invite_token=${encodeURIComponent(token.value)}` : ''
  return `/invites/${inviteId.value}${query}`
})

const loginPath = computed(() => {
  const params = new URLSearchParams()
  params.set('return', invitePath.value)
  params.set('invite_id', inviteId.value)

  if (token.value) {
    params.set('invite_token', token.value)
  }

  return `/login?${params.toString()}`
})
const signupPath = computed(() => {
  const params = new URLSearchParams()
  params.set('invite_id', inviteId.value)
  params.set('return', invitePath.value)

  if (token.value) {
    params.set('invite_token', token.value)
  }

  return `/login/signup?${params.toString()}`
})

const resourceName = computed(() => invite.value?.space?.name || invite.value?.team?.name || '')

const inviterName = computed(() => {
  return invite.value?.inviter?.name || t('labels.invites.page.inviterFallback')
})

/** Only built-in roles get a name here; custom role keys stay hidden. */
const roleLabelKeys: Record<string, string> = {
  owner: 'labels.invites.filters.roles.owner',
  admin: 'labels.invites.filters.roles.admin',
  editor: 'labels.invites.filters.roles.editor',
  member: 'labels.invites.filters.roles.member',
  billing: 'labels.invites.filters.roles.billing',
  viewer: 'labels.invites.filters.roles.viewer',
}

const roleLabel = computed(() => {
  const key = invite.value?.role ? roleLabelKeys[invite.value.role] : undefined
  return key ? t(key) : ''
})

const expiresInDays = computed(() => {
  if (!invite.value?.expires_at) return 0
  return Math.ceil((new Date(invite.value.expires_at).getTime() - Date.now()) / (1000 * 60 * 60 * 24))
})

const isExpired = computed(() => {
  return invite.value?.status === InviteStatus.EXPIRED
})

const isAccepted = computed(() => {
  return invite.value?.status === InviteStatus.ACCEPTED
})

const isPendingInvite = computed(() => {
  return invite.value?.status === InviteStatus.PENDING
})

const isDeclined = computed(() => {
  return invite.value?.status === InviteStatus.DECLINED
})

const hasToken = computed(() => token.value.length > 0)

/**
 * Signed-out visitors get the marketing split layout, which centres the slot itself.
 * Signed-in ones land inside the app shell, where the card has to centre itself.
 */
const rootClass = computed(() =>
  cn('grid w-full max-w-md space-y-8', isAuthenticated.value && 'm-auto p-8')
)

const heading = computed(() => {
  if (!isPendingInvite.value || !resourceName.value) {
    return t('labels.invites.page.headingFallback')
  }
  return t('labels.invites.page.heading', { name: resourceName.value })
})

const handleAccept = () => {
  if (invite.value?.id && token.value) {
    acceptInvite(
      { inviteId: invite.value.id, payload: { token: token.value } },
      {
        onSuccess: (data) => {
          if (data.space_id) {
            router.push(`/${data.space_id}`)
          } else if (data.team_id) {
            selectTeam(data.team_id)
            router.push(`/teams/${data.team_id}`)
          }
        },
      }
    )
  }
}

const handleDecline = () => {
  if (invite.value?.id) {
    declineInvite(
      { inviteId: invite.value.id },
      {
        onSuccess: () => {
          router.push('/')
        },
      }
    )
  }
}
</script>

<template>
  <div :class="rootClass">
    <div class="grid gap-4">
      <Logo
        v-if="!isAuthenticated"
        alt="b10cks logo"
        class="h-8 w-8 text-primary"
      />
      <h1
        class="text-2xl font-semibold text-primary"
        v-text="heading"
      />
      <p
        v-if="isPendingInvite && hasToken"
        class="text-sm text-muted"
      >
        {{
          isAuthenticated
            ? $t('labels.invites.page.introAccept')
            : $t('labels.invites.page.introSignup')
        }}
      </p>
    </div>

    <Alert
      v-if="isPending"
      variant="modern"
    >
      {{ $t('labels.invites.page.loading') }}
    </Alert>

    <div
      v-else-if="error || isExpired || isAccepted || isDeclined"
      class="grid gap-6"
    >
      <Alert
        v-if="error"
        icon="lucide:alert-circle"
        color="destructive"
        variant="modern"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.invalidOrExpired') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.invalidOrExpiredDesc') }}</p>
      </Alert>
      <Alert
        v-else-if="isExpired"
        color="warning"
        icon="lucide:clock"
        variant="modern"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.expired') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.expiredDesc') }}</p>
      </Alert>
      <Alert
        v-else-if="isAccepted"
        color="success"
        icon="lucide:check-circle"
        variant="modern"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.alreadyAccepted') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.alreadyAcceptedDesc') }}</p>
      </Alert>
      <Alert
        v-else
        icon="lucide:circle-slash"
        variant="modern"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.declined') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.declinedDesc') }}</p>
      </Alert>
      <RouterLink
        :to="isAuthenticated ? '/' : '/login'"
        class="text-center text-sm text-muted hover:text-primary"
      >
        {{
          isAuthenticated
            ? $t('labels.invites.page.backToDashboard')
            : $t('labels.login.backToLogin')
        }}
      </RouterLink>
    </div>

    <div
      v-else-if="invite"
      class="grid gap-6"
    >
      <div class="flex items-center gap-3 rounded-xl border border-border bg-surface p-4">
        <Avatar
          :name="inviterName"
          :avatar="invite.inviter?.avatar"
          size="lg"
        />
        <div class="min-w-0 space-y-0.5">
          <p class="truncate font-semibold text-primary">
            {{ $t('labels.invites.page.invitedYou', { name: inviterName }) }}
          </p>
          <p class="text-sm text-muted">
            <template v-if="roleLabel">
              {{ $t('labels.invites.page.invitedAsRole', { role: roleLabel }) }}
            </template>
            <template v-if="roleLabel && expiresInDays > 0"> · </template>
            <template v-if="expiresInDays > 0">
              {{ $t('labels.invites.tooltip.expiresInDays', expiresInDays) }}
            </template>
          </p>
        </div>
      </div>

      <Alert
        v-if="invite.message"
        variant="modern"
        icon="lucide:message-square-quote"
      >
        {{ invite.message }}
      </Alert>

      <Alert
        v-if="isPendingInvite && !hasToken"
        color="destructive"
        icon="lucide:shield-alert"
        variant="modern"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.invalidOrExpired') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.invalidOrExpiredDesc') }}</p>
      </Alert>

      <form
        v-if="isPendingInvite && isAuthenticated"
        class="grid gap-2"
        @submit.prevent="handleAccept"
      >
        <Button
          type="submit"
          variant="primary"
          size="lg"
          :loading="isAccepting"
          :disabled="!hasToken"
        >
          {{
            isAccepting
              ? $t('labels.invites.page.acceptingButton')
              : $t('labels.invites.page.acceptButton')
          }}
        </Button>

        <Button
          type="button"
          variant="ghost"
          :loading="isDeclining"
          :disabled="isAccepting"
          @click="handleDecline"
        >
          {{ $t('labels.invites.page.declineButton') }}
        </Button>
      </form>

      <div
        v-else-if="isPendingInvite"
        class="grid gap-4"
      >
        <Button
          type="button"
          variant="primary"
          size="lg"
          :disabled="!hasToken"
          @click="router.push(signupPath)"
        >
          {{ $t('labels.invites.page.signupButton') }}
        </Button>
        <Markdown
          class="text-center text-sm text-muted"
          :content="$t('labels.invites.page.loginHint', { url: loginPath })"
        />
      </div>
    </div>
  </div>
</template>
