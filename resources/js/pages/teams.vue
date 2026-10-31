<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import TeamSelector from '~/components/TeamSelector.vue'

const { t } = useI18n()

useSeoMeta({
  title: computed(() => t('labels.teams.pageTitle')),
})
</script>

<template>
  <AppHeader>
    <div class="flex items-start">
      <TeamSelector size="sm" />
    </div>
  </AppHeader>
  <main class="flex w-full grow bg-background pt-14">
    <RouterView />
  </main>
</template>
