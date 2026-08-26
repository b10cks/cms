<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeaderCombined,
  DialogTrigger,
} from '~/components/ui/dialog'
import TeamSelectField, { type TeamSelectOption } from '~/components/teams/TeamSelectField.vue'
import TeamTypeField from '~/components/teams/TeamTypeField.vue'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { flattenNestedTeams } from '~/lib/team-hierarchy'
import type { CreateTeamPayload, TeamHierarchyItem } from '~/types/teams'

const props = withDefaults(
  defineProps<{
    hierarchy: TeamHierarchyItem[]
    isRoot?: boolean
  }>(),
  {
    isRoot: false,
  }
)

const emit = defineEmits<{
  submit: [payload: CreateTeamPayload]
}>()

const { t } = useI18n()

const open = ref(false)
const isSubmitting = ref(false)

const createDefaults = (): CreateTeamPayload => ({
  name: '',
  description: '',
  type: null,
  parent_id: null,
  icon: null,
  color: null,
  settings: {},
})

const formData = ref<CreateTeamPayload>(createDefaults())

// The whole tree is shown for context; teams the user may not add children to
// are listed but not selectable. The top-level ("No parent") option is
// reserved for root.
const parentTeams = computed<TeamSelectOption[]>(() =>
  flattenNestedTeams(props.hierarchy).map((team) => ({
    ...team,
    disabled: !team.can_create_child,
  }))
)

const noParentOption = computed(() =>
  props.isRoot ? { label: t('labels.teams.noParent') as string, icon: 'circle-slash' } : null
)

const handleSubmit = async () => {
  if (!formData.value.name.trim()) return

  isSubmitting.value = true
  try {
    emit('submit', { ...formData.value })
    open.value = false
    resetForm()
  } finally {
    isSubmitting.value = false
  }
}

const resetForm = () => {
  formData.value = createDefaults()
}

const handleOpenChange = (value: boolean) => {
  open.value = value
  if (!value) {
    resetForm()
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogTrigger as-child>
      <slot name="trigger">
        <Button variant="primary">
          <Icon name="lucide:plus" />
          {{ $t('labels.teams.create') }}
        </Button>
      </slot>
    </DialogTrigger>
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.teams.createTitle')"
        :description="$t('labels.teams.createDescription')"
      />
      <form
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
          name="description"
          :label="$t('labels.teams.fields.description')"
          :placeholder="$t('labels.teams.fields.descriptionPlaceholder')"
          :rows="3"
        />

        <TeamTypeField
          v-if="isRoot"
          v-model="formData.type"
          name="type"
          editable
        />

        <TeamSelectField
          v-model="formData.parent_id"
          name="parent"
          :teams="parentTeams"
          :no-team-option="noParentOption"
          :label="$t('labels.teams.fields.parent')"
          :placeholder="$t('labels.teams.fields.parentPlaceholder')"
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
            :loading="isSubmitting"
            :disabled="!formData.name.trim()"
          >
            {{ $t('labels.teams.createButton') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
