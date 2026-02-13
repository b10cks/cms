<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'
import AssetManager from '~/components/AssetManager.vue'

const route = useRoute()
const { t } = useI18n()

const spaceId = computed<string>(() => route.params.space as string)
const folderId = useRouteQuery('folder', undefined) as Ref<string | undefined>

useSeoMeta({
  title: computed(() => t('labels.assets.pageTitle')),
})
</script>

<template>
  <AssetManager
    v-model:folder-id="folderId"
    :space-id="spaceId"
  />
</template>
