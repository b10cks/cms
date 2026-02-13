<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField, SelectField, TextField } from '~/components/ui/form'
import type { CreateInvitePayload } from '~/types/invites'

const open = defineModel<boolean>('open')
const { t } = useI18n()

const props = defineProps<{
  spaceId?: string
  teamId?: string
  resourceType: 'space' | 'team'
}>()

const { useCreateSpaceInviteMutation, useCreateTeamInviteMutation } = useInvites()
const createSpaceInviteMutation = useCreateSpaceInviteMutation()
const createTeamInviteMutation = useCreateTeamInviteMutation()

const formData = ref<CreateInvitePayload>({
  email: '',
  role: 'member',
  message: '',
  expires_in_days: 7,
})

const isLoading = computed(() => {
  return props.resourceType === 'space'
    ? createSpaceInviteMutation.isPending.value
    : createTeamInviteMutation.isPending.value
})

const handleSendInvite = async () => {
  const mutationOptions = {
    onSuccess: () => {
      open.value = false
      formData.value = {
        email: '',
        role: 'member',
        message: '',
        expires_in_days: 7,
      }
    },
  }
  if (props.resourceType === 'space' && props.spaceId) {
    createSpaceInviteMutation.mutate(
      { spaceId: props.spaceId, payload: formData.value },
      mutationOptions
    )
  } else if (props.resourceType === 'team' && props.teamId) {
    createTeamInviteMutation.mutate(
      { teamId: props.teamId, payload: formData.value },
      mutationOptions
    )
  }
}

const roles = ['member', 'editor', 'admin', 'owner'] as const

const roleOptions = computed(() =>
  roles.map((role) => ({
    label: t(`labels.invites.filters.roles.${role}`),
    value: role,
  }))
)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent>
      <DialogHeaderCombined
        :title="$t('labels.invites.create', { name: resourceType })"
        :description="$t('labels.invites.createDescription')"
      />

      <form
        class="grid gap-4"
        @submit.prevent="handleSendInvite"
      >
        <InputField
          v-model="formData.email"
          type="email"
          name="email"
          :label="$t('labels.invites.fields.email')"
          :placeholder="$t('labels.invites.fields.emailPlaceholder')"
          required
        />

        <SelectField
          name="role"
          :label="$t('labels.invites.fields.role')"
          :placeholder="$t('labels.invites.fields.rolePlaceholder')"
          v-model="formData.role"
          :options="roleOptions"
          required
        />

        <TextField
          v-model="formData.message"
          as="textarea"
          name="message"
          :label="$t('labels.invites.fields.message')"
          :placeholder="$t('labels.invites.fields.messagePlaceholder')"
          :rows="3"
        />

        <SelectField
          name="expires_in_days"
          :label="$t('labels.invites.fields.expires')"
          v-model="formData.expires_in_days"
          :options="
            [1, 7, 14, 30, 90].map((days) => ({
              label: $t('labels.invites.fields.expiresDays', { days }),
              value: days,
            }))
          "
          required
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
            :disabled="isLoading || !formData.email"
          >
            <Icon
              v-if="isLoading"
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{ isLoading ? $t('labels.invites.sending') : $t('actions.send') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
