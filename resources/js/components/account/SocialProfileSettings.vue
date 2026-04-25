<script setup lang="ts">
import { toast } from 'vue-sonner'

import GithubIcon from '~/assets/icons/github.svg?component'
import GoogleIcon from '~/assets/icons/google.svg?component'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardHeaderCombined } from '~/components/ui/card'
import { useAlertDialog } from '~/composables/useAlertDialog'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { alert } = useAlertDialog()
const { useSocialLinksQuery, useUnlinkSocialProviderMutation } = useUser()

const { data: providers, isLoading } = useSocialLinksQuery()
const { mutate: unlinkProvider, isPending: isUnlinking } = useUnlinkSocialProviderMutation()

const providerMeta: Record<string, { icon: string; labelKey: string }> = {
  google: { icon: GoogleIcon, labelKey: 'labels.login.social.googleProvider' },
  github: { icon: GithubIcon, labelKey: 'labels.login.social.githubProvider' },
}

const providerLabel = (provider: string) => {
  const labelKey = providerMeta[provider]?.labelKey
  return labelKey ? (t(labelKey) as string) : provider
}

const linkUrl = (url: string) => {
  const params = new URLSearchParams()
  params.set('return', route.fullPath.split('?')[0])

  return `${url}?${params.toString()}`
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
  <Card variant="none">
    <CardHeaderCombined
      :title="$t('labels.account.social.title')"
      :description="$t('labels.account.social.description')"
    />
    <CardContent class="space-y-4">
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
            as="a"
            variant="primary"
            :href="linkUrl(provider.link_url)"
          >
            <Icon name="lucide:link" />
            {{ $t('labels.account.social.link') }}
          </Button>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
