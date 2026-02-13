<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import ApiTokenSettings from '~/components/account/ApiTokenSettings.vue'
import BackupCodesDisplay from '~/components/BackupCodesDisplay.vue'
import TwoFactorSetupDialog from '~/components/TwoFactorSetupDialog.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardFooter, CardHeaderCombined } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import { useAlertDialog } from '~/composables/useAlertDialog'

const { useChangePasswordMutation } = useUser()
const { mutate: changePassword, isPending: isChanging } = useChangePasswordMutation()
const { useTwoFactorStatusQuery, useTwoFactorDisableMutation, useRegenerateBackupCodesMutation } =
  useTwoFactor()
const { alert } = useAlertDialog()
const { t } = useI18n()

useSeoMeta({
  title: computed(() => t('labels.account.security.title')),
})

const { data: twoFactorStatus } = useTwoFactorStatusQuery()
const { mutate: disable2FA, isPending: isDisabling } = useTwoFactorDisableMutation()
const {
  mutate: regenerateBackupCodes,
  data: newBackupCodes,
  isPending: isRegenerating,
} = useRegenerateBackupCodesMutation()

const oldPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordError = ref<string | null>(null)
const setupDialogOpen = ref(false)
const disablePassword = ref('')
const showDisableDialog = ref(false)
const showBackupCodes = ref(false)

const handleChangePassword = async () => {
  passwordError.value = null

  if (newPassword.value !== confirmPassword.value) {
    passwordError.value = 'Passwords do not match'
    return
  }

  if (newPassword.value.length < 8) {
    passwordError.value = 'Password must be at least 8 characters'
    return
  }

  await changePassword({
    old_password: oldPassword.value,
    new_password: newPassword.value,
  })

  // Reset form on success
  oldPassword.value = ''
  newPassword.value = ''
  confirmPassword.value = ''
}

const handleDisable2FA = async () => {
  if (!disablePassword.value) return

  const confirmed = await alert.confirm(
    'Are you sure you want to disable two-factor authentication? This will make your account less secure.',
    {
      title: 'Disable 2FA',
      confirmLabel: 'Disable',
      cancelLabel: 'Cancel',
      variant: 'destructive',
    }
  )

  if (confirmed) {
    disable2FA(
      { password: disablePassword.value },
      {
        onSuccess: () => {
          showDisableDialog.value = false
          disablePassword.value = ''
        },
      }
    )
  }
}

const handleRegenerateBackupCodes = async () => {
  const confirmed = await alert.confirm(
    'Regenerating backup codes will invalidate all existing codes. Make sure to save the new codes.',
    {
      title: 'Regenerate Backup Codes',
      confirmLabel: 'Regenerate',
      cancelLabel: 'Cancel',
    }
  )

  if (confirmed) {
    regenerateBackupCodes()
    showBackupCodes.value = true
  }
}
</script>

<template>
  <div class="content-grid gap-6 pb-6">
    <ContentHeader
      :header="$t('labels.account.security.title')"
      :description="$t('labels.account.security.description')"
    />

    <Card variant="outline">
      <CardHeaderCombined
        :title="$t('labels.account.security.changePassword')"
        :description="$t('labels.account.security.changePasswordDescription')"
      />
      <CardContent class="grid gap-6">
        <InputField
          v-model="oldPassword"
          type="password"
          :label="$t('labels.account.security.oldPassword')"
          :placeholder="$t('labels.account.security.oldPasswordPlaceholder')"
          name="old-password"
          required
        />

        <InputField
          v-model="newPassword"
          type="password"
          :label="$t('labels.account.security.newPassword')"
          :placeholder="$t('labels.account.security.newPasswordPlaceholder')"
          name="new-password"
          required
        />

        <InputField
          v-model="confirmPassword"
          type="password"
          :label="$t('labels.account.security.confirmPassword')"
          :placeholder="$t('labels.account.security.confirmPasswordPlaceholder')"
          name="confirm-password"
          required
          :error="passwordError"
        />
      </CardContent>
      <CardFooter>
        <Button
          variant="primary"
          :disabled="isChanging || !oldPassword || !newPassword || !confirmPassword"
          @click="handleChangePassword"
        >
          <Icon
            v-if="isChanging"
            name="lucide:loader"
            class="animate-spin"
          />
          {{ $t('labels.account.security.changePasswordButton') }}
        </Button>
      </CardFooter>
    </Card>

    <ApiTokenSettings />

    <Card variant="outline">
      <CardHeaderCombined
        :title="$t('labels.twoFactor.title')"
        :description="$t('labels.twoFactor.description')"
      />
      <CardContent class="space-y-6">
        <div
          v-if="twoFactorStatus?.enabled"
          class="space-y-4"
        >
          <Alert
            color="success"
            variant="modern"
            icon="lucide:shield-check"
          >
            <b>{{ $t('labels.twoFactor.enabled') }}</b>
          </Alert>

          <div class="flex flex-wrap gap-2">
            <Button
              variant="outline"
              @click="handleRegenerateBackupCodes"
            >
              <Icon name="lucide:refresh-cw" />
              {{ $t('labels.twoFactor.regenerateCodes') }}
            </Button>
            <Button
              variant="destructive"
              @click="showDisableDialog = true"
            >
              <Icon name="lucide:shield-off" />
              {{ $t('labels.twoFactor.disable') }}
            </Button>
          </div>
        </div>

        <div
          v-else
          class="space-y-4"
        >
          <Alert
            color="destructive"
            variant="modern"
            icon="lucide:shield-off"
          >
            <b>{{ $t('labels.twoFactor.disabled') }}</b>
          </Alert>

          <Button @click="setupDialogOpen = true">
            <Icon name="lucide:shield-plus" />
            {{ $t('labels.twoFactor.enable') }}
          </Button>
        </div>
      </CardContent>
    </Card>

    <Dialog v-model:open="showDisableDialog">
      <DialogContent class="sm:max-w-md">
        <DialogHeaderCombined
          :title="$t('labels.twoFactor.disableTitle')"
          :description="$t('labels.twoFactor.disableDescription')"
        />

        <Alert
          variant="modern"
          color="destructive"
          icon="lucide:shield-off"
        >
          <p>{{ $t('labels.twoFactor.disableWarning') }}</p>
        </Alert>

        <InputField
          name="password"
          v-model="disablePassword"
          type="password"
          :label="$t('labels.twoFactor.passwordLabel')"
          :placeholder="$t('labels.twoFactor.passwordPlaceholder')"
        />

        <DialogFooter>
          <Button
            variant="outline"
            @click="showDisableDialog = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="destructive"
            :disabled="!disablePassword || isDisabling"
            @click="handleDisable2FA"
          >
            <Icon
              v-if="isDisabling"
              name="lucide:loader"
              class="animate-spin"
            />
            {{ $t('labels.twoFactor.disableConfirm') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="showBackupCodes">
      <DialogContent class="sm:max-w-md">
        <DialogHeaderCombined
          :title="$t('labels.twoFactor.backupCodes.title')"
          :description="$t('labels.twoFactor.backupCodes.description')"
        />
        <div
          v-if="newBackupCodes?.backup_codes"
          class="space-y-4"
        >
          <BackupCodesDisplay :codes="newBackupCodes.backup_codes" />
        </div>
        <DialogFooter>
          <Button
            variant="primary"
            @click="showBackupCodes = false"
          >
            {{ $t('actions.close') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <TwoFactorSetupDialog v-model:open="setupDialogOpen" />
  </div>
</template>
