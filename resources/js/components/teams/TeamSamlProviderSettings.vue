<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent } from '~/components/ui/card'
import { CheckboxField, InputField, SelectField, TextField } from '~/components/ui/form'
import { Spinner } from '~/components/ui/spinner'
import type { TeamSamlProviderPayload, TeamSamlProviderResource } from '~/types/teams'

const props = defineProps<{
  teamId: string
  provider?: TeamSamlProviderResource | null
  defaults: Omit<TeamSamlProviderPayload, 'sp_private_key'> & {
    links: {
      login_url: string
      acs_url: string
      sls_url: string
      metadata_url: string
      sp_entity_id: string
    }
  }
  isLoading?: boolean
  isSaving?: boolean
  isDeleting?: boolean
}>()

const emit = defineEmits<{
  save: [payload: TeamSamlProviderPayload]
  delete: []
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()

type RoleMappingRow = {
  id: string
  value: string
  role: string
}

type SamlFormState = Omit<
  TeamSamlProviderPayload,
  'slo_url' | 'sp_x509_cert' | 'sp_private_key' | 'role_attribute' | 'attribute_mapping'
> & {
  slo_url: string
  sp_x509_cert: string
  sp_private_key: string
  role_attribute: string
  attribute_mapping: {
    email: string
    first_name: string
    last_name: string
    external_id: string
  }
}

const form = ref<SamlFormState>({
  ...props.defaults,
  role_mapping: {},
  attribute_mapping: {
    email: props.defaults.attribute_mapping.email,
    first_name: props.defaults.attribute_mapping.first_name ?? '',
    last_name: props.defaults.attribute_mapping.last_name ?? '',
    external_id: props.defaults.attribute_mapping.external_id ?? '',
  },
  slo_url: props.defaults.slo_url ?? '',
  sp_x509_cert: props.defaults.sp_x509_cert ?? '',
  sp_private_key: '',
  role_attribute: props.defaults.role_attribute ?? '',
})

const roleRows = ref<RoleMappingRow[]>([])

const roleOptions = computed(() => [
  { value: 'member', label: t('labels.teams.saml.roles.member') },
  { value: 'admin', label: t('labels.teams.saml.roles.admin') },
  { value: 'owner', label: t('labels.teams.saml.roles.owner') },
])

const links = computed(() => props.provider?.links ?? props.defaults.links)

// When SAML is enabled the IdP identifier, SSO URL, and signing certificate are
// required for it to work. A disabled provider may be saved with empty details.
const requiredFieldsComplete = computed(() => {
  if (!form.value.enabled) return true

  return (
    form.value.idp_entity_id.trim() !== '' &&
    form.value.sso_url.trim() !== '' &&
    form.value.idp_x509_cert.trim() !== ''
  )
})

watch(
  () => [props.provider, props.defaults] as const,
  ([provider, defaults]) => {
    const source = provider ?? defaults
    form.value = {
      enabled: source.enabled,
      idp_entity_id: source.idp_entity_id,
      sso_url: source.sso_url,
      slo_url: source.slo_url ?? '',
      idp_x509_cert: source.idp_x509_cert,
      sp_x509_cert: source.sp_x509_cert ?? '',
      sp_private_key: '',
      name_id_format: source.name_id_format,
      attribute_mapping: {
        email: source.attribute_mapping.email,
        first_name: source.attribute_mapping.first_name ?? '',
        last_name: source.attribute_mapping.last_name ?? '',
        external_id: source.attribute_mapping.external_id ?? '',
      },
      role_attribute: source.role_attribute ?? '',
      role_mapping: source.role_mapping ?? {},
      default_role: source.default_role,
      allow_jit: source.allow_jit,
      strict: source.strict,
      sign_authn_requests: source.sign_authn_requests,
      sign_logout_requests: source.sign_logout_requests,
      want_assertions_signed: source.want_assertions_signed,
      want_messages_signed: source.want_messages_signed,
      want_assertions_encrypted: source.want_assertions_encrypted,
      digest_algorithm: source.digest_algorithm,
      signature_algorithm: source.signature_algorithm,
    }

    roleRows.value = Object.entries(source.role_mapping ?? {}).map(([value, role]) => ({
      id: crypto.randomUUID(),
      value,
      role,
    }))
  },
  { immediate: true, deep: true }
)

const addRoleMapping = () => {
  roleRows.value.push({
    id: crypto.randomUUID(),
    value: '',
    role: form.value.default_role,
  })
}

const removeRoleMapping = (id: string) => {
  roleRows.value = roleRows.value.filter((row) => row.id !== id)
}

const handleSave = () => {
  if (!requiredFieldsComplete.value) return

  const roleMapping = Object.fromEntries(
    roleRows.value
      .filter((row) => row.value.trim() !== '')
      .map((row) => [row.value.trim(), row.role])
  )

  emit('save', {
    ...form.value,
    slo_url: form.value.slo_url || null,
    sp_x509_cert: form.value.sp_x509_cert || null,
    sp_private_key: form.value.sp_private_key || null,
    attribute_mapping: {
      email: form.value.attribute_mapping.email,
      first_name: form.value.attribute_mapping.first_name || null,
      last_name: form.value.attribute_mapping.last_name || null,
      external_id: form.value.attribute_mapping.external_id || null,
    },
    role_attribute: form.value.role_attribute || null,
    role_mapping: roleMapping,
  })
}

const handleDelete = async () => {
  const confirmed = await alert.confirm(t('labels.teams.saml.deleteConfirm.message'), {
    title: t('labels.teams.saml.deleteConfirm.title'),
    confirmLabel: t('labels.teams.saml.deleteConfirm.confirmLabel'),
    cancelLabel: t('actions.cancel'),
    variant: 'destructive',
  })

  if (confirmed) {
    emit('delete')
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
      <div class="space-y-1">
        <h2 class="font-semibold">{{ $t('labels.teams.saml.title') }}</h2>
        <p class="text-muted-foreground max-w-3xl text-sm">
          {{ $t('labels.teams.saml.description') }}
        </p>
      </div>

      <div class="flex flex-wrap gap-2">
        <Button
          variant="outline"
          as-child
        >
          <a
            :href="links.metadata_url"
            target="_blank"
            rel="noreferrer"
          >
            <Icon name="lucide:file-code" />
            {{ $t('labels.teams.saml.metadata') }}
          </a>
        </Button>
        <Button
          variant="outline"
          as-child
        >
          <a :href="links.login_url">
            <Icon name="lucide:log-in" />
            {{ $t('labels.teams.saml.testLogin') }}
          </a>
        </Button>
      </div>
    </div>

    <Card
      v-if="isLoading"
      variant="surface"
    >
      <CardContent class="flex items-center gap-2 py-6">
        <Spinner />
        {{ $t('labels.loading') }}
      </CardContent>
    </Card>

    <form
      v-else
      class="space-y-8"
      @submit.prevent="handleSave"
    >
      <section class="space-y-4">
        <div class="space-y-1">
          <h3 class="font-semibold">{{ $t('labels.teams.saml.spTitle') }}</h3>
          <p class="text-muted-foreground text-sm">{{ $t('labels.teams.saml.spDescription') }}</p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <InputField
            :model-value="links.sp_entity_id"
            name="sp-entity-id"
            readonly
            :label="$t('labels.teams.saml.fields.spEntityId')"
            :actions="['copy']"
          />
          <InputField
            :model-value="links.acs_url"
            name="acs-url"
            readonly
            :label="$t('labels.teams.saml.fields.acsUrl')"
            :actions="['copy']"
          />
          <InputField
            :model-value="links.sls_url"
            name="sls-url"
            readonly
            :label="$t('labels.teams.saml.fields.slsUrl')"
            :actions="['copy']"
          />
          <InputField
            :model-value="links.metadata_url"
            name="metadata-url"
            readonly
            :label="$t('labels.teams.saml.fields.metadataUrl')"
            :actions="['copy']"
          />
        </div>
      </section>

      <section class="space-y-4">
        <div class="space-y-1">
          <h3 class="font-semibold">{{ $t('labels.teams.saml.idpTitle') }}</h3>
          <p class="text-muted-foreground text-sm">{{ $t('labels.teams.saml.idpDescription') }}</p>
        </div>

        <CheckboxField
          v-model="form.enabled"
          name="saml-enabled"
          :label="$t('labels.teams.saml.fields.enabled')"
          :description="$t('labels.teams.saml.fields.enabledDescription')"
        />

        <div
          class="grid gap-4 lg:grid-cols-2"
          :class="{ 'pointer-events-none opacity-60': !form.enabled }"
        >
          <InputField
            v-model="form.idp_entity_id"
            name="idp-entity-id"
            required
            :label="$t('labels.teams.saml.fields.idpEntityId')"
          />
          <InputField
            v-model="form.sso_url"
            name="sso-url"
            required
            type="url"
            :label="$t('labels.teams.saml.fields.ssoUrl')"
          />
          <InputField
            v-model="form.slo_url"
            name="slo-url"
            type="url"
            :label="$t('labels.teams.saml.fields.sloUrl')"
          />
          <InputField
            v-model="form.name_id_format"
            name="name-id-format"
            required
            :label="$t('labels.teams.saml.fields.nameIdFormat')"
          />
        </div>

        <TextField
          v-model="form.idp_x509_cert"
          name="idp-cert"
          required
          :rows="6"
          :class="{ 'pointer-events-none opacity-60': !form.enabled }"
          :label="$t('labels.teams.saml.fields.idpCert')"
          :placeholder="$t('labels.teams.saml.fields.certPlaceholder')"
        />
      </section>

      <section
        class="space-y-4"
        :class="{ 'pointer-events-none opacity-60': !form.enabled }"
      >
        <div class="space-y-1">
          <h3 class="font-semibold">{{ $t('labels.teams.saml.mappingTitle') }}</h3>
          <p class="text-muted-foreground text-sm">
            {{ $t('labels.teams.saml.mappingDescription') }}
          </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <InputField
            v-model="form.attribute_mapping.email"
            name="mapping-email"
            required
            :label="$t('labels.teams.saml.fields.emailAttribute')"
          />
          <InputField
            v-model="form.attribute_mapping.external_id"
            name="mapping-external-id"
            :label="$t('labels.teams.saml.fields.externalIdAttribute')"
          />
          <InputField
            v-model="form.attribute_mapping.first_name"
            name="mapping-first-name"
            :label="$t('labels.teams.saml.fields.firstNameAttribute')"
          />
          <InputField
            v-model="form.attribute_mapping.last_name"
            name="mapping-last-name"
            :label="$t('labels.teams.saml.fields.lastNameAttribute')"
          />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <SelectField
            v-model="form.default_role"
            name="default-role"
            :label="$t('labels.teams.saml.fields.defaultRole')"
            :options="roleOptions"
          />
          <InputField
            v-model="form.role_attribute"
            name="role-attribute"
            :label="$t('labels.teams.saml.fields.roleAttribute')"
          />
        </div>

        <div class="space-y-3">
          <div class="flex items-center justify-between gap-3">
            <h4 class="font-medium">{{ $t('labels.teams.saml.roleMappings') }}</h4>
            <Button
              type="button"
              variant="outline"
              size="sm"
              @click="addRoleMapping"
            >
              <Icon name="lucide:plus" />
              {{ $t('labels.teams.saml.addRoleMapping') }}
            </Button>
          </div>

          <div
            v-for="row in roleRows"
            :key="row.id"
            class="grid gap-3 rounded-lg border border-input p-3 lg:grid-cols-[1fr_220px_auto]"
          >
            <InputField
              v-model="row.value"
              :name="`role-map-value-${row.id}`"
              :label="$t('labels.teams.saml.fields.samlRoleValue')"
            />
            <SelectField
              v-model="row.role"
              :name="`role-map-role-${row.id}`"
              :label="$t('labels.teams.saml.fields.b10cksRole')"
              :options="roleOptions"
            />
            <Button
              type="button"
              variant="ghost"
              size="icon"
              class="self-end"
              :title="$t('actions.delete')"
              @click="removeRoleMapping(row.id)"
            >
              <Icon name="lucide:trash-2" />
            </Button>
          </div>
        </div>
      </section>

      <section
        class="space-y-4"
        :class="{ 'pointer-events-none opacity-60': !form.enabled }"
      >
        <div class="space-y-1">
          <h3 class="font-semibold">{{ $t('labels.teams.saml.securityTitle') }}</h3>
          <p class="text-muted-foreground text-sm">
            {{ $t('labels.teams.saml.securityDescription') }}
          </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <CheckboxField
            v-model="form.allow_jit"
            name="allow-jit"
            :label="$t('labels.teams.saml.fields.allowJit')"
          />
          <CheckboxField
            v-model="form.strict"
            name="strict"
            :label="$t('labels.teams.saml.fields.strict')"
          />
          <CheckboxField
            v-model="form.want_assertions_signed"
            name="want-assertions-signed"
            :label="$t('labels.teams.saml.fields.wantAssertionsSigned')"
          />
          <CheckboxField
            v-model="form.want_messages_signed"
            name="want-messages-signed"
            :label="$t('labels.teams.saml.fields.wantMessagesSigned')"
          />
          <CheckboxField
            v-model="form.want_assertions_encrypted"
            name="want-assertions-encrypted"
            :label="$t('labels.teams.saml.fields.wantAssertionsEncrypted')"
          />
          <CheckboxField
            v-model="form.sign_authn_requests"
            name="sign-authn-requests"
            :label="$t('labels.teams.saml.fields.signAuthnRequests')"
          />
          <CheckboxField
            v-model="form.sign_logout_requests"
            name="sign-logout-requests"
            :label="$t('labels.teams.saml.fields.signLogoutRequests')"
          />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <InputField
            v-model="form.digest_algorithm"
            name="digest-algorithm"
            required
            :label="$t('labels.teams.saml.fields.digestAlgorithm')"
          />
          <InputField
            v-model="form.signature_algorithm"
            name="signature-algorithm"
            required
            :label="$t('labels.teams.saml.fields.signatureAlgorithm')"
          />
        </div>

        <TextField
          v-model="form.sp_x509_cert"
          name="sp-cert"
          :rows="5"
          :label="$t('labels.teams.saml.fields.spCert')"
          :placeholder="$t('labels.teams.saml.fields.certPlaceholder')"
        />
        <TextField
          v-model="form.sp_private_key"
          name="sp-private-key"
          :rows="5"
          :label="$t('labels.teams.saml.fields.spPrivateKey')"
          :description="
            provider?.has_sp_private_key
              ? $t('labels.teams.saml.fields.spPrivateKeyStored')
              : undefined
          "
        />
      </section>

      <div
        class="flex flex-col-reverse gap-2 border-t border-input pt-6 sm:flex-row sm:justify-between"
      >
        <Button
          v-if="provider"
          type="button"
          variant="destructive"
          :loading="isDeleting"
          @click="handleDelete"
        >
          <Icon
            v-if="!isDeleting"
            name="lucide:trash-2"
          />
          {{ $t('labels.teams.saml.delete') }}
        </Button>
        <span v-else></span>

        <div class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
          <span
            v-if="form.enabled && !requiredFieldsComplete"
            class="text-muted-foreground text-sm"
          >
            {{ $t('labels.teams.saml.requiredHint') }}
          </span>
          <Button
            type="submit"
            :loading="isSaving"
            :disabled="!requiredFieldsComplete"
          >
            <Icon
              v-if="!isSaving"
              name="lucide:save"
            />
            {{ $t('labels.teams.saml.save') }}
          </Button>
        </div>
      </div>
    </form>
  </div>
</template>
