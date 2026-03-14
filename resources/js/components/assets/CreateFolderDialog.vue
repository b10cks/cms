<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import type { AssetFolderResource, UpsertAssetFolderPayload } from '~/types/assets'

const props = withDefaults(
  defineProps<{
    spaceId: string
    parentFolderId?: string | null
    folder?: AssetFolderResource | null
    open: boolean
  }>(),
  {
    parentFolderId: null,
    folder: null,
  }
)

const emit = defineEmits(['update:open'])
const { t } = useI18n()

const { useCreateAssetFolderMutation, useUpdateAssetFolderMutation } = useAssetFolders(
  props.spaceId
)
const { mutateAsync: createFolder } = useCreateAssetFolderMutation()
const { mutateAsync: updateFolder } = useUpdateAssetFolderMutation()

const isEditMode = computed(() => !!props.folder)
const title = computed(() => {
  return isEditMode.value ? t('labels.assetFolders.edit') : t('labels.assetFolders.create')
})
const description = computed(() => {
  return isEditMode.value
    ? t('labels.assetFolders.editDescription')
    : t('labels.assetFolders.createDescription')
})
const submitLabel = computed(() => {
  return isEditMode.value ? t('actions.saveChanges') : t('actions.create')
})

const folder = ref<UpsertAssetFolderPayload>({
  name: '',
  description: null,
  color: null,
  icon: 'folder',
  parent_id: props.parentFolderId || null,
})

const isLoading = ref(false)
const errorMessage = ref<string>('')

const updateOpenState = (value: boolean) => {
  emit('update:open', value)
  if (!value) {
    resetForm()
  }
}

const resetForm = () => {
  folder.value = props.folder
    ? {
        name: props.folder.name,
        description: props.folder.description,
        color: props.folder.color,
        icon: props.folder.icon || 'folder',
        parent_id: props.folder.parent_id,
      }
    : {
        name: '',
        description: null,
        color: null,
        icon: 'folder',
        parent_id: props.parentFolderId || null,
      }
  errorMessage.value = ''
}

const handleSubmit = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    if (isEditMode.value && props.folder) {
      await updateFolder({
        id: props.folder.id,
        payload: folder.value,
      })
    } else {
      await createFolder(folder.value)
    }

    updateOpenState(false)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : t('errors.assetFolders.create')
  } finally {
    isLoading.value = false
  }
}

watch(
  [() => props.parentFolderId, () => props.folder],
  () => {
    resetForm()
  },
  { immediate: true }
)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="updateOpenState"
  >
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="title"
        :description="description"
      />

      <form @submit.prevent="handleSubmit">
        <div class="grid gap-4 py-4">
          <IconNameField
            v-model="folder"
            :label="$t('labels.assetFolders.fields.name')"
            name="name"
          />
          <TextField
            v-model="folder.description"
            name="description"
            :label="$t('labels.assetFolders.fields.description')"
            :placeholder="$t('labels.assetFolders.fields.descriptionPlaceholder')"
          />
        </div>
        <div>
          <div
            v-if="errorMessage"
            id="name-error"
            class="col-start-2 col-end-3 mt-1 text-sm text-red-500"
          >
            {{ errorMessage }}
          </div>
        </div>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="updateOpenState(false)"
          >
            {{ $t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :disabled="isLoading"
          >
            <Icon
              v-if="isLoading"
              name="lucide:loader"
              class="animate-spin"
            />
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
