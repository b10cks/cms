<script setup lang="ts">
import AccssTokenSettings from '~/components/space-settings/AccssTokenSettings.vue'
import AiSettings from '~/components/space-settings/AiSettings.vue'
import AssetLibrarySettings from '~/components/space-settings/AssetLibrarySettings.vue'
import EditorSettings from '~/components/space-settings/EditorSettings.vue'
import I18nSettings from '~/components/space-settings/I18nSettings.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)

useSeoMeta({
  title: computed(() => t('labels.settings.configuration.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.settings.configuration.title')"
      :description="$t('labels.settings.configuration.description')"
    />
    <EditorSettings
      v-if="space"
      :space="space"
    />
    <I18nSettings
      v-if="space"
      :space="space"
    />
    <AccssTokenSettings
      v-if="space"
      :space="space"
    />
    <AssetLibrarySettings
      v-if="space"
      :space="space"
    />
    <AiSettings
      v-if="space"
      :space="space"
    />
  </div>
</template>
