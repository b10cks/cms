<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { DateTimeFormField, InputField, TextField } from '~/components/ui/form'
import type { CreateReleaseRequest, Release } from '~/types/releases'

const props = defineProps<{
  open: boolean
  loading?: boolean
  releaseToEdit?: Release | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  create: [payload: CreateReleaseRequest]
  update: [id: string, payload: CreateReleaseRequest]
}>()

const form = ref<CreateReleaseRequest>({
  name: '',
  description: '',
  publish_at: null,
})

const isEdit = computed(() => !!props.releaseToEdit)

const isValid = computed(() => {
  return form.value.name.trim().length > 0
})

const dialogTitle = computed(() => {
  return isEdit.value ? 'Edit Release' : 'Create Release'
})

const dialogDescription = computed(() => {
  return isEdit.value
    ? 'Update the release details'
    : 'Create a new release and manage your content versions'
})

const buttonLabel = computed(() => {
  if (props.loading) {
    return isEdit.value ? 'Updating...' : 'Creating...'
  }
  return isEdit.value ? 'Update Release' : 'Create Release'
})

const handleSubmit = () => {
  if (isValid.value) {
    const payload: CreateReleaseRequest = {
      name: form.value.name.trim(),
      description: form.value.description?.trim() || undefined,
      publish_at: form.value.publish_at ? new Date(form.value.publish_at).toISOString() : null,
    }

    if (isEdit.value && props.releaseToEdit) {
      emit('update', props.releaseToEdit.id, payload)
    } else {
      emit('create', payload)
      resetForm()
    }
  }
}

const resetForm = () => {
  form.value = {
    name: '',
    description: '',
    publish_at: null,
  }
}

const initializeForm = () => {
  if (props.releaseToEdit) {
    form.value = {
      name: props.releaseToEdit.name,
      description: props.releaseToEdit.description || '',
      publish_at: props.releaseToEdit.publish_at
        ? props.releaseToEdit.publish_at.split('T')[0]
        : null,
    }
  } else {
    resetForm()
  }
}

const handleOpenChange = (newOpen: boolean) => {
  if (!newOpen) {
    resetForm()
  }
  emit('update:open', newOpen)
}

watch(
  () => props.open,
  (newOpen) => {
    if (newOpen) {
      initializeForm()
    } else if (!isEdit.value) {
      resetForm()
    }
  }
)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-md">
      <DialogHeaderCombined
        :title="dialogTitle"
        :description="dialogDescription"
      />

      <div class="space-y-4">
        <InputField
          name="release-name"
          label="Name"
          v-model="form.name"
          placeholder="e.g., Version 1.0"
          :disabled="props.loading"
        />
        <TextField
          name="release-description"
          label="Description"
          v-model="form.description"
          placeholder="Describe this release..."
          class="min-h-20"
          :disabled="props.loading"
        />
        <DateTimeFormField
          name="release-publish-at"
          v-model="form.publish_at"
          label="Publish At"
          :disabled="props.loading"
        />
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          @click="handleOpenChange(false)"
          :disabled="props.loading"
        >
          Cancel
        </Button>
        <Button
          @click="handleSubmit"
          :disabled="!isValid || props.loading"
          :class="{ 'cursor-not-allowed opacity-50': !isValid || props.loading }"
        >
          {{ buttonLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
