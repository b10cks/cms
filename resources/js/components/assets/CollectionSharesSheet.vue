<script setup lang="ts">
import AssetsIcon from '~/assets/images/assets.svg?component'
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
import { ScrollArea } from '~/components/ui/scroll-area'
import { Sheet, SheetContent, SheetHeaderCombined } from '~/components/ui/sheet'
import { Skeleton } from '~/components/ui/skeleton'
import type { AssetShareResource, AssetShareSource } from '~/types/asset-distribution'

const props = defineProps<{
  spaceId: string
  collection: AssetCollectionResource | null
}>()

const open = defineModel<boolean>('open', { default: false })

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime } = useFormat()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageShares = computed(() => access.hasAbility('asset_shares.manage'))

const collectionId = computed(() => props.collection?.id ?? null)

const queryParams = computed(() => ({
  source_type: 'collection' as const,
  collection_id: collectionId.value ?? '',
  per_page: 100,
}))

const queryEnabled = computed(() => open.value && Boolean(collectionId.value))

const {
  useAssetSharesQuery,
  useRevokeAssetShareMutation,
  useDeleteAssetShareMutation,
  copyShareLink,
} = useAssetShares(props.spaceId)
const { data: shares, isLoading } = useAssetSharesQuery(queryParams, queryEnabled)
const { mutate: revokeShare } = useRevokeAssetShareMutation()
const { mutate: deleteShare } = useDeleteAssetShareMutation()

const createDialogOpen = ref(false)
const editingShare = ref<AssetShareResource | null>(null)

const collectionSource = computed<AssetShareSource | null>(() =>
  collectionId.value ? { source_type: 'collection', collection_id: collectionId.value } : null
)

const openCreateDialog = () => {
  editingShare.value = null
  createDialogOpen.value = true
}

const openEditDialog = (share: AssetShareResource) => {
  editingShare.value = share
  createDialogOpen.value = true
}

const openShare = (share: AssetShareResource) => {
  window.open(buildShareUrl(props.spaceId, share), '_blank', 'noopener')
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
  <Sheet v-model:open="open">
    <SheetContent class="flex flex-col gap-4 sm:max-w-2xl">
      <SheetHeaderCombined
        :title="String($t('labels.assetShares.manageTitle'))"
        :description="collection?.name ?? String($t('labels.assetShares.manageDescription'))"
      />

      <div
        v-if="canManageShares"
        class="flex justify-end"
      >
        <Button
          variant="primary"
          @click="openCreateDialog"
        >
          <Icon name="lucide:plus" />
          {{ $t('actions.assetShares.newShare') }}
        </Button>
      </div>

      <ScrollArea class="-mx-3 min-h-0 flex-1 px-3">
        <!-- Loading -->
        <div
          v-if="isLoading"
          class="flex flex-col gap-3"
        >
          <Skeleton
            v-for="n in 3"
            :key="n"
            class="h-28 w-full rounded-lg"
          />
        </div>

        <!-- Empty -->
        <div
          v-else-if="!shares?.data?.length"
          class="flex min-h-[240px] flex-col items-center justify-center rounded-lg bg-surface p-8"
        >
          <AssetsIcon class="mb-4 w-24 text-muted" />
          <p class="mb-4 text-center text-muted">
            {{ $t('labels.assetShares.noSharesForCollection') }}
          </p>
          <Button
            v-if="canManageShares"
            variant="primary"
            @click="openCreateDialog"
          >
            <Icon name="lucide:plus" />
            {{ $t('actions.assetShares.newShare') }}
          </Button>
        </div>

        <!-- List -->
        <div
          v-else
          class="flex flex-col gap-3"
        >
          <div
            v-for="share in shares.data"
            :key="share.id"
            class="flex flex-col gap-3 rounded-lg border border-input bg-surface p-4"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="flex min-w-0 flex-col gap-1">
                <div class="flex items-center gap-2">
                  <span class="truncate text-sm font-semibold text-primary">{{ share.name }}</span>
                  <Badge :variant="statusVariant(share)">
                    {{ $t(`labels.assetShares.status.${shareStatus(share)}`) }}
                  </Badge>
                </div>
                <span class="text-xs text-muted-foreground">
                  {{ formatDateTime(share.created_at ?? '') }}
                </span>
              </div>

              <div class="flex shrink-0 items-center gap-1">
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
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
              <span class="flex items-center gap-1">
                <Icon
                  :name="share.has_password ? 'lucide:lock' : 'lucide:lock-open'"
                  :class="share.has_password ? 'text-primary' : 'text-muted-foreground'"
                />
                {{
                  $t(
                    share.has_password
                      ? 'labels.assetShares.passwordProtected'
                      : 'labels.assetShares.noPassword'
                  )
                }}
              </span>
              <span class="flex items-center gap-1">
                <Icon name="lucide:download" />
                {{ share.download_count
                }}<template v-if="share.download_limit"> / {{ share.download_limit }}</template>
              </span>
              <span class="flex items-center gap-1">
                <Icon name="lucide:eye" />
                {{ share.view_count }}
              </span>
              <span
                v-if="share.expires_at"
                class="flex items-center gap-1"
              >
                <Icon name="lucide:calendar-clock" />
                {{ formatDateTime(share.expires_at) }}
              </span>
            </div>
          </div>
        </div>
      </ScrollArea>
    </SheetContent>
  </Sheet>

  <CreateAssetShareDialog
    v-if="canManageShares"
    v-model:open="createDialogOpen"
    :space-id="spaceId"
    :share="editingShare"
    :source="editingShare ? null : collectionSource"
  />
</template>
