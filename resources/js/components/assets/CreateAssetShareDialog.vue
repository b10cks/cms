<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { DateTimeField, InputField, Label, TextField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { Spinner } from '~/components/ui/spinner'
import { Switch } from '~/components/ui/switch'
import type {
  AssetShareResource,
  AssetShareSource,
  AssetShareSourceType,
  CreateAssetSharePayload,
  UpdateAssetSharePayload,
} from '~/types/asset-distribution'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  spaceId: string
  /** Present in edit mode. */
  share?: AssetShareResource | null
  /** Required in create mode. */
  source?: AssetShareSource | null
}>()

const emit = defineEmits<{
  created: [share: AssetShareResource]
  updated: [share: AssetShareResource]
}>()

const { $t } = useI18n()
const { useCreateAssetShareMutation, useUpdateAssetShareMutation } = useAssetShares(props.spaceId)
const { mutateAsync: createShare, isPending: isCreating } = useCreateAssetShareMutation()
const { mutateAsync: updateShare, isPending: isUpdating } = useUpdateAssetShareMutation()

const isEditing = computed(() => Boolean(props.share?.id))
const isPending = computed(() => isCreating.value || isUpdating.value)

const name = ref('')
const description = ref('')
const password = ref('')
const clearPassword = ref(false)
const expiresAt = ref('')
const downloadLimit = ref('')
const allowIndividualDownloads = ref(true)
const createdShare = ref<AssetShareResource | null>(null)

const toDateTimeLocal = (iso: string | null | undefined): string => {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

watch(open, (isOpen) => {
  if (!isOpen) return
  createdShare.value = null
  name.value = props.share?.name ?? ''
  description.value = props.share?.description ?? ''
  password.value = ''
  clearPassword.value = false
  expiresAt.value = toDateTimeLocal(props.share?.expires_at)
  downloadLimit.value =
    props.share?.download_limit != null ? String(props.share.download_limit) : ''
  allowIndividualDownloads.value = props.share?.allow_individual_downloads ?? true
})

const sourceType = computed<AssetShareSourceType | null>(
  () => props.share?.source_type ?? props.source?.source_type ?? null
)

const sourceCount = computed(() => {
  const ids = props.share?.asset_ids ?? props.source?.asset_ids
  return Array.isArray(ids) ? ids.length : null
})

const shareUrl = computed(() =>
  createdShare.value ? buildShareUrl(props.spaceId, createdShare.value) : null
)

const canSubmit = computed(() => {
  if (!name.value.trim() || isPending.value) return false
  return isEditing.value || Boolean(props.source)
})

const parsedDownloadLimit = computed<number | null>(() => {
  const raw = downloadLimit.value.trim()
  if (!raw) return null
  const value = Number(raw)
  return Number.isInteger(value) && value > 0 ? value : null
})

const toIsoOrNull = (value: string): string | null => {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date.toISOString()
}

const copyShareUrl = async () => {
  if (!shareUrl.value) return
  await navigator.clipboard.writeText(shareUrl.value)
  toast.success(String($t('composables.assetShares.linkCopied')))
}

const handleSubmit = async () => {
  if (!canSubmit.value) return

  if (isEditing.value && props.share) {
    const payload: UpdateAssetSharePayload = {
      name: name.value.trim(),
      description: description.value.trim() || null,
      expires_at: toIsoOrNull(expiresAt.value),
      download_limit: parsedDownloadLimit.value,
      allow_individual_downloads: allowIndividualDownloads.value,
    }

    // Password semantics: absent key = keep, null = clear, value = set.
    if (clearPassword.value) {
      payload.password = null
    } else if (password.value.trim()) {
      payload.password = password.value
    }

    const updated = await updateShare({ id: props.share.id, payload })
    emit('updated', updated)
    open.value = false
    return
  }

  if (!props.source) return

  const payload: CreateAssetSharePayload = {
    name: name.value.trim(),
    description: description.value.trim() || null,
    source_type: props.source.source_type,
    collection_id: props.source.collection_id ?? undefined,
    folder_id: props.source.folder_id ?? undefined,
    asset_ids: props.source.asset_ids ?? undefined,
    password: password.value.trim() || undefined,
    expires_at: toIsoOrNull(expiresAt.value) ?? undefined,
    download_limit: parsedDownloadLimit.value ?? undefined,
    allow_individual_downloads: allowIndividualDownloads.value,
  }

  const created = await createShare(payload)
  createdShare.value = created
  emit('created', created)
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <template v-if="createdShare">
        <DialogHeaderCombined
          :title="$t('labels.assetShares.createdTitle')"
          :description="$t('labels.assetShares.createdDescription')"
        />

        <div class="flex flex-col gap-3">
          <div class="flex items-center gap-2">
            <Input
              :model-value="shareUrl ?? ''"
              readonly
              class="flex-1"
              @focus="($event.target as HTMLInputElement).select()"
            />
            <Button
              variant="primary"
              @click="copyShareUrl"
            >
              <Icon name="lucide:copy" />
              {{ $t('actions.copyLink') }}
            </Button>
          </div>
          <p
            v-if="createdShare.has_password"
            class="text-sm text-muted"
          >
            {{ $t('labels.assetShares.createdPasswordHint') }}
          </p>
        </div>

        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.close') }}
          </Button>
        </DialogFooter>
      </template>

      <template v-else>
        <DialogHeaderCombined
          :title="
            $t(isEditing ? 'labels.assetShares.editTitle' : 'labels.assetShares.createTitle')
          "
          :description="$t('labels.assetShares.dialogDescription')"
        />

        <div class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto px-1">
          <div
            v-if="sourceType"
            class="flex items-center gap-2 rounded-md bg-surface px-3 py-2 text-sm text-muted"
          >
            <Icon
              :name="
                sourceType === 'collection'
                  ? 'lucide:layers'
                  : sourceType === 'folder'
                    ? 'lucide:folder'
                    : 'lucide:images'
              "
            />
            <span>
              {{
                sourceCount !== null
                  ? $t(`labels.assetShares.sources.${sourceType}Count`, { count: sourceCount })
                  : $t(`labels.assetShares.sources.${sourceType}`)
              }}
            </span>
          </div>

          <InputField
            v-model="name"
            name="share_name"
            :label="$t('labels.assetShares.fields.name')"
            :placeholder="$t('labels.assetShares.fields.namePlaceholder')"
            required
          />

          <TextField
            v-model="description"
            name="share_description"
            :label="$t('labels.assetShares.fields.description')"
            :placeholder="$t('labels.assetShares.fields.descriptionPlaceholder')"
            :rows="2"
          />

          <div class="flex flex-col gap-2">
            <InputField
              v-model="password"
              name="share_password"
              type="password"
              autocomplete="new-password"
              :label="$t('labels.assetShares.fields.password')"
              :disabled="clearPassword"
              :placeholder="
                isEditing && share?.has_password
                  ? $t('labels.assetShares.fields.passwordKeepPlaceholder')
                  : $t('labels.assetShares.fields.passwordPlaceholder')
              "
              :description="
                isEditing && share?.has_password
                  ? $t('labels.assetShares.fields.passwordKeepHint')
                  : $t('labels.assetShares.fields.passwordHint')
              "
            />
            <label
              v-if="isEditing && share?.has_password"
              class="flex cursor-pointer items-center gap-2 text-sm"
            >
              <Switch v-model="clearPassword" />
              {{ $t('labels.assetShares.fields.removePassword') }}
            </label>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <DateTimeField
              v-model="expiresAt"
              name="share_expires_at"
              type="datetime-local"
              :label="$t('labels.assetShares.fields.expiresAt')"
              :description="$t('labels.assetShares.fields.expiresAtHint')"
            />

            <InputField
              v-model="downloadLimit"
              name="share_download_limit"
              type="number"
              min="1"
              :label="$t('labels.assetShares.fields.downloadLimit')"
              :placeholder="$t('labels.assetShares.fields.downloadLimitPlaceholder')"
              :description="$t('labels.assetShares.fields.downloadLimitHint')"
            />
          </div>

          <div class="flex items-center justify-between gap-4 rounded-md bg-surface px-3 py-2">
            <div class="flex flex-col">
              <Label :label="String($t('labels.assetShares.fields.allowIndividualDownloads'))" />
              <span class="text-xs text-muted">
                {{ $t('labels.assetShares.fields.allowIndividualDownloadsHint') }}
              </span>
            </div>
            <Switch v-model="allowIndividualDownloads" />
          </div>
        </div>

        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            :disabled="!canSubmit"
            @click="handleSubmit"
          >
            <Spinner v-if="isPending" />
            {{ $t(isEditing ? 'actions.save' : 'actions.assetShares.create') }}
          </Button>
        </DialogFooter>
      </template>
    </DialogContent>
  </Dialog>
</template>
