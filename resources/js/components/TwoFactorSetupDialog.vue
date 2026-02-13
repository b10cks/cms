<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { CheckboxField, InputField, OtpField } from '~/components/ui/form'
import BackupCodesDisplay from './BackupCodesDisplay.vue'
import TwoFactorQRCode from './TwoFactorQRCode.vue'

const open = defineModel<boolean>('open')

const { useTwoFactorSetupMutation, useTwoFactorConfirmMutation } = useTwoFactor()
const { mutate: setup, data: setupData, isPending: isSettingUp } = useTwoFactorSetupMutation()
const { mutate: confirm, isPending: isConfirming } = useTwoFactorConfirmMutation()

const step = ref<'initial' | 'scan' | 'verify' | 'backup'>('initial')
const verificationCode = ref('')
const backupCodes = ref<string[]>([])
const error = ref<string | null>(null)
const hasConfirmedBackupCodes = ref(false)

const handleStartSetup = async () => {
  error.value = null
  await setup()
  step.value = 'scan'
}

const handleVerify = async () => {
  error.value = null
  if (verificationCode.value.length !== 6) {
    error.value = 'Code must be 6 digits'
    return
  }

  confirm(
    { code: verificationCode.value },
    {
      onSuccess: (data) => {
        backupCodes.value = data.backup_codes
        hasConfirmedBackupCodes.value = false
        step.value = 'backup'
      },
      onError: (err: Error) => {
        error.value = err.message || 'Invalid code'
      },
    }
  )
}

const handleClose = () => {
  open.value = false
  step.value = 'initial'
  verificationCode.value = ''
  backupCodes.value = []
  error.value = null
  hasConfirmedBackupCodes.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ $t('labels.twoFactor.setup.title') }}</DialogTitle>
        <DialogDescription v-if="step === 'initial'">
          {{ $t('labels.twoFactor.setup.description') }}
        </DialogDescription>
      </DialogHeader>

      <div
        v-if="step === 'initial'"
        class="space-y-4"
      >
        <Alert variant="modern">
          <h4 class="mb-2 font-medium">{{ $t('labels.twoFactor.setup.whatIsTitle') }}</h4>
          <p class="text-muted-foreground text-sm">
            {{ $t('labels.twoFactor.setup.whatIsDescription') }}
          </p>
        </Alert>

        <div>
          <h4 class="mb-2 font-medium">{{ $t('labels.twoFactor.setup.howItWorksTitle') }}</h4>
          <ol class="text-muted-foreground ml-4 list-decimal text-sm">
            <li>{{ $t('labels.twoFactor.setup.step1') }}</li>
            <li>{{ $t('labels.twoFactor.setup.step2') }}</li>
            <li>{{ $t('labels.twoFactor.setup.step3') }}</li>
          </ol>
        </div>

        <div
          v-if="error"
          class="rounded-md border border-destructive bg-destructive/10 p-3 text-sm text-destructive"
        >
          {{ error }}
        </div>
      </div>

      <div
        v-else-if="step === 'scan' && setupData"
        class="space-y-4"
      >
        <div class="flex flex-col items-center space-y-4">
          <div class="rounded-lg border border-border p-4">
            <TwoFactorQRCode
              :value="`otpauth://totp/b10cks:?secret=${setupData.secret}&issuer=b10cks`"
              :size="200"
            />
          </div>
        </div>

        <InputField
          name="secret"
          :label="$t('labels.twoFactor.setup.manualEntry')"
          :model-value="setupData.secret"
          readonly
          input-class="font-mono tracking-widest"
          :actions="['copy']"
        />

        <OtpField
          v-model="verificationCode"
          :maxlength="6"
          name="verificationCode"
          inputmode="numeric"
          pattern="[0-9]*"
          maxlength="6"
          :label="$t('labels.twoFactor.setup.enterCode')"
          :placeholder="$t('labels.twoFactor.setup.codePlaceholder')"
        />

        <div
          v-if="error"
          class="rounded-md border border-destructive bg-destructive/10 p-3 text-sm text-destructive"
        >
          {{ error }}
        </div>
      </div>

      <div
        v-else-if="step === 'backup'"
        class="space-y-4"
      >
        <Alert
          v-if="backupCodes.length === 0"
          color="warning"
          icon="lucide:alert-triangle"
        >
          <h4 class="font-medium">
            {{ $t('labels.twoFactor.backupCodes.saveTitle') }}
          </h4>
          <p>
            {{ $t('labels.twoFactor.backupCodes.saveDescription') }}
          </p>
        </Alert>

        <BackupCodesDisplay
          :codes="backupCodes"
          show-full-codes
        />

        <CheckboxField
          name="confirm-backup-codes"
          v-model="hasConfirmedBackupCodes"
          :label="$t('labels.twoFactor.backupCodes.confirmCheckbox')"
        />
      </div>

      <DialogFooter>
        <Button
          v-if="step === 'initial'"
          variant="outline"
          @click="handleClose"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          v-if="step === 'initial'"
          variant="primary"
          :disabled="isSettingUp"
          @click="handleStartSetup"
        >
          <Icon
            v-if="isSettingUp"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ $t('labels.twoFactor.setup.start') }}
        </Button>

        <Button
          v-if="step === 'scan'"
          variant="outline"
          @click="step = 'initial'"
        >
          {{ $t('actions.back') }}
        </Button>
        <Button
          v-if="step === 'scan'"
          variant="primary"
          :disabled="isConfirming || verificationCode.length !== 6"
          @click="handleVerify"
        >
          <Icon
            v-if="isConfirming"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ $t('labels.twoFactor.setup.verify') }}
        </Button>

        <Button
          v-if="step === 'backup'"
          variant="primary"
          :disabled="!hasConfirmedBackupCodes"
          @click="handleClose"
        >
          {{ $t('labels.twoFactor.setup.done') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
