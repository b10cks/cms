<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import TeamSelectField, { type TeamSelectOption } from '~/components/teams/TeamSelectField.vue'
import TeamTypeField from '~/components/teams/TeamTypeField.vue'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { flattenNestedTeams } from '~/lib/team-hierarchy'
import type { TeamHierarchyItem, TeamResource, UpdateTeamPayload } from '~/types/teams'

const props = withDefaults(
  defineProps<{
    team: TeamResource | null
    hierarchy: TeamHierarchyItem[]
    open: boolean
    isRoot?: boolean
  }>(),
  {
    isRoot: false,
  }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  submit: [payload: UpdateTeamPayload]
}>()

const { t } = useI18n()

const isSubmitting = ref(false)

const formData = ref<UpdateTeamPayload>({
  name: '',
  description: '',
  type: null,
  parent_id: null,
  icon: null,
  color: null,
  settings: {},
})

// Valid re-parent destinations: the tree minus the team itself and its
// descendants, since a team cannot move under its own child. Teams the user
// may not add children to are shown for context but not selectable.
// "No parent" (top level) is reserved for root.
const parentTeams = computed<TeamSelectOption[]>(() =>
  flattenNestedTeams(props.hierarchy, props.team?.id).map((team) => ({
    ...team,
    disabled: !team.can_create_child,
  }))
)

const noParentOption = computed(() =>
  props.isRoot ? { label: t('labels.teams.noParent') as string, icon: 'circle-slash' } : null
)

watch(
  () => props.team,
  (newTeam) => {
    if (newTeam) {
      formData.value = {
        name: newTeam.name,
        description: newTeam.description || '',
        type: newTeam.type,
        parent_id: newTeam.parent_id,
        icon: newTeam.icon,
        color: newTeam.color,
        settings: newTeam.settings,
      }
    }
  },
  { immediate: true }
)

const handleSubmit = async () => {
  if (!formData.value.name?.trim()) return

  isSubmitting.value = true
  try {
    const payload: UpdateTeamPayload = {}
    if (formData.value.name !== props.team?.name) payload.name = formData.value.name
    if (formData.value.description !== props.team?.description)
      payload.description = formData.value.description
    if (formData.value.type !== props.team?.type) payload.type = formData.value.type
    if (formData.value.parent_id !== props.team?.parent_id)
      payload.parent_id = formData.value.parent_id
    if (formData.value.color !== props.team?.color) payload.color = formData.value.color
    if (formData.value.icon !== props.team?.icon) payload.icon = formData.value.icon
    if (JSON.stringify(formData.value.settings) !== JSON.stringify(props.team?.settings)) {
      payload.settings = formData.value.settings
    }

    emit('submit', payload)
    emit('update:open', false)
  } finally {
    isSubmitting.value = false
  }
}

const handleOpenChange = (value: boolean) => {
  emit('update:open', value)
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.teams.editTitle')"
        :description="$t('labels.teams.editDescription')"
      />
      <form
        v-if="team"
        class="space-y-4"
        @submit.prevent="handleSubmit"
      >
        <IconNameField
          v-model="formData"
          :label="$t('labels.teams.fields.name')"
          :placeholder="$t('labels.teams.fields.namePlaceholder')"
        />

        <TextField
          v-model="formData.description"
          name="edit-description"
          :label="$t('labels.teams.fields.description')"
          :placeholder="$t('labels.teams.fields.descriptionPlaceholder')"
          :rows="3"
        />

        <TeamTypeField
          v-model="formData.type"
          name="edit-type"
          :editable="isRoot"
        />

        <TeamSelectField
          v-model="formData.parent_id"
          name="edit-parent"
          :teams="parentTeams"
          :no-team-option="noParentOption"
          :label="$t('labels.teams.fields.parent')"
          :placeholder="$t('labels.teams.fields.parentPlaceholder')"
        />

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="emit('update:open', false)"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            :loading="isSubmitting"
            :disabled="!formData.name?.trim()"
          >
            {{ $t('labels.teams.saveButton') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
