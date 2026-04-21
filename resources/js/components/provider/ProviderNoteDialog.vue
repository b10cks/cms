<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { Switch } from '~/components/ui/switch'
import { Textarea } from '~/components/ui/textarea'

const props = defineProps<{
  open: boolean
  loading?: boolean
  noteToEdit?: ProviderNote | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  create: [payload: ProviderNotePayload]
  update: [id: string, payload: Partial<ProviderNotePayload>]
}>()

interface ProviderNoteFormState {
  title: string
  icon: string
  url: string
  color: string
  content: string
  is_pinned: boolean
}

const defaultFormState = (): ProviderNoteFormState => ({
  title: '',
  icon: 'dot',
  url: '',
  color: '',
  content: '',
  is_pinned: false,
})

const form = ref<ProviderNoteFormState>(defaultFormState())
const isEdit = computed(() => props.noteToEdit != null)
const { t } = useI18n()

const isValid = computed(() => form.value.title.trim().length > 0)

const resetForm = () => {
  form.value = defaultFormState()
}

const initializeForm = () => {
  if (props.noteToEdit) {
    form.value = {
      title: props.noteToEdit.title,
      icon: props.noteToEdit.icon,
      url: props.noteToEdit.url ?? '',
      color: props.noteToEdit.color ?? '',
      content: props.noteToEdit.content ?? '',
      is_pinned: props.noteToEdit.is_pinned,
    }
    return
  }

  resetForm()
}

const handleSubmit = () => {
  if (!isValid.value) {
    return
  }

  const payload: ProviderNotePayload = {
    title: form.value.title.trim(),
    icon: form.value.icon.trim() || null,
    url: form.value.url.trim() || null,
    color: form.value.color.trim() || null,
    content: form.value.content.trim() || null,
    is_pinned: form.value.is_pinned,
  }

  if (props.noteToEdit) {
    emit('update', props.noteToEdit.id, payload)
    return
  }

  emit('create', payload)
}

const handleOpenChange = (value: boolean) => {
  emit('update:open', value)

  if (!value) {
    resetForm()
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      initializeForm()
    }
  }
)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-xl">
      <DialogHeaderCombined
        :title="
          isEdit
            ? t('labels.provider.notes.dialog.editTitle')
            : t('labels.provider.notes.dialog.createTitle')
        "
        :description="
          isEdit
            ? t('labels.provider.notes.dialog.editDescription')
            : t('labels.provider.notes.dialog.createDescription')
        "
      />

      <div class="space-y-4">
        <IconNameField
          name="provider-note-title"
          :label="t('labels.provider.notes.fields.title')"
          :model-value="{ icon: form.icon, color: form.color, name: form.title }"
          :disabled="loading"
          :placeholder="t('labels.provider.notes.fields.titlePlaceholder')"
          @update:model-value="
            Object.assign(form, {
              icon: ($event as { icon?: string | null }).icon ?? '',
              color: ($event as { color?: string | null }).color ?? '',
              title: ($event as { name?: string | null }).name ?? '',
            })
          "
          @submit="handleSubmit"
          @cancel="handleOpenChange(false)"
        />

        <InputField
          name="provider-note-url"
          :label="t('labels.provider.notes.fields.link')"
          v-model="form.url"
          :placeholder="t('labels.provider.notes.fields.linkPlaceholder')"
          :disabled="loading"
        />

        <div class="space-y-2">
          <div class="text-sm font-medium text-primary">
            {{ t('labels.provider.notes.fields.body') }}
          </div>
          <Textarea
            v-model="form.content"
            :auto-size="220"
            :disabled="loading"
          />
        </div>

        <label
          class="flex items-center justify-between rounded-lg border border-input px-3 py-2 text-sm"
        >
          <span class="font-medium text-primary">{{
            t('labels.provider.notes.fields.pinned')
          }}</span>
          <Switch
            v-model="form.is_pinned"
            :disabled="loading"
          />
        </label>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          :disabled="loading"
          @click="handleOpenChange(false)"
        >
          {{ t('actions.cancel') }}
        </Button>
        <Button
          :disabled="!isValid || loading"
          @click="handleSubmit"
        >
          {{
            loading
              ? t('actions.saving')
              : isEdit
                ? t('actions.saveChanges')
                : t('labels.provider.notes.create')
          }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
