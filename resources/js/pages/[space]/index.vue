<script setup lang="ts">
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import SpaceDashboard from '~/components/SpaceDashboard.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const { useCurrentSpaceQuery } = useSpaces()
const { t } = useI18n()
const { data: space } = useCurrentSpaceQuery()
const { formatDateTime } = useFormat()

useSeoMeta({
  title: computed(() => t('dashboard.title')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid pb-6">
      <div v-if="space">
        <ContentHeader :header="space.name">
          <template #before-header>
            <NuxtImg
              v-if="space.icon"
              :src="space.icon"
              :alt="space.name"
              :width="80"
              :height="80"
              class="size-10 rounded-md object-cover"
            />
          </template>
          <div class="flex items-center gap-2">
            <span>
              {{ formatDateTime(space.updated_at ?? space.content_updated_at) }}
            </span>
            <SpaceBadge
              v-if="space.badge"
              :badge="space.badge"
              size="xs"
            />
          </div>
        </ContentHeader>
        <SpaceDashboard :space-id="space.id" />
      </div>
    </div>
  </div>
</template>
