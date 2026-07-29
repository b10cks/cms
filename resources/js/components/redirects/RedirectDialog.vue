<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField, SelectField } from '~/components/ui/form'

const props = defineProps<{
  open: boolean
  loading?: boolean
  redirectToEdit?: RedirectResource | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  create: [payload: CreateRedirectPayload]
  update: [id: string, payload: UpdateRedirectPayload]
}>()

const { t } = useI18n()

const form = ref<CreateRedirectPayload>({
  source: '',
  target: '',
  status_code: 301,
})

const isEdit = computed(() => props.redirectToEdit != null)

const statusCodeOptions = computed(() =>
  [301, 302, 303, 307, 308].map((code) => ({
    value: code,
    label: `${code} - ${t(`labels.redirects.statusCodes.${code}`)}`,
  }))
)

const isValid = computed(() => {
  return form.value.source.trim().length > 0 && form.value.target.trim().length > 0
})

const handleSubmit = () => {
  if (!isValid.value) {
    return
  }

  const payload: CreateRedirectPayload = {
    source: form.value.source.trim(),
    target: form.value.target.trim(),
    status_code: form.value.status_code,
  }

  if (props.redirectToEdit) {
    emit('update', props.redirectToEdit.id, payload)
    return
  }

  emit('create', payload)
}

const resetForm = () => {
  form.value = {
    source: '',
    target: '',
    status_code: 301,
  }
}

const initializeForm = () => {
  if (props.redirectToEdit) {
    form.value = {
      source: props.redirectToEdit.source,
      target: props.redirectToEdit.target,
      status_code: props.redirectToEdit.status_code,
    }
    return
  }

  resetForm()
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
    <DialogContent class="max-w-lg">
      <DialogHeaderCombined
        :title="isEdit ? $t('labels.redirects.editRedirect') : $t('labels.redirects.createRedirect')"
        :description="
          isEdit
            ? $t('labels.redirects.editDescription')
            : $t('labels.redirects.createDescription')
        "
      />

      <div class="space-y-4">
        <InputField
          name="redirect-source"
          :label="$t('labels.redirects.fields.source')"
          :description="$t('labels.redirects.fields.fromDescription')"
          v-model="form.source"
          :placeholder="$t('labels.redirects.placeholders.source')"
          :disabled="loading"
        />

        <InputField
          name="redirect-target"
          :label="$t('labels.redirects.columns.target')"
          :description="$t('labels.redirects.fields.toDescription')"
          v-model="form.target"
          :placeholder="$t('labels.redirects.placeholders.target')"
          :disabled="loading"
        />

        <SelectField
          name="redirect-status-code"
          :label="$t('labels.redirects.fields.statusCode')"
          :description="$t('labels.redirects.fields.statusCodeDescription')"
          v-model="form.status_code"
          :options="statusCodeOptions"
          :disabled="loading"
        />
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          :disabled="loading"
          @click="handleOpenChange(false)"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button
          :disabled="!isValid || loading"
          @click="handleSubmit"
        >
          {{ loading ? $t('actions.saving') : $t('actions.redirects.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
