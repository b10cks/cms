<script setup lang="ts">
import RedirectsTable from '~/components/redirects/RedirectsTable.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('labels.redirects.title')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.redirects.title')"
        :description="$t('labels.redirects.description')"
      />
      <RedirectsTable :space-id="spaceId" />
    </div>
  </div>
</template>
