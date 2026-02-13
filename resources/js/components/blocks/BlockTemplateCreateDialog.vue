<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import { ScrollArea } from '~/components/ui/scroll-area'

import IconNameField from '~/components/ui/IconNameField.vue'

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
  previewFile: File | null
}>({
  name: '',
  icon: 'block',
  color: '',
  description: '',
  previewFile: null,
})

const fileInputRef = ref<HTMLInputElement | null>(null)

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files && target.files[0]) {
    template.value.previewFile = target.files[0]
  }
}

const handleRemoveFile = () => {
  template.value.previewFile = null
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

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

          <div class="grid gap-2">
            <label class="text-sm font-medium">
              {{ $t('labels.blockTemplates.fields.previewFile') }}
            </label>
            <div class="flex items-center gap-2">
              <input
                ref="fileInputRef"
                type="file"
                accept="image/*"
                class="hidden"
                @change="handleFileSelect"
              />
              <Button
                type="button"
                variant="outline"
                @click="fileInputRef?.click()"
              >
                <Icon name="lucide:upload" />
                {{ $t('actions.selectFile') }}
              </Button>
              <span
                v-if="template.previewFile"
                class="text-muted-foreground text-sm"
              >
                {{ template.previewFile.name }}
              </span>
              <Button
                v-if="template.previewFile"
                type="button"
                variant="ghost"
                size="icon"
                @click="handleRemoveFile"
              >
                <Icon name="lucide:x" />
              </Button>
            </div>
            <p class="text-muted-foreground text-xs">
              {{ $t('labels.blockTemplates.fields.previewFileHint') }}
            </p>
          </div>

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
            :disabled="!template.name.trim() || isPending"
          >
            <Icon
              v-if="isPending"
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
