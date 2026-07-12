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
import { SelectField, TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { useTeamTypes } from '~/composables/useTeamTypes'
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
const { teamTypeOptions } = useTeamTypes()

const open = ref(false)
const isSubmitting = ref(false)

const createDefaults = (): CreateTeamPayload => ({
  name: '',
  description: '',
  type: 'partner',
  parent_id: null,
  icon: null,
  color: null,
  settings: {},
})

const formData = ref<CreateTeamPayload>(createDefaults())

// Only teams the user may add children to are offered as parents. The
// top-level ("No parent") option is reserved for root.
const parentOptions = computed(() => {
  const options: { value: string | null; label: string }[] = []

  if (props.isRoot) {
    options.push({ value: null, label: t('labels.teams.noParent') })
  }

  const flattenHierarchy = (items: TeamHierarchyItem[], prefix = '') => {
    for (const item of items) {
      if (item.can_create_child) {
        options.push({ value: item.id, label: prefix + item.name })
      }
      if (item.children?.length) {
        flattenHierarchy(item.children, `${prefix}  `)
      }
    }
  }

  flattenHierarchy(props.hierarchy)
  return options
})

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

        <SelectField
          v-model="formData.type"
          name="type"
          :label="$t('labels.teams.fields.type')"
          :placeholder="$t('labels.teams.fields.typePlaceholder')"
          :options="teamTypeOptions"
        />

        <SelectField
          v-if="parentOptions.length > 0"
          v-model="formData.parent_id"
          name="parent"
          :label="$t('labels.teams.fields.parent')"
          :placeholder="$t('labels.teams.fields.parentPlaceholder')"
          :options="parentOptions"
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
