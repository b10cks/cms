<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { InviteStatus } from '~/types/invites.d'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const inviteId = computed(() => route.params.id as string)

const { selectTeam } = useGlobalTeam()
const { isAuthenticated } = useAuth()
const { usePublicInviteQuery, useAcceptInviteMutation } = useInvites()
const { data: invite, isPending, error } = usePublicInviteQuery(inviteId)
const { mutate: acceptInvite, isPending: isAccepting } = useAcceptInviteMutation()

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

const resourceName = computed(() => {
  if (invite.value?.space) {
    return `space "${invite.value.space.name}"`
  }
  if (invite.value?.team) {
    return `team "${invite.value.team.name}"`
  }
  return 'this resource'
})

const inviterName = computed(() => {
  return invite.value?.inviter?.name || 'Someone'
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

const hasToken = computed(() => token.value.length > 0)

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
</script>

<template>
  <div class="flex w-full grow items-center justify-center bg-background">
    <div class="w-full max-w-md space-y-6">
      <h1 class="text-2xl font-bold">{{ $t('labels.invites.page.title') }}</h1>
      <Alert
        v-if="isPending"
        class="space-y-4"
      >
        {{ $t('labels.invites.page.loading') }}
      </Alert>
      <Alert
        v-else-if="error"
        icon="lucide:alert-circle"
        color="destructive"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.invalidOrExpired') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.invalidOrExpiredDesc') }}</p>
      </Alert>
      <Alert
        v-else-if="isExpired"
        color="destructive"
        icon="lucide:clock"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.expired') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.expiredDesc') }}</p>
      </Alert>
      <Alert
        v-else-if="isAccepted"
        color="success"
        icon="lucide:check-circle"
      >
        <p class="font-semibold">{{ $t('labels.invites.page.alreadyAccepted') }}</p>
        <p class="mt-1 text-sm">{{ $t('labels.invites.page.alreadyAcceptedDesc') }}</p>
      </Alert>

      <form
        v-else-if="invite"
        class="space-y-6"
        @submit.prevent="handleAccept"
      >
        <div class="space-y-2">
          <p class="text-sm text-muted-foreground">
            {{ $t('labels.invites.page.fromInviter', { name: inviterName }) }}
          </p>
          <div>
            {{ $t('labels.invites.page.invitedToJoin') }}
            <span class="font-semibold">{{ resourceName }}</span>
          </div>
        </div>
        <Alert
          v-if="invite.message"
          variant="modern"
        >
          {{ invite.message }}
        </Alert>

        <Alert
          v-if="isPendingInvite && !hasToken"
          color="destructive"
          icon="lucide:shield-alert"
        >
          <p class="font-semibold">{{ $t('labels.invites.page.invalidOrExpired') }}</p>
          <p class="mt-1 text-sm">{{ $t('labels.invites.page.invalidOrExpiredDesc') }}</p>
        </Alert>

        <div
          v-if="isPendingInvite && isAuthenticated"
          class="space-y-2"
        >
          <Button
            type="submit"
            variant="primary"
            class="w-full"
            :disabled="isAccepting || !hasToken"
          >
            <Icon
              v-if="isAccepting"
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{
              isAccepting
                ? $t('labels.invites.page.acceptingButton')
                : $t('labels.invites.page.acceptButton')
            }}
          </Button>

          <Button
            type="button"
            variant="outline"
            class="w-full"
            @click="router.back()"
          >
            {{ $t('labels.invites.page.declineButton') }}
          </Button>
        </div>

        <div
          v-else-if="isPendingInvite"
          class="space-y-2"
        >
          <Button
            type="button"
            variant="primary"
            class="w-full"
            @click="router.push(loginPath)"
          >
            {{ $t('actions.login') }}
          </Button>
          <Button
            type="button"
            variant="outline"
            class="w-full"
            @click="router.push(signupPath)"
          >
            {{ $t('actions.signup') }}
          </Button>
        </div>
      </form>
    </div>
  </div>
</template>
