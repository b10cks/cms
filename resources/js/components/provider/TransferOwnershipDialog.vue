<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { SelectField } from '~/components/ui/form'
import { useProviderConsole } from '~/composables/useProviderConsole'
import type { ProviderUserDetail } from '~/types/provider-console'

const props = defineProps<{ user: ProviderUserDetail }>()
const open = defineModel<boolean>('open', { required: true })

const { useTransferOwnershipMutation } = useProviderConsole()
const transfer = useTransferOwnershipMutation()

const teamId = ref<string>()
const newOwnerId = ref<string>()

const team = computed(() => props.user.owned_teams.find((owned) => owned.id === teamId.value))
const teamOptions = computed(() =>
  props.user.owned_teams.map((owned) => ({ value: owned.id, label: owned.name }))
)
const ownerOptions = computed(() =>
  (team.value?.candidates ?? []).map((candidate) => ({
    value: candidate.id,
    label: `${candidate.name} (${candidate.email})`,
  }))
)

watch(open, (isOpen) => {
  if (!isOpen) return
  teamId.value = props.user.owned_teams[0]?.id
})

watch(teamId, () => {
  newOwnerId.value = team.value?.candidates[0]?.id
})

const canSubmit = computed(() => !!teamId.value && !!newOwnerId.value && !transfer.isPending.value)

const submit = () => {
  if (!teamId.value || !newOwnerId.value) return

  transfer.mutate(
    { id: props.user.id, payload: { team_id: teamId.value, new_owner_id: newOwnerId.value } },
    { onSuccess: () => (open.value = false) }
  )
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="$t('providerConsole.transfer.title')"
        :description="$t('providerConsole.transfer.description', { name: user.name })"
      />
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <SelectField
          v-model="teamId"
          name="team"
          :label="$t('providerConsole.transfer.team')"
          :options="teamOptions"
        />
        <SelectField
          v-model="newOwnerId"
          name="new-owner"
          :label="$t('providerConsole.transfer.newOwner')"
          :placeholder="$t('providerConsole.transfer.noCandidates')"
          :disabled="ownerOptions.length === 0"
          :options="ownerOptions"
        />
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="primary"
            :loading="transfer.isPending.value"
            :disabled="!canSubmit"
          >
            {{ $t('providerConsole.transfer.submit') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
