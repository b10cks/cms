<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'

import SerpPreview from './SerpPreview.vue'
import SocialPreview from './SocialPreview.vue'
import { PLATFORMS, type MetaPreviewData, type Platform } from './types'

/** How the entry shows up where people find and share it, one platform at a time on a stage. */
defineProps<{ data: MetaPreviewData }>()

// Per viewer: the platform someone checks most stays picked across entries.
const platform = useLocalStorage<Platform>('b10cks:meta-preview-platform', 'google')

const currentPlatform = computed(
  () => PLATFORMS.find((entry) => entry.id === platform.value) ?? PLATFORMS[0]
)
</script>

<template>
  <div
    data-meta-preview
    class="overflow-hidden rounded-xl border border-border bg-card"
  >
    <Tabs v-model="platform">
      <div class="flex items-center gap-2 border-b border-border p-2">
        <TabsList>
          <TabsTrigger
            v-for="entry in PLATFORMS"
            :key="entry.id"
            :value="entry.id"
            :aria-label="entry.label"
            :title="entry.label"
          >
            <Icon :name="entry.icon" />
          </TabsTrigger>
        </TabsList>
        <span class="ml-auto truncate pr-1 text-sm text-muted">{{ currentPlatform.label }}</span>
      </div>
    </Tabs>
    <div
      class="flex min-h-56 items-center justify-center bg-surface px-6 py-8"
      role="figure"
      :aria-label="$t('components.metaPreview.previewOn', { platform: currentPlatform.label })"
    >
      <SerpPreview
        v-if="currentPlatform.id === 'google'"
        :data="data"
        class="w-full"
      />
      <SocialPreview
        v-else
        :data="data"
        :platform="currentPlatform.id"
      />
    </div>
  </div>
</template>
