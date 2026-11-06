<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'

import { displayHost, type MetaPreviewData, type SocialPlatform } from './types'

/** The link card a platform shows for the entry, in that platform's layout, drawn with the app's tokens. */
const props = defineProps<{
  data: MetaPreviewData
  platform: SocialPlatform
}>()

const host = computed(() => displayHost(props.data.url))
</script>

<template>
  <div class="w-full max-w-[500px] min-w-0 text-left">
    <div
      v-if="platform === 'facebook' || platform === 'linkedin'"
      class="overflow-hidden rounded-lg bg-card ring-1 ring-border"
    >
      <NuxtImg
        v-if="data.image"
        :src="data.image"
        alt=""
        :width="1000"
        :height="524"
        :modifiers="{ crop: 'fill' }"
        class="aspect-[1.91] w-full object-cover"
      />
      <div
        v-else
        class="flex aspect-[1.91] w-full flex-col items-center justify-center gap-2 bg-chip text-input-muted"
      >
        <Icon
          name="lucide:image-off"
          size="24"
        />
        <span class="text-sm">{{ $t('components.metaPreview.noImage') }}</span>
      </div>
      <div :class="['grid gap-0.5 px-3 py-2.5', platform === 'facebook' && 'bg-surface']">
        <p
          v-if="platform === 'facebook'"
          class="truncate text-sm text-muted uppercase"
        >
          {{ host }}
        </p>
        <p class="line-clamp-2 font-semibold text-primary">{{ data.shareTitle }}</p>
        <p
          v-if="platform === 'facebook' && data.shareDescription"
          class="truncate text-sm text-muted"
        >
          {{ data.shareDescription }}
        </p>
        <p
          v-if="platform === 'linkedin'"
          class="truncate text-sm text-muted"
        >
          {{ host }}
        </p>
      </div>
    </div>

    <div
      v-else-if="platform === 'x'"
      class="grid gap-1"
    >
      <div class="relative overflow-hidden rounded-2xl ring-1 ring-border">
        <NuxtImg
          v-if="data.image"
          :src="data.image"
          alt=""
          :width="1000"
          :height="524"
          :modifiers="{ crop: 'fill' }"
          class="aspect-[1.91] w-full object-cover"
        />
        <div
          v-else
          class="flex aspect-[1.91] w-full items-center justify-center bg-chip text-input-muted"
        >
          <Icon
            name="lucide:image-off"
            size="24"
          />
        </div>
        <span
          class="absolute bottom-3 left-3 max-w-[calc(100%-1.5rem)] truncate rounded-md bg-black/70 px-1.5 py-0.5 text-sm text-white"
        >
          {{ data.shareTitle }}
        </span>
      </div>
      <p class="truncate text-sm text-muted">{{ $t('components.metaPreview.fromHost', { host }) }}</p>
    </div>

    <div
      v-else-if="platform === 'slack'"
      class="grid gap-1 border-l-4 border-border-strong pl-3"
    >
      <p class="flex min-w-0 items-center gap-1.5 text-sm font-semibold text-primary">
        <NuxtImg
          v-if="data.siteIcon"
          :src="data.siteIcon"
          alt=""
          :width="16"
          :height="16"
          class="size-4 rounded-sm object-cover"
        />
        <span class="truncate">{{ data.siteName }}</span>
      </p>
      <p class="font-semibold text-info-ink">{{ data.shareTitle }}</p>
      <p
        v-if="data.shareDescription"
        class="line-clamp-3 text-sm text-primary"
      >
        {{ data.shareDescription }}
      </p>
      <NuxtImg
        v-if="data.image"
        :src="data.image"
        alt=""
        :width="720"
        :height="377"
        :modifiers="{ crop: 'fill' }"
        class="mt-1 aspect-[1.91] w-full max-w-90 rounded-lg object-cover"
      />
    </div>

    <div
      v-else
      class="ml-auto w-fit max-w-[340px] rounded-xl rounded-tr-sm bg-success/15 p-1 ring-1 ring-success/30"
    >
      <div class="overflow-hidden rounded-lg bg-card/70">
        <NuxtImg
          v-if="data.image"
          :src="data.image"
          alt=""
          :width="680"
          :height="356"
          :modifiers="{ crop: 'fill' }"
          class="aspect-[1.91] w-full object-cover"
        />
        <div class="grid gap-0.5 px-2.5 py-2">
          <p class="line-clamp-2 text-sm font-semibold text-primary">{{ data.shareTitle }}</p>
          <p
            v-if="data.shareDescription"
            class="line-clamp-2 text-sm text-muted"
          >
            {{ data.shareDescription }}
          </p>
          <p class="truncate text-sm text-muted">{{ host }}</p>
        </div>
      </div>
      <p class="truncate px-1.5 pt-1 pb-0.5 text-sm text-info-ink">{{ data.url?.href }}</p>
    </div>

    <p
      v-if="data.shareTitleSource === 'search'"
      class="mt-2 flex items-center gap-1.5 text-sm text-muted"
    >
      <Icon name="lucide:corner-down-right" />
      {{ $t('components.metaPreview.borrowsSearchTitle') }}
    </p>
  </div>
</template>
