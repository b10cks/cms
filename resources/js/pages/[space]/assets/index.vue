<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'

import AssetManager from '~/components/AssetManager.vue'

const route = useRoute()
const { t } = useI18n()

const spaceId = computed<string>(() => route.params.space as string)
const folderId = useRouteQuery('folder', undefined) as Ref<string | undefined>
const tagId = useRouteQuery('tag', undefined) as Ref<string | undefined>
const assetId = useRouteQuery('asset', undefined) as Ref<string | undefined>

useSeoMeta({
  title: computed(() => t('labels.assets.pageTitle')),
})
</script>

<template>
  <AssetManager
    v-model:folder-id="folderId"
    v-model:tag-id="tagId"
    v-model:asset-id="assetId"
    :space-id="spaceId"
  />
</template>
