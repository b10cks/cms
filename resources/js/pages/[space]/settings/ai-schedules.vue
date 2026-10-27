<script setup lang="ts">
import AiSchedulesSettings from '~/components/ai-settings/AiSchedulesSettings.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('components.aiSettings.schedules.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader :header="$t('components.aiSettings.schedules.title')" />
    <div class="divide-y divide-border pt-6">
      <AiSchedulesSettings :space-id="spaceId" />
    </div>
  </div>
</template>
