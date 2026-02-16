<script setup lang="ts">
import AiSettings from '~/components/space-settings/AiSettings.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)

useSeoMeta({
  title: computed(() => t('labels.settings.ai.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.ai.title')"
      :description="$t('labels.settings.ai.description')"
    />
    <AiSettings
      v-if="space"
      :space="space"
    />
  </div>
</template>
