<script setup lang="ts">
import GithubIcon from '~/assets/icons/github.svg?component'
import GoogleIcon from '~/assets/icons/google.svg?component'
import { Button } from '~/components/ui/button'
import { runtimeConfig } from '~/lib/runtime-config'
import { safeReturnPath } from '~/lib/safeReturnPath'

const route = useRoute()

const providerMeta: Record<string, { icon: Component; labelKey: string }> = {
  google: { icon: GoogleIcon, labelKey: 'labels.login.social.google' },
  github: { icon: GithubIcon, labelKey: 'labels.login.social.github' },
}

const providers = computed(() => {
  return runtimeConfig.public.socialAuth.providers
    .filter((provider) => providerMeta[provider.key])
    .map((provider) => ({
      ...provider,
      ...providerMeta[provider.key],
    }))
})

const providerUrl = (url: string) => {
  const params = new URLSearchParams()
  const returnPath = route.query.return
  const inviteId = route.query.invite_id
  const inviteToken = route.query.invite_token

  if (
    typeof returnPath === 'string' &&
    returnPath.length > 0 &&
    safeReturnPath(returnPath) === returnPath
  ) {
    params.set('return', returnPath)
  }

  if (typeof inviteId === 'string' && inviteId.length > 0) {
    params.set('invite_id', inviteId)
  }

  if (typeof inviteToken === 'string' && inviteToken.length > 0) {
    params.set('invite_token', inviteToken)
  }

  const query = params.toString()
  return `${url}${query ? `?${query}` : ''}`
}
</script>

<template>
  <div
    v-if="providers.length > 0"
    class="grid gap-6"
  >
    <div class="relative flex items-center">
      <div class="h-px flex-1 bg-border" />
      <span class="px-3 text-xs font-medium text-muted">
        {{ $t('labels.login.social.or') }}
      </span>
      <div class="h-px flex-1 bg-border" />
    </div>
    <div class="grid gap-2">
      <Button
        v-for="provider in providers"
        :key="provider.key"
        as="a"
        variant="outline"
        class="w-full"
        :href="providerUrl(provider.url)"
      >
        <component
          :is="provider.icon"
          class="size-5!"
        />
        {{ $t(provider.labelKey) }}
      </Button>
    </div>
  </div>
</template>
