<script setup lang="ts">
import Logo from '~/assets/logo.svg'
import Markdown from '~/components/Markdown.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

useSeoMeta({
  title: computed(() => t('labels.login.passwordPageTitle')),
})

const isSubmitting = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)

const token = computed(() => {
  const value = route.query.token
  return typeof value === 'string' && value.length > 0 ? value : ''
})

const email = computed(() => {
  const value = route.query.email
  return typeof value === 'string' && value.length > 0 ? value : ''
})

const hasResetToken = computed(() => token.value.length > 0 && email.value.length > 0)

const requestForm = ref<{
  email: string
}>({
  email: email.value,
})

const resetForm = ref<{
  email: string
  token: string
  password: string
  password_confirmation: string
}>({
  email: email.value,
  token: token.value,
  password: '',
  password_confirmation: '',
})

watch(email, (value) => {
  requestForm.value.email = value
  resetForm.value.email = value
})

watch(token, (value) => {
  resetForm.value.token = value
})

const loginUrl = computed(() => '/login')

const submitForgotPassword = async () => {
  isSubmitting.value = true
  error.value = null
  success.value = null

  try {
    const { api } = await import('~/api')
    await api.client.ensureCsrfCookie()
    const response = await api.client.post<{ message?: string }>('/auth/v1/password/email', {
      email: requestForm.value.email,
    })

    success.value =
      response?.message ||
      'If your email address exists in our system, we have sent you a password reset link.'
  } catch (err: any) {
    const message =
      err?.data?.message ||
      err?.response?.data?.message ||
      err?.data?.errors?.email?.[0] ||
      err?.response?.data?.errors?.email?.[0] ||
      err?.message ||
      'We could not send a password reset link. Please try again.'

    error.value = message
  } finally {
    isSubmitting.value = false
  }
}

const submitPasswordReset = async () => {
  isSubmitting.value = true
  error.value = null
  success.value = null

  try {
    const { api } = await import('~/api')
    await api.client.ensureCsrfCookie()
    const response = await api.client.post<{ message?: string }>('/auth/v1/password/reset', {
      email: resetForm.value.email,
      token: resetForm.value.token,
      password: resetForm.value.password,
      password_confirmation: resetForm.value.password_confirmation,
    })

    success.value =
      response?.message ||
      'Your password has been reset successfully. You can now sign in with your new password.'

    await router.push('/login')
  } catch (err: any) {
    const message =
      err?.data?.message ||
      err?.response?.data?.message ||
      err?.data?.errors?.email?.[0] ||
      err?.response?.data?.errors?.email?.[0] ||
      err?.data?.errors?.password?.[0] ||
      err?.response?.data?.errors?.password?.[0] ||
      err?.message ||
      'We could not reset your password. Please check the link and try again.'

    error.value = message
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="grid w-full max-w-md space-y-8 select-none">
    <div class="grid gap-4">
      <Logo
        alt="b10cks logo"
        class="h-8 w-8 text-primary"
      />
      <h1
        class="text-2xl font-semibold text-primary"
        v-text="$t('labels.login.passwordPageTitle')"
      />
      <p class="text-sm text-muted">
        {{
          hasResetToken
            ? 'Choose a new password for your account.'
            : 'Enter your email address and we will send you a password reset link.'
        }}
      </p>
    </div>

    <form
      v-if="!hasResetToken"
      class="grid gap-6"
      @submit.prevent="submitForgotPassword"
    >
      <Alert
        v-if="error"
        color="destructive"
        icon="lucide:alert-circle"
        class="select-text"
      >
        {{ error }}
      </Alert>

      <Alert
        v-if="success"
        color="success"
        icon="lucide:mail-check"
        class="select-text"
      >
        {{ success }}
      </Alert>

      <InputField
        v-model="requestForm.email"
        type="email"
        name="email"
        :label="$t('labels.login.fields.emailLabel')"
        :placeholder="$t('labels.login.fields.emailPlaceholder')"
        required
      />

      <Button
        variant="primary"
        :disabled="isSubmitting"
      >
        {{ isSubmitting ? 'Sending…' : 'Send reset link' }}
      </Button>

      <Markdown :content="$t('labels.login.login', { url: loginUrl })" />
    </form>

    <form
      v-else
      class="grid gap-6"
      @submit.prevent="submitPasswordReset"
    >
      <Alert
        v-if="error"
        color="destructive"
        icon="lucide:alert-circle"
        class="select-text"
      >
        {{ error }}
      </Alert>

      <Alert
        v-if="success"
        color="success"
        icon="lucide:check-circle"
        class="select-text"
      >
        {{ success }}
      </Alert>

      <InputField
        v-model="resetForm.email"
        type="email"
        name="email"
        :label="$t('labels.login.fields.emailLabel')"
        :placeholder="$t('labels.login.fields.emailPlaceholder')"
        required
      />

      <InputField
        v-model="resetForm.password"
        type="password"
        name="password"
        :label="$t('labels.login.fields.passwordLabel')"
        :placeholder="$t('labels.login.fields.passwordPlaceholder')"
        required
      />

      <InputField
        v-model="resetForm.password_confirmation"
        type="password"
        name="password_confirmation"
        :label="$t('labels.login.fields.passwordConfirmationLabel')"
        :placeholder="$t('labels.login.fields.passwordConfirmationPlaceholder')"
        required
      />

      <Button
        variant="primary"
        :disabled="isSubmitting"
      >
        {{ isSubmitting ? 'Resetting…' : 'Reset password' }}
      </Button>

      <Markdown :content="$t('labels.login.login', { url: loginUrl })" />
      <Markdown :content="'Need a new reset link? [Request another one](/login/password)'" />
    </form>
  </div>
</template>
