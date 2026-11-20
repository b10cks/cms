<script setup lang="ts">
import { toast } from 'vue-sonner'

import GithubIcon from '~/assets/icons/github.svg?component'
import GoogleIcon from '~/assets/icons/google.svg?component'
import StepUpDialog from '~/components/account/StepUpDialog.vue'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { SettingsSection } from '~/components/ui/settings-section'
import type { StepUpCredential } from '~/api/resources/personal-access-tokens'
import { useAlertDialog } from '~/composables/useAlertDialog'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { alert } = useAlertDialog()
const { useSocialLinksQuery, useStartSocialLinkMutation, useUnlinkSocialProviderMutation } =
  useUser()

const { data: providers, isLoading } = useSocialLinksQuery()
const { mutateAsync: startLink, isPending: isStarting } = useStartSocialLinkMutation()
const { mutate: unlinkProvider, isPending: isUnlinking } = useUnlinkSocialProviderMutation()

const linkingProvider = ref<string | null>(null)
const showStepUpDialog = ref(false)
const stepUpFactor = ref<StepUpCredential['factor']>('password')
const stepUpError = ref<string | null>(null)

const providerMeta: Record<string, { icon: Component; labelKey: string }> = {
  google: { icon: GoogleIcon, labelKey: 'labels.login.social.googleProvider' },
  github: { icon: GithubIcon, labelKey: 'labels.login.social.githubProvider' },
}

const providerLabel = (provider: string) => {
  const labelKey = providerMeta[provider]?.labelKey
  return labelKey ? (t(labelKey) as string) : provider
}

/**
 * A linked provider is a login that outlives a password change, so the API
 * asks for the password or a TOTP code first (423) and then hands back a
 * short-lived signed URL that starts the OAuth flow.
 */
const submitLink = async (provider: string, credential?: StepUpCredential) => {
  linkingProvider.value = provider

  try {
    const url = await startLink({
      provider,
      returnPath: route.fullPath.split('?')[0],
      credential,
    })
    window.location.assign(url)
  } catch (error: any) {
    if (error?.status === 423) {
      stepUpFactor.value = error?.data?.requires_2fa ? 'totp' : 'password'
      stepUpError.value = null
      showStepUpDialog.value = true
    } else if (error?.status === 403 && showStepUpDialog.value) {
      stepUpError.value = error?.message ?? null
    }
  }
}

const handleConfirmStepUp = async (credential?: StepUpCredential) => {
  if (!linkingProvider.value || isStarting.value) return

  await submitLink(linkingProvider.value, credential)
}

const handleUnlink = async (provider: string) => {
  const confirmed = await alert.confirm(
    t('labels.account.social.unlinkConfirm.message', {
      provider: providerLabel(provider),
    }) as string,
    {
      title: t('labels.account.social.unlinkConfirm.title') as string,
      confirmLabel: t('labels.account.social.unlinkConfirm.confirmLabel') as string,
      cancelLabel: t('actions.cancel') as string,
      variant: 'destructive',
    }
  )

  if (!confirmed) return

  unlinkProvider(provider)
}

onMounted(() => {
  const status = route.query.social_link
  const provider =
    typeof route.query.provider === 'string' ? providerLabel(route.query.provider) : ''

  if (status === 'linked') {
    toast.success(t('labels.account.social.toast.linked', { provider }) as string)
  } else if (status === 'conflict') {
    toast.error(t('labels.account.social.toast.conflict', { provider }) as string)
  } else if (status === 'error') {
    toast.error(t('labels.account.social.toast.linkFailed', { provider }) as string)
  } else {
    return
  }

  router.replace({ query: { ...route.query, social_link: undefined, provider: undefined } })
})
</script>

<template>
  <SettingsSection
    :title="$t('labels.account.social.title')"
    :description="$t('labels.account.social.description')"
  >
    <Alert
      v-if="!isLoading && (!providers || providers.length === 0)"
      color="warning"
      variant="modern"
      icon="lucide:ban"
    >
      {{ $t('labels.account.social.empty') }}
    </Alert>

    <div
      v-for="provider in providers"
      :key="provider.provider"
      class="flex flex-col gap-4 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="flex items-center gap-3">
        <div class="flex size-10 items-center justify-center rounded-lg bg-input">
          <component
            :is="providerMeta[provider.provider]?.icon"
            class="size-6 text-primary"
          />
        </div>
        <div>
          <p class="font-medium text-primary">
            {{ providerLabel(provider.provider) }}
          </p>
          <p class="text-sm text-muted">
            {{
              provider.linked
                ? $t('labels.account.social.linked')
                : $t('labels.account.social.notLinked')
            }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <Button
          v-if="provider.linked"
          variant="outline"
          :disabled="isUnlinking"
          @click="handleUnlink(provider.provider)"
        >
          <Icon name="lucide:unlink" />
          {{ $t('labels.account.social.unlink') }}
        </Button>
        <Button
          v-else
          variant="primary"
          :loading="isStarting && linkingProvider === provider.provider"
          @click="submitLink(provider.provider)"
        >
          <Icon name="lucide:link" />
          {{ $t('labels.account.social.link') }}
        </Button>
      </div>
    </div>
  </SettingsSection>

  <StepUpDialog
    v-model:open="showStepUpDialog"
    :factor="stepUpFactor"
    :description="
      stepUpFactor === 'totp'
        ? $t('labels.account.social.stepUpTotpDescription')
        : $t('labels.account.social.stepUpPasswordDescription')
    "
    :confirm-label="$t('labels.account.social.stepUpConfirm')"
    :error="stepUpError"
    :loading="isStarting"
    @submit="handleConfirmStepUp"
    @passkey-confirmed="handleConfirmStepUp()"
  />
</template>
