<script setup lang="ts">
import IconManager from '~/components/icons/IconManager.vue'

const route = useRoute()
const { t } = useI18n()

const spaceId = computed<string>(() => route.params.space as string)

useSeoMeta({
  title: computed(() => t('labels.icons.pageTitle')),
})
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <IconManager :space-id="spaceId" />
    </div>
  </div>
</template>
