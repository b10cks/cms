<script setup lang="ts">
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
          <div class="flex gap-2 items-center">
            <span>{{ formatDateTime(space.content_updated_at) }}</span>
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
