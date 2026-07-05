<script setup lang="ts">
import AssetsIcon from '~/assets/images/assets.svg?component'
import Logo from '~/assets/logo.svg'
import Icon from '~/components/Icon.vue'
import Markdown from '~/components/Markdown.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Progress } from '~/components/ui/progress'
import { Skeleton } from '~/components/ui/skeleton'
import { Spinner } from '~/components/ui/spinner'
import type { PublicShareAsset } from '~/types/asset-distribution'

const route = useRoute()
const { t, $t } = useI18n()
const { formatFileSize, formatDateTime } = useFormat()
const { getFileType } = useFileUtils()

const spaceId = computed(() => route.params.space as string)
const token = computed(() => route.params.token as string)

const { useShareQuery, useShareAssetsQuery, useUnlockMutation, downloadAll, downloadAsset } =
  usePublicShare(spaceId, token)

const { data: share, isPending: isLoadingShare, error: shareError } = useShareQuery()
const { mutateAsync: unlock, isPending: isUnlocking } = useUnlockMutation()

const isNotFound = computed(() => (shareError.value as any)?.status === 404)
const isLocked = computed(() => Boolean(share.value?.protected && !share.value?.unlocked))
const isUnlocked = computed(() => Boolean(share.value?.unlocked))

const {
  data: assetPages,
  isPending: isLoadingAssets,
  hasNextPage,
  isFetchingNextPage,
  fetchNextPage,
} = useShareAssetsQuery(48, isUnlocked)

const assets = computed<PublicShareAsset[]>(
  () => assetPages.value?.pages.flatMap((page) => page.data) ?? []
)

const isEmpty = computed(() => !isLoadingAssets.value && assets.value.length === 0)

useSeoMeta({
  title: computed(() => share.value?.name ?? (t('labels.publicShare.pageTitle') as string)),
})

const accentColor = computed(() => {
  const value = share.value?.settings?.accent_color
  return typeof value === 'string' && value ? value : null
})

/* Password gate ----------------------------------------------------- */

const password = ref('')
const unlockError = ref<string | null>(null)

const handleUnlock = async () => {
  if (!password.value || isUnlocking.value) return
  unlockError.value = null

  try {
    await unlock(password.value)
    password.value = ''
  } catch (error: any) {
    unlockError.value =
      error?.status === 403
        ? String($t('labels.publicShare.wrongPassword'))
        : String($t('labels.publicShare.unlockFailed'))
  }
}

/* Downloads ---------------------------------------------------------- */

const isDownloadingAll = ref(false)
const buildProgress = ref<number | null>(null)
const downloadError = ref<string | null>(null)
const downloadingAssetIds = ref(new Set<string>())

const isLimitReached = computed(() => {
  const limit = share.value?.download_limit
  return limit != null && (share.value?.download_count ?? 0) >= limit
})

const triggerUrlDownload = (url: string) => {
  const link = document.createElement('a')
  link.href = url
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
}

const handleDownloadAll = async () => {
  if (isDownloadingAll.value) return
  isDownloadingAll.value = true
  buildProgress.value = null
  downloadError.value = null

  try {
    const { url } = await downloadAll((progress) => {
      buildProgress.value = progress
    })
    triggerUrlDownload(url)
  } catch (error: any) {
    downloadError.value =
      error?.status === 403
        ? String($t('labels.publicShare.downloadLimitReached'))
        : String($t('labels.publicShare.downloadFailed'))
  } finally {
    isDownloadingAll.value = false
    buildProgress.value = null
  }
}

const handleDownloadAsset = async (asset: PublicShareAsset) => {
  if (downloadingAssetIds.value.has(asset.id)) return
  downloadingAssetIds.value.add(asset.id)
  downloadError.value = null

  try {
    const { url } = await downloadAsset(asset.id)
    triggerUrlDownload(url)
  } catch {
    downloadError.value = String($t('labels.publicShare.downloadFailed'))
  } finally {
    downloadingAssetIds.value.delete(asset.id)
  }
}

/* Presentation helpers ----------------------------------------------- */

const assetDisplayName = (asset: PublicShareAsset) =>
  asset.extension ? `${asset.filename}.${asset.extension}` : asset.filename

const assetTypeIcon = (asset: PublicShareAsset): string => {
  switch (getFileType(asset.mime_type)) {
    case 'image':
      return 'lucide:image'
    case 'video':
      return 'lucide:video'
    case 'audio':
      return 'lucide:music'
    case 'document':
      return 'lucide:file-text'
    default:
      return 'lucide:file'
  }
}

const isImage = (asset: PublicShareAsset) => getFileType(asset.mime_type) === 'image'

// Images use the bounded, access-controlled share preview endpoint (never an
// ilum URL — that would outlive revocation/expiry). Non-image assets expose a
// generated thumbnail on public storage, which we size down through ilum.
const thumbnailPath = (asset: PublicShareAsset): string | null =>
  asset.metadata.thumbnails?.[0]?.full_path ?? null

const hasVisual = (asset: PublicShareAsset): boolean =>
  Boolean((isImage(asset) && asset.preview_url) || thumbnailPath(asset))

// Per-thumbnail loading placeholder: the skeleton shows until the <img> fires
// load/error, so it never lingers behind transparent images.
const loadedAssetIds = ref(new Set<string>())
const markLoaded = (asset: PublicShareAsset) => loadedAssetIds.value.add(asset.id)
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 py-10 sm:px-8">
    <!-- Not found / expired -->
    <div
      v-if="isNotFound"
      class="flex flex-1 flex-col items-center justify-center gap-4 text-center"
    >
      <Icon
        name="lucide:link-2-off"
        class="size-12 text-muted"
      />
      <h1 class="text-2xl font-bold text-primary">
        {{ $t('labels.publicShare.notFoundTitle') }}
      </h1>
      <p class="max-w-md text-muted">
        {{ $t('labels.publicShare.notFoundDescription') }}
      </p>
    </div>

    <!-- Loading -->
    <div
      v-else-if="isLoadingShare"
      class="flex flex-1 items-center justify-center"
    >
      <Spinner class="size-8 text-muted" />
    </div>

    <!-- Password gate -->
    <div
      v-else-if="isLocked"
      class="flex flex-1 flex-col items-center justify-center gap-6"
    >
      <div class="flex w-full max-w-sm flex-col gap-4 rounded-lg bg-surface p-6 shadow">
        <div class="flex flex-col items-center gap-2 text-center">
          <Icon
            name="lucide:lock"
            class="size-8 text-muted"
            :style="accentColor ? { color: accentColor } : undefined"
          />
          <h1 class="text-xl font-bold text-primary">{{ share?.name }}</h1>
          <p class="text-sm text-muted">
            {{ $t('labels.publicShare.passwordPrompt') }}
          </p>
        </div>

        <form
          class="flex flex-col gap-3"
          @submit.prevent="handleUnlock"
        >
          <Input
            v-model="password"
            type="password"
            autocomplete="off"
            :placeholder="String($t('labels.publicShare.passwordPlaceholder'))"
            autofocus
          />
          <p
            v-if="unlockError"
            class="text-sm text-destructive"
          >
            {{ unlockError }}
          </p>
          <Button
            type="submit"
            variant="primary"
            :disabled="!password || isUnlocking"
            :style="accentColor ? { backgroundColor: accentColor } : undefined"
          >
            <Spinner v-if="isUnlocking" />
            {{ $t('labels.publicShare.unlock') }}
          </Button>
        </form>
      </div>
    </div>

    <!-- Unlocked share -->
    <template v-else-if="share">
      <header
        class="mb-8 flex flex-col gap-4 border-b border-border pb-6 sm:flex-row sm:items-end sm:justify-between"
      >
        <div class="flex flex-col gap-2">
          <div class="flex items-center gap-3">
            <span
              class="flex size-10 items-center justify-center rounded-md bg-surface"
              :style="accentColor ? { backgroundColor: accentColor } : undefined"
            >
              <Icon
                name="lucide:folder-down"
                :class="accentColor ? 'text-white' : 'text-primary'"
              />
            </span>
            <h1 class="text-2xl font-bold text-primary">{{ share.name }}</h1>
          </div>
          <p
            v-if="share.description"
            class="max-w-2xl text-muted"
          >
            {{ share.description }}
          </p>
          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
            <span v-if="share.asset_count != null">
              {{ $t('labels.publicShare.assetCount', { count: share.asset_count }) }}
            </span>
            <span v-if="share.expires_at">
              {{ $t('labels.publicShare.expiresAt', { date: formatDateTime(share.expires_at) }) }}
            </span>
            <span v-if="share.download_limit != null">
              {{
                $t('labels.publicShare.downloadsRemaining', {
                  count: Math.max(0, share.download_limit - (share.download_count ?? 0)),
                })
              }}
            </span>
          </div>
        </div>

        <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
          <Button
            variant="primary"
            size="lg"
            :disabled="isDownloadingAll || isLimitReached || isLoadingAssets || isEmpty"
            :style="accentColor ? { backgroundColor: accentColor } : undefined"
            @click="handleDownloadAll"
          >
            <Spinner v-if="isDownloadingAll" />
            <Icon
              v-else
              name="lucide:download"
            />
            {{ $t('labels.publicShare.downloadAll') }}
          </Button>
          <div
            v-if="isDownloadingAll"
            class="flex w-full min-w-48 items-center gap-2"
          >
            <Progress
              :model-value="buildProgress ?? 0"
              class="h-1.5 flex-1"
            />
            <span class="text-xs whitespace-nowrap text-muted">
              {{
                buildProgress != null
                  ? $t('labels.publicShare.building', { progress: buildProgress })
                  : $t('labels.publicShare.preparing')
              }}
            </span>
          </div>
          <p
            v-if="isLimitReached"
            class="text-xs text-destructive"
          >
            {{ $t('labels.publicShare.downloadLimitReached') }}
          </p>
          <p
            v-else-if="downloadError"
            class="text-xs text-destructive"
          >
            {{ downloadError }}
          </p>
        </div>
      </header>

      <main class="flex-1">
        <div
          v-if="isLoadingAssets"
          class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
          aria-hidden="true"
        >
          <div
            v-for="n in 8"
            :key="n"
            class="flex flex-col overflow-hidden rounded-lg border border-border bg-surface"
          >
            <Skeleton class="aspect-square w-full rounded-none" />
            <div class="flex flex-col gap-1.5 p-3">
              <Skeleton class="h-4 w-3/4" />
              <Skeleton class="h-3 w-1/3" />
            </div>
          </div>
        </div>

        <div
          v-else-if="isEmpty"
          class="flex min-h-[240px] flex-col items-center justify-center rounded-lg bg-surface p-8"
        >
          <AssetsIcon class="mb-4 w-32 text-muted" />
          <p class="text-center text-muted">
            {{ $t('labels.publicShare.empty') }}
          </p>
        </div>

        <div
          v-else
          class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
        >
          <div
            v-for="asset in assets"
            :key="asset.id"
            class="group flex flex-col overflow-hidden rounded-lg border border-border bg-surface"
          >
            <div
              class="relative flex aspect-square items-center justify-center overflow-hidden bg-background"
              :style="
                asset.metadata.dominant_color
                  ? { backgroundColor: asset.metadata.dominant_color }
                  : undefined
              "
            >
              <Skeleton
                v-if="hasVisual(asset) && !loadedAssetIds.has(asset.id)"
                class="absolute inset-0 rounded-none"
              />
              <img
                v-if="isImage(asset) && asset.preview_url"
                :src="asset.preview_url"
                :alt="assetDisplayName(asset)"
                loading="lazy"
                class="relative h-full w-full object-cover"
                @load="markLoaded(asset)"
                @error="markLoaded(asset)"
              >
              <NuxtImg
                v-else-if="thumbnailPath(asset)"
                :src="thumbnailPath(asset)!"
                :alt="assetDisplayName(asset)"
                :width="480"
                :height="480"
                :modifiers="{ crop: 'fill' }"
                class="relative h-full w-full object-cover"
                @load="markLoaded(asset)"
                @error="markLoaded(asset)"
              />
              <Icon
                v-else
                :name="assetTypeIcon(asset)"
                class="size-10 text-muted"
              />
            </div>
            <div class="flex items-center gap-2 p-3">
              <div class="min-w-0 flex-1">
                <p
                  class="truncate text-sm font-semibold text-primary"
                  :title="assetDisplayName(asset)"
                >
                  {{ assetDisplayName(asset) }}
                </p>
                <p class="text-xs text-muted">{{ formatFileSize(asset.size) }}</p>
              </div>
              <Button
                v-if="share.allow_individual_downloads"
                variant="ghost"
                size="icon"
                :disabled="downloadingAssetIds.has(asset.id)"
                :aria-label="String($t('labels.publicShare.downloadAsset'))"
                @click="handleDownloadAsset(asset)"
              >
                <Spinner v-if="downloadingAssetIds.has(asset.id)" />
                <Icon
                  v-else
                  name="lucide:download"
                />
              </Button>
            </div>
          </div>
        </div>

        <div
          v-if="hasNextPage"
          class="mt-6 flex justify-center"
        >
          <Button
            :disabled="isFetchingNextPage"
            @click="fetchNextPage()"
          >
            <Spinner v-if="isFetchingNextPage" />
            {{ $t('actions.loadMore') }}
          </Button>
        </div>
      </main>

      <footer
        class="mt-10 flex flex-col items-center gap-3 border-t border-border pt-6 text-center text-xs text-muted sm:flex-row sm:justify-between sm:text-left"
      >
        <div class="flex items-center gap-2">
          <Logo
            alt="b10cks logo"
            class="h-5 w-5 text-primary"
          />
          <span>{{ $t('labels.publicShare.poweredBy') }}</span>
        </div>
        <Markdown
          class="text-xs text-muted [&_a]:underline"
          :content="$t('labels.login.terms')"
        />
      </footer>
    </template>
  </div>
</template>
