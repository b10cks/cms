<script setup lang="ts">
import dayjs from 'dayjs'

import ImagePickerField from '~/components/assets/ImagePickerField.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Sheet, SheetContent, SheetHeaderCombined } from '~/components/ui/sheet'
import { Spinner } from '~/components/ui/spinner'

const open = defineModel<boolean>('open')
const { $t } = useI18n()

const props = defineProps<{
  spaceId: string
  blockId: string
}>()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageBlockTemplates = computed(() => access.hasAbility('block_templates.manage'))

const { useBlockTemplatesQuery, useUpdateBlockTemplateMutation, useDeleteBlockTemplateMutation } =
  useBlockTemplates(props.spaceId, props.blockId)
const { data: templates, isLoading } = useBlockTemplatesQuery()
const { mutate: updateTemplate, isPending: isUpdating } = useUpdateBlockTemplateMutation()
const { mutate: deleteTemplate, isPending: isDeleting } = useDeleteBlockTemplateMutation()

const { alert } = useAlertDialog()

const editingTemplate = ref<BlockTemplate | null>(null)
const editForm = ref<{
  name: string
  icon: string
  color: string
  description: string
  previewFile: string | null
}>({
  name: '',
  icon: 'block',
  color: '',
  description: '',
  previewFile: null,
})

const startEditing = (template: BlockTemplate) => {
  editingTemplate.value = template
  editForm.value = {
    name: template.name,
    icon: template.icon || 'block',
    color: template.color || '',
    description: template.description || '',
    previewFile: template.preview_file || null,
  }
}

const cancelEditing = () => {
  editingTemplate.value = null
}

const handleUpdate = async () => {
  if (!editingTemplate.value) return

  await updateTemplate({
    id: editingTemplate.value.id,
    payload: {
      name: editForm.value.name,
      icon: editForm.value.icon,
      color: editForm.value.color,
      description: editForm.value.description,
      preview_file: editForm.value.previewFile,
    },
  })

  editingTemplate.value = null
}

const handleDelete = async (template: BlockTemplate) => {
  await alert.confirm($t('labels.blockTemplates.delete.message', { name: template.name }), {
    title: $t('labels.blockTemplates.delete.title'),
    confirmLabel: $t('labels.blockTemplates.delete.confirmLabel'),
    variant: 'destructive',
    onConfirm: () => {
      deleteTemplate(template.id)
    },
  })
}

const formatDate = (date: string) => {
  return dayjs(date).format('MMM D, YYYY HH:mm')
}

watch(open, (isOpen) => {
  if (!isOpen) {
    editingTemplate.value = null
  }
})
</script>

<template>
  <Sheet
    :open="open"
    @update:open="open = $event"
  >
    <SheetContent class="sm:max-w-2xl">
      <SheetHeaderCombined :title="$t('labels.blockTemplates.manageTitle')" />
      <div
        v-if="isLoading"
        class="flex h-32 items-center justify-center"
      >
        <Spinner class="mr-2 size-6" />
        <span>{{ $t('labels.blockTemplates.loading') }}</span>
      </div>

      <div
        v-else-if="!templates?.length"
        class="text-muted-foreground py-8 text-center"
      >
        <Icon
          name="lucide:file-x"
          class="mx-auto mb-2 h-12 w-12 opacity-50"
        />
        <p>{{ $t('labels.blockTemplates.noTemplates') }}</p>
      </div>

      <ScrollArea v-else>
        <div class="grid gap-4">
          <div
            v-for="template in templates"
            :key="template.id"
            class="rounded-lg border p-4"
          >
            <div v-if="canManageBlockTemplates && editingTemplate?.id === template.id">
              <div class="grid gap-4">
                <IconNameField
                  v-model="editForm"
                  :label="$t('labels.blockTemplates.fields.name')"
                  name="name"
                />

                <TextField
                  v-model="editForm.description"
                  :label="$t('labels.blockTemplates.fields.description')"
                  name="description"
                />

                <ImagePickerField
                  v-model="editForm.previewFile"
                  :space-id="spaceId"
                  :label="$t('labels.blockTemplates.fields.previewFile')"
                />

                <div class="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="cancelEditing"
                  >
                    {{ $t('actions.cancel') }}
                  </Button>
                  <Button
                    type="button"
                    variant="primary"
                    size="sm"
                    :loading="isUpdating"
                    :disabled="!editForm.name.trim()"
                    @click="handleUpdate"
                  >
                    {{ $t('actions.save') }}
                  </Button>
                </div>
              </div>
            </div>

            <div v-else>
              <div class="flex items-start justify-between">
                <div class="flex items-center gap-3">
                  <div
                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-muted"
                    :style="{
                      backgroundColor: template.color ? `${template.color}20` : undefined,
                    }"
                  >
                    <Icon
                      v-if="template.icon"
                      :name="`lucide:${template.icon}`"
                      :style="{ color: template.color }"
                      class="h-5 w-5"
                    />
                    <Icon
                      v-else
                      name="lucide:block"
                      class="h-5 w-5"
                    />
                  </div>
                  <div>
                    <h4 class="font-medium">{{ template.name }}</h4>
                    <p
                      v-if="template.description"
                      class="text-muted-foreground text-sm"
                    >
                      {{ template.description }}
                    </p>
                  </div>
                </div>

                <div class="flex gap-1">
                  <Button
                    v-if="canManageBlockTemplates"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-6! w-6!"
                    :aria-label="$t('actions.edit')"
                    @click="startEditing(template)"
                  >
                    <Icon name="lucide:pencil" />
                  </Button>
                  <Button
                    v-if="canManageBlockTemplates"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-6! w-6!"
                    :disabled="isDeleting"
                    :aria-label="$t('actions.delete')"
                    @click="handleDelete(template)"
                  >
                    <Icon name="lucide:trash-2" />
                  </Button>
                </div>
              </div>

              <div
                v-if="template.preview_file"
                class="mt-3"
              >
                <NuxtImg
                  :src="template.preview_file"
                  :alt="template.name"
                  :width="576"
                  :height="96"
                  :modifiers="{ crop: 'fit' }"
                  class="h-24 w-full rounded-md bg-surface/50 object-contain"
                />
              </div>

              <div class="text-muted-foreground mt-3 flex items-center gap-2 text-xs">
                <Avatar
                  v-if="template.created_by"
                  :name="template.created_by.name"
                  :avatar="template.created_by.avatar"
                  size="sm"
                />
                <span v-if="template.created_by">{{ template.created_by.name }}</span>
                <span v-else>{{ $t('labels.system') }}</span>
                <span>•</span>
                <span>{{ formatDate(template.created_at) }}</span>
              </div>
            </div>
          </div>
        </div>
      </ScrollArea>
    </SheetContent>
  </Sheet>
</template>
