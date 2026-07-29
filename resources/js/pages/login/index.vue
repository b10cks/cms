<script setup lang="ts">
import Logo from '~/assets/logo.svg'
import Markdown from '~/components/Markdown.vue'
import SocialLoginButtons from '~/components/SocialLoginButtons.vue'
import TwoFactorVerifyDialog from '~/components/TwoFactorVerifyDialog.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { runtimeConfig } from '~/lib/runtime-config'

const {
  login,
  error,
  requiresTwoFactor,
  verifyTwoFactorAndLogin,
  verifySocialTwoFactorAndLogin,
  cancelTwoFactorLogin,
} = useAuth()
const { t } = useI18n()
const route = useRoute()

useSeoMeta({
  title: computed(() => t('labels.login.pageTitle')),
})

const formData = ref<{
  email: string
  password: string
}>({
  email: '',
  password: '',
})

const twoFactorDialogOpen = ref(false)
const isSocialTwoFactor = ref(false)

watch(requiresTwoFactor, (value) => {
  if (value) {
    isSocialTwoFactor.value = false
  }

  twoFactorDialogOpen.value = value
})

const handleVerify = async (code: string): Promise<boolean> => {
  if (isSocialTwoFactor.value) {
    return await verifySocialTwoFactorAndLogin(code)
  }

  return await verifyTwoFactorAndLogin(code)
}

const handleCancel = () => {
  isSocialTwoFactor.value = false
  cancelTwoFactorLogin()
}

// A closed instance (self-hosted after the first account exists) still allows
// invite-based signups, so the link stays when invite params are present.
const showSignup = computed(() => {
  return runtimeConfig.public.features.registration || typeof route.query.invite_id === 'string'
})

const signupUrl = computed(() => {
  const params = new URLSearchParams()
  const inviteId = route.query.invite_id
  const inviteToken = route.query.invite_token
  const returnPath = route.query.return

  if (typeof inviteId === 'string' && inviteId.length > 0) {
    params.set('invite_id', inviteId)
  }

  if (typeof inviteToken === 'string' && inviteToken.length > 0) {
    params.set('invite_token', inviteToken)
  }

  if (typeof returnPath === 'string' && returnPath.length > 0) {
    params.set('return', returnPath)
  }

  const query = params.toString()
  return query ? `/login/signup?${query}` : '/login/signup'
})

// Handle session expired message
onMounted(() => {
  if (route.query.message === 'session_expired') {
    error.value = t('composables.auth.sessionExpired') as string
  }

  if (route.query.social_error === '1') {
    error.value = t('composables.auth.socialLoginFailed') as string
  }

  if (route.query.social_2fa === '1') {
    isSocialTwoFactor.value = true
    twoFactorDialogOpen.value = true
  }
})
</script>

<template>
  <div class="grid w-full max-w-md space-y-8 select-none">
    <div class="mb-6 grid gap-4">
      <Logo
        alt="b10cks logo"
        class="h-8 w-8 text-primary"
      />
      <h1
        class="text-2xl font-semibold text-primary"
        v-text="$t('labels.login.header')"
      />
      <p class="text-sm text-muted">{{ $t('labels.login.intro') }}</p>
    </div>
    <form
      class="grid gap-6"
      @submit.prevent="login(formData)"
    >
      <Alert
        v-if="error && !requiresTwoFactor"
        color="destructive"
        icon="lucide:alert-circle"
        class="select-text"
      >
        {{ error }}
      </Alert>
      <InputField
        v-model="formData.email"
        type="email"
        name="email"
        autocomplete="username"
        :label="$t('labels.login.fields.emailLabel')"
        :placeholder="$t('labels.login.fields.emailPlaceholder')"
        required
      />
      <div class="grid gap-3">
        <InputField
          v-model="formData.password"
          type="password"
          name="password"
          autocomplete="current-password"
          :label="$t('labels.login.fields.passwordLabel')"
          :placeholder="$t('labels.login.fields.passwordPlaceholder')"
          required
        />
        <div class="text-right">
          <RouterLink to="/login/password">{{ $t('labels.login.forgotPassword') }}</RouterLink>
        </div>
      </div>
      <Button variant="primary">{{ $t('actions.login') }}</Button>
      <Markdown
        v-if="showSignup"
        :content="$t('labels.login.signup', { url: signupUrl })"
      />
      <SocialLoginButtons />
    </form>
  </div>
  <TwoFactorVerifyDialog
    v-model:open="twoFactorDialogOpen"
    :on-verify="handleVerify"
    :on-cancel="handleCancel"
  />
</template>
