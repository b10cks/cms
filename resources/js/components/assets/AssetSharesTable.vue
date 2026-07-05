<script setup lang="ts">
import CreateAssetShareDialog from '~/components/assets/CreateAssetShareDialog.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
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
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import type { AssetShareResource } from '~/types/asset-distribution'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime } = useFormat()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageShares = computed(() => access.hasAbility('asset_shares.manage'))

const currentPage = ref(1)
const perPage = ref(24)

const queryParams = computed(() => ({
  page: currentPage.value,
  per_page: perPage.value,
}))

const {
  useAssetSharesQuery,
  useRevokeAssetShareMutation,
  useDeleteAssetShareMutation,
  copyShareLink,
} = useAssetShares(props.spaceId)
const { data: shares, isLoading } = useAssetSharesQuery(queryParams)
const { mutate: revokeShare } = useRevokeAssetShareMutation()
const { mutate: deleteShare } = useDeleteAssetShareMutation()

const editDialogOpen = ref(false)
const editingShare = ref<AssetShareResource | null>(null)

const openEditDialog = (share: AssetShareResource) => {
  editingShare.value = share
  editDialogOpen.value = true
}

const shareStatus = (share: AssetShareResource): 'revoked' | 'expired' | 'active' => {
  if (share.is_revoked) return 'revoked'
  if (share.is_expired) return 'expired'
  return 'active'
}

const statusVariant = (share: AssetShareResource) => {
  switch (shareStatus(share)) {
    case 'active':
      return 'success'
    case 'expired':
      return 'warning'
    default:
      return 'destructive'
  }
}

const openShare = (share: AssetShareResource) => {
  window.open(buildShareUrl(props.spaceId, share), '_blank', 'noopener')
}

const handleRevoke = async (share: AssetShareResource) => {
  const confirmed = await alert.confirm(
    String($t('labels.assetShares.revokeConfirmMessage', { name: share.name })),
    {
      title: String($t('labels.assetShares.revokeConfirmTitle')),
      confirmLabel: String($t('actions.assetShares.revoke')),
      cancelLabel: String($t('alertDialog.cancel')),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    revokeShare(share.id)
  }
}

const handleDelete = async (share: AssetShareResource) => {
  const confirmed = await alert.confirm(
    String($t('labels.assetShares.deleteConfirmMessage', { name: share.name })),
    {
      title: String($t('labels.assetShares.deleteConfirmTitle')),
      confirmLabel: String($t('actions.delete')),
      cancelLabel: String($t('alertDialog.cancel')),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    deleteShare(share.id)
  }
}
</script>

<template>
  <div class="space-y-2">
    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('labels.assetShares.columns.name') }}</TableHead>
            <TableHead>{{ $t('labels.assetShares.columns.source') }}</TableHead>
            <TableHead>{{ $t('labels.assetShares.columns.protected') }}</TableHead>
            <TableHead>{{ $t('labels.assetShares.columns.downloads') }}</TableHead>
            <TableHead>{{ $t('labels.assetShares.columns.views') }}</TableHead>
            <TableHead>{{ $t('labels.assetShares.columns.expires') }}</TableHead>
            <TableHead>{{ $t('labels.assetShares.columns.status') }}</TableHead>
            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="8"
          />
          <template v-else-if="shares?.data?.length">
            <TableRow
              v-for="share in shares.data"
              :key="share.id"
            >
              <TableCell>
                <div class="flex flex-col gap-0.5">
                  <span class="text-sm font-medium">{{ share.name }}</span>
                  <span class="text-xs text-muted-foreground">
                    {{ formatDateTime(share.created_at ?? '') }}
                  </span>
                </div>
              </TableCell>
              <TableCell>
                <Badge type="outline">
                  {{
                    share.source_type === 'selection' && Array.isArray(share.asset_ids)
                      ? $t('labels.assetShares.sources.selectionCount', {
                          count: share.asset_ids.length,
                        })
                      : $t(`labels.assetShares.sources.${share.source_type}`)
                  }}
                </Badge>
              </TableCell>
              <TableCell>
                <Icon
                  :name="share.has_password ? 'lucide:lock' : 'lucide:lock-open'"
                  :class="share.has_password ? 'text-primary' : 'text-muted-foreground'"
                  :title="
                    String(
                      $t(
                        share.has_password
                          ? 'labels.assetShares.passwordProtected'
                          : 'labels.assetShares.noPassword'
                      )
                    )
                  "
                />
              </TableCell>
              <TableCell>
                <span class="text-sm">
                  {{ share.download_count }}<template v-if="share.download_limit"> / {{ share.download_limit }}</template>
                </span>
              </TableCell>
              <TableCell>
                <span class="text-sm">{{ share.view_count }}</span>
              </TableCell>
              <TableCell>
                <span class="text-sm text-muted-foreground">
                  {{ share.expires_at ? formatDateTime(share.expires_at) : '—' }}
                </span>
              </TableCell>
              <TableCell>
                <Badge :variant="statusVariant(share)">
                  {{ $t(`labels.assetShares.status.${shareStatus(share)}`) }}
                </Badge>
              </TableCell>
              <TableCell>
                <div class="flex items-center justify-end gap-1">
                  <Button
                    variant="ghost"
                    size="icon"
                    :title="String($t('actions.copyLink'))"
                    @click="copyShareLink(share)"
                  >
                    <span class="sr-only">{{ $t('actions.copyLink') }}</span>
                    <Icon name="lucide:link" />
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{ $t('labels.assetShares.openMenu') }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem @click="openShare(share)">
                        <Icon name="lucide:external-link" />
                        {{ $t('actions.open') }}
                      </DropdownMenuItem>
                      <DropdownMenuItem @click="copyShareLink(share)">
                        <Icon name="lucide:link" />
                        {{ $t('actions.copyLink') }}
                      </DropdownMenuItem>
                      <template v-if="canManageShares">
                        <DropdownMenuItem @click="openEditDialog(share)">
                          <Icon name="lucide:edit" />
                          {{ $t('actions.edit') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          v-if="!share.is_revoked"
                          class="text-destructive focus:text-destructive"
                          @click="handleRevoke(share)"
                        >
                          <Icon name="lucide:ban" />
                          {{ $t('actions.assetShares.revoke') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          class="text-destructive focus:text-destructive"
                          @click="handleDelete(share)"
                        >
                          <Icon name="lucide:trash-2" />
                          {{ $t('actions.delete') }}
                        </DropdownMenuItem>
                      </template>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </TableCell>
            </TableRow>
          </template>
          <TableEmptyRow
            v-else
            :colspan="8"
            :label="$t('labels.assetShares.noShares')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="shares?.meta"
      :meta="shares.meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(val) => (currentPage = val)"
      @update:per-page="(val) => (perPage = val)"
    />

    <CreateAssetShareDialog
      v-if="canManageShares"
      v-model:open="editDialogOpen"
      :space-id="spaceId"
      :share="editingShare"
    />
  </div>
</template>
