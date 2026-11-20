<script setup lang="ts">
import PasskeyConfirmButton from '~/components/account/PasskeyConfirmButton.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import type { StepUpCredential } from '~/api/resources/personal-access-tokens'

/**
 * Asks for the step-up the API answered 423 for: a TOTP or backup code, the
 * password, or a passkey instead of either. `submit` carries the typed
 * credential; `passkey-confirmed` means the caller retries without one.
 */
const props = defineProps<{
  factor: StepUpCredential['factor']
  description: string
  confirmLabel: string
  error?: string | null
  loading?: boolean
}>()

const emit = defineEmits<{
  submit: [credential: StepUpCredential]
  passkeyConfirmed: []
}>()

const open = defineModel<boolean>('open', { required: true })

const value = ref('')

watch(open, (isOpen) => {
  if (isOpen) value.value = ''
})

const handleSubmit = () => {
  if (!value.value || props.loading) return

  emit('submit', { factor: props.factor, value: value.value })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="$t('labels.account.apiTokens.stepUpTitle')"
        :description="description"
      />

      <InputField
        v-if="factor === 'totp'"
        v-model="value"
        name="step-up-code"
        autocomplete="one-time-code"
        inputmode="numeric"
        :label="$t('labels.account.apiTokens.stepUpTotpLabel')"
        :error="error ?? undefined"
        @keyup.enter="handleSubmit"
      />
      <InputField
        v-else
        v-model="value"
        name="step-up-password"
        type="password"
        autocomplete="current-password"
        :label="$t('labels.account.apiTokens.stepUpPasswordLabel')"
        :error="error ?? undefined"
        @keyup.enter="handleSubmit"
      />

      <DialogFooter>
        <PasskeyConfirmButton
          class="sm:mr-auto"
          @confirmed="emit('passkeyConfirmed')"
        />
        <Button
          variant="outline"
          @click="open = false"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          :loading="loading"
          :disabled="!value"
          @click="handleSubmit"
        >
          {{ confirmLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
