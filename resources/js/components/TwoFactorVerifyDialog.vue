<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { ref } from 'vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField, OtpField } from '~/components/ui/form'

const open = defineModel<boolean>('open')

const props = defineProps<{
  onVerify: (code: string) => Promise<boolean>
  onCancel?: () => void
}>()

const useBackup = ref(false)
const code = ref('')
const isVerifying = ref(false)
const error = ref<string | null>(null)

const handleVerify = async () => {
  error.value = null

  if (code.value.length !== 6 && code.value.length !== 8) {
    error.value = 'Code must be 6 digits (TOTP) or 8 characters (backup code)'
    return
  }

  isVerifying.value = true
  try {
    const success = await props.onVerify(code.value)
    if (success) {
      open.value = false
      code.value = ''
    } else {
      // If onVerify returns false, there might be an error set externally
      // or we show a generic error
      error.value = 'Verification failed. Please try again.'
    }
  } catch (err: any) {
    error.value = err.message || 'Invalid code'
  } finally {
    isVerifying.value = false
  }
}

const handleCancel = () => {
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
    <DialogContent class="sm:max-w-md">
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
          name="code"
          v-model="code"
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
          :disabled="isVerifying || code.length < 6"
          @click="handleVerify"
        >
          <Icon
            v-if="isVerifying"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ $t('labels.twoFactor.verify.button') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
