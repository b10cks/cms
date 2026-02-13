<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { DateTimeFormField, FormField, InputField, TextField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'

const props = defineProps<{
  open: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  create: [payload: CreateBackupPayload]
}>()

const { $t } = useI18n()

const form = ref<{
  name: string
  description: string
  recipients: string
  password: string
  expires_at: string
}>({
  name: '',
  description: '',
  recipients: '',
  password: '',
  expires_at: '',
})

const isValid = computed(() => {
  return (
    form.value.name.trim().length > 0 &&
    form.value.recipients.trim().length > 0 &&
    form.value.expires_at
  )
})

const handleCreate = () => {
  if (isValid.value) {
    const recipientList = form.value.recipients
      .split(',')
      .map((email) => email.trim())
      .filter((email) => email.length > 0)

    const payload: CreateBackupPayload = {
      name: form.value.name.trim(),
      description: form.value.description?.trim() || undefined,
      to: recipientList,
      password: form.value.password?.trim() || undefined,
      expires_at: new Date(form.value.expires_at).toISOString(),
    }
    emit('create', payload)
    resetForm()
  }
}

const resetForm = () => {
  form.value = {
    name: '',
    description: '',
    recipients: '',
    password: '',
    expires_at: '',
  }
}

const handleOpenChange = (newOpen: boolean) => {
  if (!newOpen) {
    resetForm()
  }
  emit('update:open', newOpen)
}

const defaultExpiresAt = computed(() => {
  const date = new Date()
  date.setDate(date.getDate() + 30)
  return date.toISOString().split('T')[0]
})

const maxExpiresAt = computed(() => {
  const date = new Date()
  date.setDate(date.getDate() + 12)
  return date.toISOString().split('T')[0]
})
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.backups.createTitle')"
        :description="$t('labels.backups.createDescription')"
      />

      <div class="space-y-4">
        <InputField
          name="backup-name"
          :label="$t('labels.backups.fields.name')"
          v-model="form.name"
          :placeholder="$t('labels.backups.fields.namePlaceholder')"
          :disabled="loading"
        />
        <TextField
          name="backup-description"
          :label="$t('labels.backups.fields.description')"
          v-model="form.description"
          :placeholder="$t('labels.backups.fields.descriptionPlaceholder')"
          class="min-h-20"
          :disabled="loading"
        />
        <FormField
          name="backup-recipients"
          :label="$t('labels.backups.fields.recipients')"
          :description="$t('labels.backups.fields.recipientsHint')"
        >
          <Input
            v-model="form.recipients"
            :placeholder="$t('labels.backups.fields.recipientsPlaceholder')"
            :disabled="loading"
          />
        </FormField>
        <FormField
          name="backup-password"
          :label="$t('labels.backups.fields.password')"
          :description="$t('labels.backups.fields.passwordHint')"
        >
          <Input
            v-model="form.password"
            type="password"
            :placeholder="$t('labels.backups.fields.passwordPlaceholder')"
            :disabled="loading"
          />
        </FormField>
        <DateTimeFormField
          name="backup-expires-at"
          v-model="form.expires_at"
          :label="$t('labels.backups.fields.expiresAt')"
          :placeholder="defaultExpiresAt"
          :max="maxExpiresAt"
          :disabled="loading"
        />
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          @click="handleOpenChange(false)"
          :disabled="loading"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          @click="handleCreate"
          :disabled="!isValid || loading"
          :class="{ 'cursor-not-allowed opacity-50': !isValid || loading }"
        >
          <Icon
            v-if="loading"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ loading ? $t('actions.creating') : $t('actions.backups.create') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
