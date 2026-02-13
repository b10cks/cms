<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import TeamSelector from '~/components/TeamSelector.vue'

import AppHeader from '~/components/AppHeader.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { InviteStatus } from '~/types/invites.d'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const inviteId = computed(() => route.params.id as string)

const { selectTeam } = useGlobalTeam()
const { usePublicInviteQuery, useAcceptInviteMutation } = useInvites()
const { data: invite, isPending, error } = usePublicInviteQuery(inviteId)
const { mutate: acceptInvite, isPending: isAccepting } = useAcceptInviteMutation()

useSeoMeta({
  title: computed(() => t('labels.invites.page.title')),
})

const token = computed(() => route.query.token as string)

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
  if (invite.value?.inviter) {
    return `${invite.value.inviter.firstname} ${invite.value.inviter.lastname}`
  }
  return 'Someone'
})

const isExpired = computed(() => {
  return invite.value?.status === InviteStatus.EXPIRED
})

const isAccepted = computed(() => {
  return invite.value?.status === InviteStatus.ACCEPTED
})

const handleAccept = () => {
  if (invite.value?.id) {
    acceptInvite(
      { inviteId: invite.value.id, payload: { token: token.value } },
      {
        onSuccess: (data) => {
          if (data.space_id) {
            router.push(`/${data.space_id}`)
          } else if (data.team_id) {
            selectTeam(data.team_id)
            router.push('/')
          }
        },
      }
    )
  }
}
</script>

<template>
  <AppHeader>
    <div class="flex items-start">
      <TeamSelector size="sm" />
    </div>
  </AppHeader>
  <div class="flex w-full grow items-center justify-center bg-background pt-14">
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
        <div>
          {{ $t('labels.invites.page.invitedToJoin') }}
          <span class="font-semibold">{{ resourceName }}</span>
        </div>
        <Alert
          variant="modern"
          v-if="invite.message"
        >
          {{ invite.message }}
        </Alert>

        <div class="space-y-2">
          <Button
            type="submit"
            variant="primary"
            class="w-full"
            :disabled="isAccepting"
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
      </form>
    </div>
  </div>
</template>
