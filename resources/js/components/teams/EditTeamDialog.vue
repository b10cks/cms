<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { SelectField, TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import type { TeamHierarchyItem, TeamResource, UpdateTeamPayload } from '~/types/teams'

const props = defineProps<{
  team: TeamResource | null
  hierarchy: TeamHierarchyItem[]
  open: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  submit: [payload: UpdateTeamPayload]
}>()

const { t } = useI18n()

const isSubmitting = ref(false)

const formData = ref<UpdateTeamPayload>({
  name: '',
  description: '',
  type: 'partner',
  parent_id: null,
  icon: null,
  color: null,
  settings: {},
})

const teamTypes = computed(() => [
  { value: 'partner', label: t('labels.teams.types.partner') },
  { value: 'reseller', label: t('labels.teams.types.reseller') },
  { value: 'affiliate', label: t('labels.teams.types.affiliate') },
])

const parentOptions = computed(() => {
  const options: { value: string | null; label: string }[] = [
    { value: null, label: t('labels.teams.noParent') },
  ]

  const flattenHierarchy = (items: TeamHierarchyItem[], prefix = '') => {
    for (const item of items) {
      if (item.id === props.team?.id) continue
      options.push({
        value: item.id,
        label: prefix + item.name,
      })
      if (item.children?.length) {
        flattenHierarchy(item.children, `${prefix}  `)
      }
    }
  }

  flattenHierarchy(props.hierarchy)
  return options
})

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
    <DialogContent class="sm:max-w-[500px]">
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
          v-model:name="formData.name"
          v-model:icon="formData.icon"
          v-model:color="formData.color"
          name="team-identity"
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

        <SelectField
          v-model="formData.type"
          name="edit-type"
          :label="$t('labels.teams.fields.type')"
          :placeholder="$t('labels.teams.fields.typePlaceholder')"
          :options="teamTypes"
        />

        <SelectField
          v-model="formData.parent_id"
          name="edit-parent"
          :label="$t('labels.teams.fields.parent')"
          :placeholder="$t('labels.teams.fields.parentPlaceholder')"
          :options="parentOptions"
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
            :disabled="isSubmitting || !formData.name?.trim()"
          >
            <Icon
              v-if="isSubmitting"
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{ $t('labels.teams.saveButton') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
