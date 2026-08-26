<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import TeamSelectField, { type TeamSelectOption } from '~/components/teams/TeamSelectField.vue'
import { FormField, SelectField, TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { useFileUpload } from '~/composables/useFileUpload'
import { useTeamTypes } from '~/composables/useTeamTypes'
import { flattenNestedTeams } from '~/lib/team-hierarchy'
import type { TeamHierarchyItem, TeamResource, UpdateTeamPayload } from '~/types/teams'

const props = withDefaults(
  defineProps<{
    team: TeamResource
    hierarchy: TeamHierarchyItem[]
    isRoot?: boolean
  }>(),
  {
    isRoot: false,
  }
)

const { t } = useI18n()
const { teamTypeOptions } = useTeamTypes()
const { useUpdateTeamMutation, useDeleteTeamAvatarMutation, invalidateTeam } = useTeams()

const updateMutation = useUpdateTeamMutation()
const deleteAvatarMutation = useDeleteTeamAvatarMutation()

const formData = ref<UpdateTeamPayload>({
  name: '',
  description: '',
  type: 'partner',
  parent_id: null,
  icon: null,
  color: null,
})

watch(
  () => props.team,
  (team) => {
    formData.value = {
      name: team.name,
      description: team.description || '',
      type: team.type,
      parent_id: team.parent_id,
      icon: team.icon,
      color: team.color,
    }
  },
  { immediate: true }
)

// Valid re-parent destinations: the tree minus the team itself and its
// descendants, since a team cannot move under its own child. Teams the user
// may not add children to are shown for context but not selectable.
// "No parent" (top level) is reserved for root.
const parentTeams = computed<TeamSelectOption[]>(() =>
  flattenNestedTeams(props.hierarchy, props.team.id).map((team) => ({
    ...team,
    disabled: !team.can_create_child,
  }))
)

const noParentOption = computed(() =>
  props.isRoot ? { label: t('labels.teams.noParent') as string, icon: 'circle-slash' } : null
)

const handleSave = () => {
  if (!formData.value.name?.trim()) return

  const payload: UpdateTeamPayload = {}
  if (formData.value.name !== props.team.name) payload.name = formData.value.name
  if (formData.value.description !== (props.team.description || ''))
    payload.description = formData.value.description
  if (formData.value.type !== props.team.type) payload.type = formData.value.type
  if (formData.value.parent_id !== props.team.parent_id)
    payload.parent_id = formData.value.parent_id
  if (formData.value.color !== props.team.color) payload.color = formData.value.color
  if (formData.value.icon !== props.team.icon) payload.icon = formData.value.icon

  if (Object.keys(payload).length === 0) return

  updateMutation.mutate({ id: props.team.id, payload })
}

// Avatar upload
const avatarInputRef = ref<HTMLInputElement | null>(null)
const uploadProgress = ref(0)
const { upload, isUploading } = useFileUpload()

const handleAvatarFile = async (file: File) => {
  if (!file) return
  uploadProgress.value = 0
  try {
    await upload(file, {
      url: `/mgmt/v1/teams/${props.team.id}/avatar`,
      fieldName: 'avatar',
      onProgress: (p: number) => (uploadProgress.value = p),
    })
    invalidateTeam(props.team.id)
    toast.success(t('labels.teams.settings.avatarUploaded'))
  } catch (e) {
    toast.error(e instanceof Error ? e.message : t('labels.teams.settings.avatarUploadFailed'))
  }
}

const onAvatarInputChange = (e: Event) => {
  const files = (e.target as HTMLInputElement).files
  if (files?.[0]) handleAvatarFile(files[0])
}

const onDropAvatar = (e: DragEvent) => {
  e.preventDefault()
  if (e.dataTransfer?.files?.[0]) handleAvatarFile(e.dataTransfer.files[0])
}

const handleRemoveAvatar = () => {
  deleteAvatarMutation.mutate(props.team.id)
}
</script>

<template>
  <Card variant="none">
    <CardHeader>
      <CardTitle>{{ $t('labels.teams.settings.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.teams.settings.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="grid max-w-2xl gap-6">
      <FormField
        name="team-avatar"
        :label="$t('labels.teams.settings.avatar')"
        :description="$t('labels.teams.settings.avatarDescription')"
      >
        <div
          class="flex items-center gap-4"
          @drop="onDropAvatar"
          @dragover.prevent
        >
          <button
            type="button"
            class="cursor-pointer rounded-2xl transition-opacity hover:opacity-80"
            @click="avatarInputRef?.click()"
          >
            <Avatar
              :name="team.name"
              :avatar="team.avatar"
              :border-color="team.color"
              size="lg"
            />
          </button>
          <div class="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              :loading="isUploading"
              @click="avatarInputRef?.click()"
            >
              <Icon name="lucide:upload" />
              {{ $t('labels.teams.settings.uploadAvatar') }}
            </Button>
            <Button
              v-if="team.avatar"
              type="button"
              variant="ghost"
              size="sm"
              :loading="deleteAvatarMutation.isPending.value"
              @click="handleRemoveAvatar"
            >
              <Icon name="lucide:trash-2" />
              {{ $t('labels.teams.settings.removeAvatar') }}
            </Button>
          </div>
          <input
            ref="avatarInputRef"
            type="file"
            accept="image/*"
            class="hidden"
            @change="onAvatarInputChange"
          />
        </div>
      </FormField>

      <IconNameField
        v-model="formData"
        name="team-name"
        :label="$t('labels.teams.fields.name')"
        :placeholder="$t('labels.teams.fields.namePlaceholder')"
      />

      <TextField
        v-model="formData.description"
        name="team-description"
        :label="$t('labels.teams.fields.description')"
        :placeholder="$t('labels.teams.fields.descriptionPlaceholder')"
        :rows="3"
      />

      <SelectField
        v-model="formData.type"
        name="team-type"
        :label="$t('labels.teams.fields.type')"
        :placeholder="$t('labels.teams.fields.typePlaceholder')"
        :options="teamTypeOptions"
      />

      <TeamSelectField
        v-model="formData.parent_id"
        name="team-parent"
        :teams="parentTeams"
        :no-team-option="noParentOption"
        :label="$t('labels.teams.fields.parent')"
        :placeholder="$t('labels.teams.fields.parentPlaceholder')"
      />
    </CardContent>
    <CardFooter>
      <Button
        variant="primary"
        :loading="updateMutation.isPending.value"
        :disabled="!formData.name?.trim()"
        @click="handleSave"
      >
        {{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
