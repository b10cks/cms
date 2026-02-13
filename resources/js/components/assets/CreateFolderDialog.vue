<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { Translation } from 'nuxt-i18n-micro-types/src'
import { ref } from 'vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'

const props = defineProps<{
  spaceId: string
  parentFolderId?: string | null
  open: boolean
}>()

const emit = defineEmits(['update:open'])
const { t } = useI18n()

const { useCreateAssetFolderMutation } = useAssetFolders(props.spaceId)
const { mutate: createFolder } = useCreateAssetFolderMutation()

const folder = ref<UpsertAssetFolderPayload>({
  name: '',
  description: null,
  color: null,
  icon: 'folder',
  parent_id: props.parentFolderId || null,
})

const isLoading = ref(false)
const errorMessage = ref<string | Translation>('')

const updateOpenState = (value: boolean) => {
  emit('update:open', value)
  if (!value) {
    resetForm()
  }
}

const resetForm = () => {
  folder.value = {
    name: '',
    description: null,
    icon: 'folder',
    parent_id: props.parentFolderId || null,
  }
  errorMessage.value = ''
}

const handleSubmit = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    await createFolder(folder.value)
    updateOpenState(false)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : t('errors.assetFolders.create')
  } finally {
    isLoading.value = false
  }
}

watch(
  () => props.parentFolderId,
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
    <DialogContent class="sm:max-w-[500px]">
      <DialogHeaderCombined
        :title="$t('labels.assetFolders.create')"
        :description="$t('labels.assetFolders.createDescription')"
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
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
