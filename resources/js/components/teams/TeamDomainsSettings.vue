<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { InputField, SelectField } from '~/components/ui/form'
import { SettingsSection } from '~/components/ui/settings-section'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import type { TeamDomainPolicy, TeamDomainResource } from '~/types/team-domains'

const props = defineProps<{
  teamId: string
  /** Whether the team has an enabled SAML provider; "Require SSO" needs one. */
  ssoEnabled?: boolean
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { useAuthorizationQuery } = useAuthorization()
const {
  useTeamDomainsQuery,
  useAddTeamDomainMutation,
  useVerifyTeamDomainMutation,
  useUpdateTeamDomainMutation,
  useRemoveTeamDomainMutation,
} = useTeamDomains()

const teamId = toRef(props, 'teamId')
const { data: domains, isLoading } = useTeamDomainsQuery(teamId)
const { data: authorization } = useAuthorizationQuery(computed(() => ({ team_id: teamId.value })))

const addMutation = useAddTeamDomainMutation()
const verifyMutation = useVerifyTeamDomainMutation()
const updateMutation = useUpdateTeamDomainMutation()
const removeMutation = useRemoveTeamDomainMutation()

const newDomain = ref('')
const verifyErrors = ref<Record<string, string>>({})

const policyOptions = computed(() =>
  (['request', 'auto_join', 'require_sso'] as const).map((value) => ({
    value,
    label: t(`components.teamDomains.policies.${value}`),
    disabled: value === 'require_sso' && !props.ssoEnabled,
  }))
)

const roleOptions = computed(() =>
  (authorization.value?.roles.team ?? [])
    .filter((role) => role.key !== 'owner')
    .map((role) => ({ value: role.key, label: role.name }))
)

const addDomain = () => {
  const domain = newDomain.value.trim()
  if (!domain) return

  addMutation.mutate(
    { teamId: teamId.value, domain },
    { onSuccess: () => (newDomain.value = '') }
  )
}

const verify = (domain: TeamDomainResource) => {
  delete verifyErrors.value[domain.id]
  verifyMutation.mutate(
    { teamId: teamId.value, domainId: domain.id },
    {
      onError: (error: Error) => {
        verifyErrors.value[domain.id] = error.message
      },
    }
  )
}

const updatePolicy = (domain: TeamDomainResource, policy: TeamDomainPolicy) => {
  if (policy === domain.policy) return
  updateMutation.mutate({ teamId: teamId.value, domainId: domain.id, payload: { policy } })
}

const updateDefaultRole = (domain: TeamDomainResource, role: string) => {
  if (role === domain.default_role) return
  updateMutation.mutate({
    teamId: teamId.value,
    domainId: domain.id,
    payload: { default_role: role },
  })
}

const remove = async (domain: TeamDomainResource) => {
  const confirmed = await alert.confirm(
    t('components.teamDomains.removeConfirm.message', { domain: domain.display_domain }),
    {
      title: t('components.teamDomains.removeConfirm.title'),
      confirmLabel: t('components.teamDomains.removeConfirm.confirm'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )

  if (confirmed) {
    removeMutation.mutate({ teamId: teamId.value, domainId: domain.id })
  }
}
</script>

<template>
  <SettingsSection
    id="domains"
    wide
    :title="$t('components.teamDomains.title')"
    :description="$t('components.teamDomains.description')"
  >
    <form
      class="flex items-end gap-3"
      @submit.prevent="addDomain"
    >
      <InputField
        v-model="newDomain"
        class="flex-1"
        name="team-domain"
        :label="$t('components.teamDomains.fields.domain')"
        :placeholder="$t('components.teamDomains.fields.domainPlaceholder')"
        :tooltip="$t('components.teamDomains.fields.domainHint')"
      />
      <Button
        type="submit"
        :loading="addMutation.isPending.value"
        :disabled="!newDomain.trim()"
      >
        <Icon
          v-if="!addMutation.isPending.value"
          name="lucide:plus"
        />
        {{ $t('components.teamDomains.add') }}
      </Button>
    </form>

    <div class="overflow-hidden rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('components.teamDomains.columns.domain') }}</TableHead>
            <TableHead>{{ $t('components.teamDomains.columns.status') }}</TableHead>
            <TableHead class="w-56">{{ $t('components.teamDomains.columns.policy') }}</TableHead>
            <TableHead class="w-44">{{ $t('components.teamDomains.columns.defaultRole') }}</TableHead>
            <TableHead class="w-36" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="5"
            :rows="2"
          />
          <TableEmptyRow
            v-else-if="!domains?.length"
            :colspan="5"
            :label="$t('components.teamDomains.empty')"
          />
          <template v-else>
            <template
              v-for="domain in domains"
              :key="domain.id"
            >
              <TableRow>
                <TableCell class="font-medium">{{ domain.display_domain }}</TableCell>
                <TableCell>
                  <Badge
                    :variant="domain.verified_at ? 'success' : 'warning'"
                    size="sm"
                  >
                    {{
                      domain.verified_at
                        ? $t('components.teamDomains.status.verified')
                        : $t('components.teamDomains.status.pending')
                    }}
                  </Badge>
                </TableCell>
                <TableCell>
                  <SelectField
                    :model-value="domain.policy"
                    :name="`domain-policy-${domain.id}`"
                    :options="policyOptions"
                    :disabled="updateMutation.isPending.value"
                    @update:model-value="(value) => updatePolicy(domain, value)"
                  />
                </TableCell>
                <TableCell>
                  <SelectField
                    :model-value="domain.default_role"
                    :name="`domain-role-${domain.id}`"
                    :options="roleOptions"
                    :disabled="updateMutation.isPending.value"
                    @update:model-value="(value) => updateDefaultRole(domain, value)"
                  />
                </TableCell>
                <TableCell>
                  <div class="flex justify-end gap-1">
                    <Button
                      v-if="!domain.verified_at"
                      size="sm"
                      variant="outline"
                      :loading="
                        verifyMutation.isPending.value &&
                        verifyMutation.variables.value?.domainId === domain.id
                      "
                      @click="verify(domain)"
                    >
                      {{ $t('components.teamDomains.verify') }}
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      :aria-label="$t('actions.delete')"
                      :title="$t('actions.delete')"
                      @click="remove(domain)"
                    >
                      <Icon name="lucide:trash-2" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
              <TableRow
                v-if="!domain.verified_at"
                class="hover:bg-transparent"
              >
                <TableCell
                  :colspan="5"
                  class="pt-0"
                >
                  <div class="grid gap-3 rounded-lg bg-surface p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <p class="text-sm text-muted lg:col-span-2">
                      {{ $t('components.teamDomains.txtInstructions') }}
                    </p>
                    <InputField
                      :model-value="domain.domain"
                      :name="`domain-txt-host-${domain.id}`"
                      readonly
                      :label="$t('components.teamDomains.fields.txtHost')"
                      :actions="['copy']"
                    />
                    <InputField
                      :model-value="domain.txt_record"
                      :name="`domain-txt-value-${domain.id}`"
                      readonly
                      :label="$t('components.teamDomains.fields.txtValue')"
                      :actions="['copy']"
                      :error="verifyErrors[domain.id]"
                    />
                  </div>
                </TableCell>
              </TableRow>
            </template>
          </template>
        </TableBody>
      </Table>
    </div>
  </SettingsSection>
</template>
