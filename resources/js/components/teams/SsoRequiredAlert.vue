<script setup lang="ts">
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { safeReturnPath } from '~/lib/safeReturnPath'

/** Shown when the email's domain belongs to a team that requires its SSO. */
const props = defineProps<{
  /** The team's SAML login URL. */
  url: string
  message?: string | null
}>()

const route = useRoute()

const href = computed(() => {
  const url = new URL(props.url, window.location.origin)
  url.searchParams.set('return', safeReturnPath(route.query.return))
  return url.toString()
})
</script>

<template>
  <Alert
    color="warning"
    variant="modern"
    icon="lucide:key-round"
    class="select-text"
  >
    <div class="grid justify-items-start gap-3">
      <span>{{ message || $t('components.teamDomains.login.ssoRequired') }}</span>
      <Button
        as-child
        size="sm"
        variant="primary"
      >
        <a :href="href">{{ $t('components.teamDomains.login.continueWithSso') }}</a>
      </Button>
    </div>
  </Alert>
</template>
