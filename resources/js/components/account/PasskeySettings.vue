<script setup lang="ts">
import StepUpDialog from '~/components/account/StepUpDialog.vue'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import { SettingsSection } from '~/components/ui/settings-section'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import type { Passkey } from '~/api/resources/passkeys'
import type { StepUpCredential } from '~/api/resources/personal-access-tokens'
import { useAlertDialog } from '~/composables/useAlertDialog'

const { t } = useI18n()
const { formatDateTime } = useFormat()
const { alert } = useAlertDialog()
const {
  supported,
  usePasskeysQuery,
  useAddPasskeyMutation,
  useRenamePasskeyMutation,
  useDeletePasskeyMutation,
} = usePasskeys()

const { data: passkeys, isLoading } = usePasskeysQuery()
const { mutateAsync: addPasskey, isPending: isAdding } = useAddPasskeyMutation()
const { mutateAsync: renamePasskey, isPending: isRenaming } = useRenamePasskeyMutation()
const { mutateAsync: deletePasskey, isPending: isDeleting } = useDeletePasskeyMutation()

const nameDialogOpen = ref(false)
const nameValue = ref('')
const renaming = ref<Passkey | null>(null)

const stepUpOpen = ref(false)
const stepUpFactor = ref<StepUpCredential['factor']>('password')
const stepUpError = ref<string | null>(null)
const stepUpDescription = ref('')
const retry = shallowRef<((credential?: StepUpCredential) => Promise<void>) | null>(null)

/**
 * Adding and removing a passkey needs a step-up. The request goes out
 * without one, and the API's 423 says which factor to ask for; the action is
 * kept so the dialog can replay it with the credential.
 */
const withStepUp = async (
  action: (credential?: StepUpCredential) => Promise<unknown>,
  description: string,
  credential?: StepUpCredential
) => {
  try {
    await action(credential)
    stepUpOpen.value = false
    retry.value = null
  } catch (error: any) {
    if (error?.status === 423) {
      stepUpFactor.value = error?.data?.requires_2fa ? 'totp' : 'password'
      stepUpError.value = null
      stepUpDescription.value = description
      retry.value = (next) => withStepUp(action, description, next)
      stepUpOpen.value = true
    } else if (error?.status === 403 && stepUpOpen.value) {
      stepUpError.value = error?.message ?? null
    }
  }
}

const openAdd = () => {
  renaming.value = null
  nameValue.value = ''
  nameDialogOpen.value = true
}

const openRename = (passkey: Passkey) => {
  renaming.value = passkey
  nameValue.value = passkey.name
  nameDialogOpen.value = true
}

const handleSaveName = async () => {
  const name = nameValue.value.trim()
  if (!name) return

  if (renaming.value) {
    await renamePasskey({ id: renaming.value.id, name })
    nameDialogOpen.value = false
    return
  }

  nameDialogOpen.value = false
  await withStepUp(
    (credential) => addPasskey({ name, credential }),
    t('labels.account.passkeys.stepUpAddDescription') as string
  )
}

const handleDelete = async (passkey: Passkey) => {
  const confirmed = await alert.confirm(
    t('labels.account.passkeys.removeConfirm', { name: passkey.name }) as string,
    {
      title: t('labels.account.passkeys.removeTitle') as string,
      confirmLabel: t('actions.delete') as string,
      variant: 'destructive',
    }
  )

  if (!confirmed) return

  await withStepUp(
    (credential) => deletePasskey({ id: passkey.id, credential }),
    t('labels.account.passkeys.stepUpRemoveDescription') as string
  )
}

const handleStepUp = (credential?: StepUpCredential) => retry.value?.(credential)
</script>

<template>
  <SettingsSection
    :title="$t('labels.account.passkeys.title')"
    :description="$t('labels.account.passkeys.description')"
  >
    <Alert
      v-if="!supported"
      variant="modern"
      color="warning"
      icon="lucide:alert-triangle"
    >
      {{ $t('labels.account.passkeys.unsupported') }}
    </Alert>

    <div class="overflow-hidden rounded-md border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('labels.account.passkeys.table.name') }}</TableHead>
            <TableHead>{{ $t('labels.account.passkeys.table.createdAt') }}</TableHead>
            <TableHead>{{ $t('labels.account.passkeys.table.lastUsedAt') }}</TableHead>
            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="passkey in passkeys ?? []"
            :key="passkey.id"
          >
            <TableCell class="font-medium">{{ passkey.name }}</TableCell>
            <TableCell>{{ formatDateTime(passkey.created_at) }}</TableCell>
            <TableCell>
              {{ passkey.last_used_at ? formatDateTime(passkey.last_used_at) : $t('labels.never') }}
            </TableCell>
            <TableCell>
              <div class="flex justify-end">
                <Button
                  variant="ghost"
                  size="icon"
                  @click="openRename(passkey)"
                >
                  <Icon name="lucide:pencil" />
                  <span class="sr-only">{{ $t('labels.account.passkeys.rename') }}</span>
                </Button>
                <Button
                  variant="ghost-destructive"
                  size="icon"
                  :disabled="isDeleting"
                  @click="handleDelete(passkey)"
                >
                  <Icon name="lucide:trash-2" />
                  <span class="sr-only">{{ $t('actions.delete') }}</span>
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableEmptyRow
            v-if="!isLoading && !passkeys?.length"
            :colspan="4"
            :label="$t('labels.account.passkeys.empty')"
          />
        </TableBody>
      </Table>
    </div>

    <template #footer>
      <Button
        :loading="isAdding"
        :disabled="!supported"
        @click="openAdd"
      >
        <Icon name="lucide:key-round" />
        {{ $t('labels.account.passkeys.add') }}
      </Button>
    </template>
  </SettingsSection>

  <Dialog v-model:open="nameDialogOpen">
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="
          renaming ? $t('labels.account.passkeys.renameTitle') : $t('labels.account.passkeys.add')
        "
        :description="$t('labels.account.passkeys.nameDescription')"
      />

      <InputField
        v-model="nameValue"
        name="passkey-name"
        :label="$t('labels.account.passkeys.nameLabel')"
        :placeholder="$t('labels.account.passkeys.namePlaceholder')"
        maxlength="100"
        @keyup.enter="handleSaveName"
      />

      <DialogFooter>
        <Button
          variant="outline"
          @click="nameDialogOpen = false"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          :loading="isRenaming"
          :disabled="!nameValue.trim()"
          @click="handleSaveName"
        >
          {{ renaming ? $t('actions.save') : $t('labels.account.passkeys.continue') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <StepUpDialog
    v-model:open="stepUpOpen"
    :factor="stepUpFactor"
    :description="stepUpDescription"
    :confirm-label="$t('labels.account.passkeys.continue')"
    :error="stepUpError"
    :loading="isAdding || isDeleting"
    @submit="handleStepUp"
    @passkey-confirmed="handleStepUp()"
  />
</template>
