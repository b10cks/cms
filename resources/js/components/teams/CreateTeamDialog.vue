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
import type { CreateTeamPayload, TeamHierarchyItem } from '~/types/teams'

const props = defineProps<{
  hierarchy: TeamHierarchyItem[]
}>()

const emit = defineEmits<{
  submit: [payload: CreateTeamPayload]
}>()

const { t } = useI18n()

const open = ref(false)
const isSubmitting = ref(false)

const formData = ref<CreateTeamPayload>({
  name: '',
  description: '',
  type: 'partner',
  parent_id: null,
  icon: null,
  color: null,
  settings: {},
})

const parentOptions = computed(() => {
  const options: { value: string | null; label: string }[] = [
    { value: null, label: t('labels.teams.noParent') },
  ]

  const flattenHierarchy = (items: TeamHierarchyItem[], prefix = '') => {
    for (const item of items) {
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
  formData.value = {
    name: '',
    description: '',
    type: 'partner',
    parent_id: null,
    icon: null,
    color: null,
    settings: {},
  }
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
    <DialogContent class="sm:max-w-[500px]">
      <DialogHeaderCombined
        :title="$t('labels.teams.createTitle')"
        :description="$t('labels.teams.createDescription')"
      />
      <form
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
          name="description"
          :label="$t('labels.teams.fields.description')"
          :placeholder="$t('labels.teams.fields.descriptionPlaceholder')"
          :rows="3"
        />

        <SelectField
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
            :disabled="isSubmitting || !formData.name.trim()"
          >
            <Icon
              v-if="isSubmitting"
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{ $t('labels.teams.createButton') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
