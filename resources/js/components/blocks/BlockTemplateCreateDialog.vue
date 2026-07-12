<script setup lang="ts">
import ImagePickerField from '~/components/assets/ImagePickerField.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { ScrollArea } from '~/components/ui/scroll-area'

const open = defineModel<boolean>('open')
const { $t } = useI18n()

const props = defineProps<{
  spaceId: string
  blockId: string
  blockName: string
  content: Record<string, any>
}>()

const { useCreateBlockTemplateMutation } = useBlockTemplates(
  () => props.spaceId,
  () => props.blockId
)
const { mutate: createTemplate, isPending } = useCreateBlockTemplateMutation()

const template = ref<{
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

const handleSubmit = async () => {
  if (!template.value.name.trim()) return

  await createTemplate({
    name: template.value.name,
    icon: template.value.icon,
    color: template.value.color,
    description: template.value.description,
    content: props.content,
    preview_file: template.value.previewFile,
  })

  open.value = false
  resetForm()
}

const resetForm = () => {
  template.value = {
    name: '',
    icon: 'block',
    color: '',
    description: '',
    previewFile: null,
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    resetForm()
  }
})
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent class="sm:max-w-lg">
      <form
        class="grid gap-4"
        @submit.prevent="handleSubmit"
      >
        <DialogHeaderCombined :title="$t('labels.blockTemplates.createTitle')" />

        <div class="grid gap-6">
          <IconNameField
            v-model="template"
            :label="$t('labels.blockTemplates.fields.name')"
            name="name"
          />

          <TextField
            v-model="template.description"
            :label="$t('labels.blockTemplates.fields.description')"
            name="description"
            :placeholder="$t('labels.blockTemplates.fields.descriptionPlaceholder')"
          />

          <ImagePickerField
            v-model="template.previewFile"
            :space-id="spaceId"
            :label="$t('labels.blockTemplates.fields.previewFile')"
            :description="$t('labels.blockTemplates.fields.previewFileHint')"
          />

          <ScrollArea class="h-32 rounded-lg bg-surface p-2">
            <div class="font-mono text-xs">
              <pre>{{ content }}</pre>
            </div>
          </ScrollArea>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            type="submit"
            :loading="isPending"
            :disabled="!template.name.trim()"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
