<script setup lang="ts">
import Logo from '~/assets/logo.svg'
import Markdown from '~/components/Markdown.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { digest } from '~/lib/utils'

const route = useRoute()
const router = useRouter()
const { register } = useAuth()
const { usePublicInviteQuery } = useInvites()
const { t } = useI18n()

useSeoMeta({
  title: computed(() => t('labels.login.signupPageTitle')),
})

const inviteId = computed(() => route.query.invite_id as string | undefined)
const inviteToken = computed(() => route.query.invite_token as string | undefined)

const { data: publicInvite, error: inviteError } = usePublicInviteQuery(inviteId)

const formData = ref<{
  firstname: string
  lastname: string
  email: string
  password: string
  password_confirmation: string
  invite_id?: string
}>({
  firstname: '',
  lastname: '',
  email: '',
  password: '',
  password_confirmation: '',
  invite_id: inviteId.value,
})

const emailHash = ref<string | undefined>()

watch(
  () => formData.value.email,
  async () => {
    emailHash.value = await digest(formData.value.email)
  }
)

const loginUrl = computed(
  () =>
    `/login${inviteId.value ? `?return=/invites/${inviteId.value}?token=${inviteToken.value}` : ''}`
)

const emailMismatch = computed(() => {
  if (!publicInvite.value?.email_hash || !emailHash.value || !formData.value.email) {
    return false
  }

  return publicInvite.value.email_hash !== emailHash.value
})

const handleSignup = async () => {
  await register({
    ...formData.value,
    invite_id: formData.value.invite_id || undefined,
  })

  if (publicInvite.value?.space?.id) {
    router.push(`/${publicInvite.value.space.id}`)
  }
}
</script>

<template>
  <div class="grid w-full max-w-md space-y-8">
    <div class="grid gap-4">
      <Logo
        alt="b10cks logo"
        class="h-8 w-8 text-primary"
      />
      <h1
        class="font-script text-2xl font-semibold text-primary"
        v-text="$t('labels.login.signupHeader')"
      />
      <Markdown
        class="text-sm text-muted"
        :content="$t('labels.login.signupDescription')"
      />
      <Alert
        v-if="publicInvite"
        color="info"
        variant="modern"
        icon="lucide:handshake"
      >
        <Markdown
          :content="
            $t('labels.login.invite', {
              inviter: publicInvite.inviter?.name,
              name: publicInvite.space?.name || publicInvite.team?.name || 'their team',
            })
          "
        />
      </Alert>
      <Alert
        v-else-if="inviteError"
        color="warning"
        variant="modern"
        icon="lucide:octagon-x"
      >
        {{ inviteError.data.message }}
      </Alert>
    </div>
    <form
      class="grid gap-6"
      @submit.prevent="handleSignup"
    >
      <InputField
        v-model="formData.firstname"
        type="text"
        name="firstname"
        :label="$t('labels.login.fields.firstnameLabel')"
        :placeholder="$t('labels.login.fields.firstnamePlaceholder')"
        required
      />
      <InputField
        v-model="formData.lastname"
        type="text"
        name="lastname"
        :label="$t('labels.login.fields.lastnameLabel')"
        :placeholder="$t('labels.login.fields.lastnamePlaceholder')"
        required
      />
      <InputField
        v-model="formData.email"
        type="email"
        name="email"
        :label="$t('labels.login.fields.emailLabel')"
        :placeholder="$t('labels.login.fields.emailPlaceholder')"
        :description="emailMismatch ? $t('labels.login.emailMismatch') : ''"
        required
      />
      <InputField
        v-model="formData.password"
        type="password"
        name="password"
        :label="$t('labels.login.fields.passwordLabel')"
        :placeholder="$t('labels.login.fields.passwordPlaceholder')"
        required
      />
      <InputField
        v-model="formData.password_confirmation"
        type="password"
        name="password_confirmation"
        :label="$t('labels.login.fields.passwordConfirmationLabel')"
        :placeholder="$t('labels.login.fields.passwordConfirmationPlaceholder')"
        required
      />
      <Button variant="primary">{{ $t('actions.signup') }}</Button>
      <Markdown :content="$t('labels.login.login', { url: loginUrl })" />
    </form>
  </div>
</template>
