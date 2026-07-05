<script setup lang="ts">
import AssetSharesTable from '~/components/assets/AssetSharesTable.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string

useSeoMeta({
  title: computed(() => t('labels.assetShares.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.assetShares.title')"
      :description="$t('labels.assetShares.description')"
    />

    <AssetSharesTable :space-id="spaceId" />
  </div>
</template>
