<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import ImpersonateDialog from '~/components/provider/ImpersonateDialog.vue'
import ProviderUserStatusBadge from '~/components/provider/ProviderUserStatusBadge.vue'
import SuspendUserDialog from '~/components/provider/SuspendUserDialog.vue'
import TransferOwnershipDialog from '~/components/provider/TransferOwnershipDialog.vue'
import ElevateDialog from '~/components/root/ElevateDialog.vue'
import StatTile from '~/components/stats/StatTile.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '~/components/ui/sheet'
import { Skeleton } from '~/components/ui/skeleton'
import { useProviderConsole } from '~/composables/useProviderConsole'
import { hasRootWriteAccess } from '~/lib/access-control'
import { instanceAuditLabel } from '~/lib/instance-audit'

const props = defineProps<{ userId: string | null }>()
const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatRelativeTime } = useFormat()
const { user: me } = useAuth()
const { useUserQuery, useSignOutMutation, useRestoreMutation } = useProviderConsole()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({})))

const { data: user, isLoading } = useUserQuery(computed(() => props.userId))
const signOut = useSignOutMutation()
const restore = useRestoreMutation()

const dialogs = reactive({ impersonate: false, suspend: false, transfer: false, elevate: false })

const canWrite = computed(() => hasRootWriteAccess(access.authorization.value))
/** Root accounts and your own are off limits for these actions. */
const isActionable = computed(() => !!user.value && !user.value.is_root && user.value.id !== me.value?.id)

const confirmSignOut = async () => {
  if (!user.value) return
  const ok = await alert.confirm(t('providerConsole.signOut.message', { name: user.value.name }), {
    title: t('providerConsole.signOut.title'),
    confirmLabel: t('providerConsole.actions.signOut'),
  })
  if (ok) signOut.mutate(user.value.id)
}

const confirmRestore = async () => {
  if (!user.value) return
  const ok = await alert.confirm(t('providerConsole.restore.message', { name: user.value.name }), {
    title: t('providerConsole.restore.title'),
    confirmLabel: t('providerConsole.actions.restore'),
  })
  if (ok) restore.mutate(user.value.id)
}

const membershipDetail = (membership: NonNullable<typeof user.value>['memberships'][number]) => {
  if (membership.is_sole_owner) return t('providerConsole.users.sheet.onlyOwner')
  return membership.kind === 'team'
    ? t('providerConsole.users.sheet.teamRole')
    : t('providerConsole.users.sheet.spaceRole')
}
</script>

<template>
  <Sheet v-model:open="open">
    <SheetContent class="flex flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg">
      <div
        v-if="isLoading || !user"
        class="space-y-4 p-6"
      >
        <Skeleton class="h-12 w-2/3" />
        <Skeleton class="h-24 w-full" />
        <SheetTitle class="sr-only">{{ $t('providerConsole.users.title') }}</SheetTitle>
      </div>
      <div
        v-else
        class="space-y-6 p-6"
      >
        <div class="flex items-center gap-3 pr-8">
          <Avatar
            :name="user.name"
            :avatar="user.avatar"
            size="lg"
          />
          <div class="min-w-0 grow">
            <SheetTitle class="truncate text-panel font-semibold text-primary">{{ user.name }}</SheetTitle>
            <SheetDescription class="truncate text-sm text-muted">{{ user.email }}</SheetDescription>
          </div>
          <ProviderUserStatusBadge :status="user.status" />
        </div>

        <div
          v-if="user.suspended_at"
          class="rounded-lg border border-destructive/30 bg-destructive-background/20 p-3 text-sm"
        >
          {{
            $t('providerConsole.users.sheet.suspendedSince', {
              time: formatRelativeTime(user.suspended_at),
              by: user.suspended_by ?? '',
            })
          }}
          <span
            v-if="user.suspension_reason"
            class="block text-muted"
          >{{ user.suspension_reason }}</span>
        </div>

        <div
          v-if="isActionable"
          class="space-y-3"
        >
          <div
            v-if="!canWrite"
            class="flex items-center gap-3 rounded-lg border border-border bg-surface p-3 text-sm"
          >
            <Icon
              name="lucide:shield"
              class="shrink-0 text-warning"
            />
            <span class="grow">{{ $t('providerConsole.users.sheet.elevateHint') }}</span>
            <Button
              size="sm"
              variant="outline"
              @click="dialogs.elevate = true"
            >
              {{ $t('rootAccess.banner.elevate') }}
            </Button>
          </div>
          <div class="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              :disabled="!canWrite || !!user.suspended_at"
              @click="dialogs.impersonate = true"
            >
              <Icon name="lucide:log-in" />
              {{ $t('providerConsole.actions.impersonate') }}
            </Button>
            <Button
              size="sm"
              variant="outline"
              :disabled="!canWrite"
              :loading="signOut.isPending.value"
              @click="confirmSignOut"
            >
              <Icon name="lucide:log-out" />
              {{ $t('providerConsole.actions.signOut') }}
            </Button>
            <Button
              v-if="user.owned_teams.length > 0"
              size="sm"
              variant="outline"
              :disabled="!canWrite"
              @click="dialogs.transfer = true"
            >
              <Icon name="lucide:arrow-left-right" />
              {{ $t('providerConsole.actions.transfer') }}
            </Button>
            <Button
              v-if="user.suspended_at"
              size="sm"
              variant="outline"
              :disabled="!canWrite"
              :loading="restore.isPending.value"
              @click="confirmRestore"
            >
              <Icon name="lucide:undo-2" />
              {{ $t('providerConsole.actions.restore') }}
            </Button>
            <Button
              v-else
              size="sm"
              variant="destructive"
              :disabled="!canWrite"
              @click="dialogs.suspend = true"
            >
              <Icon name="lucide:ban" />
              {{ $t('providerConsole.actions.suspend') }}
            </Button>
          </div>
        </div>

        <dl class="grid grid-cols-3 gap-3">
          <StatTile
            :label="$t('providerConsole.users.sheet.teams')"
            :value="String(user.counts.teams)"
          />
          <StatTile
            :label="$t('providerConsole.users.sheet.sessions')"
            :value="user.counts.sessions === null ? '–' : String(user.counts.sessions)"
          />
          <StatTile
            :label="$t('providerConsole.users.sheet.tokens')"
            :value="String(user.counts.tokens)"
          />
        </dl>

        <section>
          <h3 class="mb-2 font-semibold text-primary">{{ $t('providerConsole.users.sheet.memberships') }}</h3>
          <p
            v-if="user.memberships.length === 0"
            class="text-sm text-muted"
          >
            {{ $t('providerConsole.users.sheet.noMemberships') }}
          </p>
          <ul
            v-else
            class="divide-y divide-border rounded-xl border border-border"
          >
            <li
              v-for="membership in user.memberships"
              :key="`${membership.kind}-${membership.id}`"
              class="flex items-center gap-3 px-3 py-2"
            >
              <Avatar
                :name="membership.name"
                :avatar="membership.avatar"
                size="sm"
                class="size-6"
              />
              <div class="min-w-0 grow">
                <div class="truncate font-semibold text-primary">{{ membership.name }}</div>
                <div class="text-sm text-muted">{{ membershipDetail(membership) }}</div>
              </div>
              <Badge
                v-if="membership.role_name"
                :variant="membership.role_key === 'owner' ? 'warning' : 'secondary'"
                size="sm"
              >
                {{ membership.role_name }}
              </Badge>
            </li>
          </ul>
        </section>

        <section>
          <h3 class="mb-2 font-semibold text-primary">{{ $t('providerConsole.users.sheet.activity') }}</h3>
          <p
            v-if="user.activity.length === 0"
            class="text-sm text-muted"
          >
            {{ $t('providerConsole.users.sheet.noActivity') }}
          </p>
          <ul
            v-else
            class="space-y-2 text-sm"
          >
            <li
              v-for="(entry, index) in user.activity"
              :key="index"
              class="flex gap-3"
            >
              <span class="w-28 shrink-0 text-muted">
                {{ entry.created_at ? formatRelativeTime(entry.created_at) : '' }}
              </span>
              <span>{{ instanceAuditLabel(t, entry.action) }}</span>
            </li>
          </ul>
        </section>
      </div>

      <template v-if="user">
        <ImpersonateDialog
          v-model:open="dialogs.impersonate"
          :user="user"
        />
        <SuspendUserDialog
          v-model:open="dialogs.suspend"
          :user="user"
        />
        <TransferOwnershipDialog
          v-model:open="dialogs.transfer"
          :user="user"
        />
      </template>
      <ElevateDialog v-model:open="dialogs.elevate" />
    </SheetContent>
  </Sheet>
</template>
