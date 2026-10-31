<script setup lang="ts">
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { Panel } from '~/components/ui/panel'
import type { JoinableTeamResource } from '~/types/team-domains'

const { useJoinableTeamsQuery, useRequestToJoinMutation, useCancelJoinRequestMutation } =
  useTeamDomains()

const { data: joinable } = useJoinableTeamsQuery()
const requestMutation = useRequestToJoinMutation()
const cancelMutation = useCancelJoinRequestMutation()

const isActing = (entry: JoinableTeamResource) =>
  (requestMutation.isPending.value && requestMutation.variables.value === entry.team.id) ||
  (cancelMutation.isPending.value && cancelMutation.variables.value === entry.request?.id)
</script>

<template>
  <div
    v-if="joinable?.length"
    class="grid gap-3"
  >
    <Panel
      v-for="entry in joinable"
      :key="entry.team.id"
    >
      <div class="flex flex-wrap items-center gap-4">
        <Avatar
          :name="entry.team.name"
          :avatar="entry.team.avatar"
        />
        <div class="min-w-0 flex-1">
          <p class="font-semibold text-primary">
            {{ $t('components.teamDomains.join.title', { team: entry.team.name }) }}
          </p>
          <p class="text-sm text-muted">
            {{
              entry.request?.status === 'pending'
                ? $t('components.teamDomains.join.pending')
                : entry.request?.status === 'declined'
                  ? $t('components.teamDomains.join.declined')
                  : $t('components.teamDomains.join.description', { domain: entry.domain })
            }}
          </p>
        </div>
        <Button
          v-if="entry.request?.status === 'pending'"
          variant="outline"
          :loading="isActing(entry)"
          @click="cancelMutation.mutate(entry.request.id)"
        >
          {{ $t('components.teamDomains.join.cancel') }}
        </Button>
        <Button
          v-else-if="!entry.request || entry.request.status === 'approved'"
          variant="primary"
          :loading="isActing(entry)"
          @click="requestMutation.mutate(entry.team.id)"
        >
          {{ $t('components.teamDomains.join.request') }}
        </Button>
      </div>
    </Panel>
  </div>
</template>
