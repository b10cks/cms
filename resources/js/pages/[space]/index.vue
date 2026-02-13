<script setup lang="ts">
import SpaceDashboard from '~/components/SpaceDashboard.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const { useCurrentSpaceQuery } = useSpaces()
const { t } = useI18n()
const { data: currentSpace } = useCurrentSpaceQuery()
const { formatDateTime } = useFormat()

useSeoMeta({
  title: computed(() => t('dashboard.title')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid pb-6">
      <div v-if="currentSpace">
        <ContentHeader
          :header="currentSpace.name"
          :description="formatDateTime(currentSpace.updated_at)"
        />
        <SpaceDashboard :space-id="currentSpace.id" />
      </div>
    </div>
  </div>
</template>
