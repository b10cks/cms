<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

/**
 * Offers a passkey wherever a password or TOTP code is asked for. Shown only
 * when the browser supports passkeys and the user has one. After `confirmed`
 * the API accepts step-up requests without a credential for a few minutes.
 */
const emit = defineEmits<{ confirmed: [] }>()

const { supported, usePasskeysQuery, useConfirmPasskeyMutation } = usePasskeys()
const { data: passkeys } = usePasskeysQuery()
const { mutateAsync: confirm, isPending } = useConfirmPasskeyMutation()

const visible = computed(() => supported && (passkeys.value?.length ?? 0) > 0)

const handleClick = async () => {
  try {
    await confirm()
    emit('confirmed')
  } catch {
    /** Reported by the mutation. A cancelled prompt leaves the dialog as it was. */
  }
}
</script>

<template>
  <Button
    v-if="visible"
    variant="outline"
    :loading="isPending"
    @click="handleClick"
  >
    <Icon name="lucide:key-round" />
    {{ $t('labels.account.passkeys.useInstead') }}
  </Button>
</template>
