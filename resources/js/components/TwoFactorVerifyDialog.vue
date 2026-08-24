<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField, OtpField } from '~/components/ui/form'

const { t } = useI18n()

const open = defineModel<boolean>('open')

const props = defineProps<{
  onVerify: (code: string) => Promise<boolean>
  onCancel?: () => void
}>()

const useBackup = ref(false)
const code = ref('')
const isVerifying = ref(false)
const error = ref<string | null>(null)
const otpField = ref<{ focus: () => void; blur: () => void } | null>(null)

/**
 * Password-manager inline menus (Bitwarden, 1Password) anchor to the focused field and
 * only close on focusout. Verifying navigates away, so the field unmounts while still
 * focused and the menu is left floating over the dashboard. Blur before we submit.
 */
const releaseFocus = () => {
  otpField.value?.blur()

  if (document.activeElement instanceof HTMLElement) {
    document.activeElement.blur()
  }
}

const handleVerify = async () => {
  if (isVerifying.value) {
    return
  }

  error.value = null

  if (code.value.length !== 6 && code.value.length !== 8) {
    error.value = t('labels.twoFactor.verify.invalidLength')
    return
  }

  releaseFocus()
  isVerifying.value = true

  try {
    const success = await props.onVerify(code.value)
    if (success) {
      open.value = false
      code.value = ''
      return
    }

    // onVerify may have set a more specific error on the caller side.
    error.value = t('labels.twoFactor.verify.failed')
  } catch (err: any) {
    error.value = err.message || t('labels.twoFactor.verify.failed')
  } finally {
    isVerifying.value = false
  }

  // Rejected: wipe the boxes and hand focus back so the user can just retype.
  if (error.value) {
    code.value = ''
    await nextTick()
    otpField.value?.focus()
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    code.value = ''
    error.value = null
  }
})

/** Land on the code boxes instead of the dialog's first button. */
const handleOpenAutoFocus = (event: Event) => {
  if (useBackup.value) {
    return
  }

  event.preventDefault()
  otpField.value?.focus()
}

const handleCancel = () => {
  releaseFocus()
  open.value = false
  code.value = ''
  error.value = null
  props.onCancel?.()
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent
      class="sm:max-w-md"
      @open-auto-focus="handleOpenAutoFocus"
    >
      <DialogHeaderCombined
        :title="$t('labels.twoFactor.verify.title')"
        :description="$t('labels.twoFactor.verify.description')"
      />

      <div class="space-y-4">
        <div class="flex justify-center">
          <div class="rounded-full bg-success-background/20 p-4">
            <Icon
              name="lucide:shield-check"
              class="h-8 w-8 text-success"
            />
          </div>
        </div>

        <InputField
          name="code"
          v-if="useBackup"
          v-model="code"
          type="text"
          inputmode="numeric"
          pattern="[0-9]*"
          maxlength="8"
          :label="$t('labels.twoFactor.verify.backupLabel')"
          :placeholder="$t('labels.twoFactor.verify.backupPlaceholder')"
          class="text-center text-lg tracking-widest"
        />

        <OtpField
          v-else
          ref="otpField"
          name="code"
          v-model="code"
          @complete="handleVerify"
          :maxlength="6"
          :label="$t('labels.twoFactor.verify.codeLabel')"
          :placeholder="$t('labels.twoFactor.verify.codePlaceholder')"
        />

        <Alert
          v-if="error"
          color="destructive"
          variant="modern"
        >
          {{ error }}
        </Alert>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          @click="handleCancel"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          variant="primary"
          :loading="isVerifying"
          :disabled="code.length < 6"
          @click="handleVerify"
        >
          {{ $t('labels.twoFactor.verify.button') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
