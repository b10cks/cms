<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import TeamHierarchyTree from '~/components/teams/TeamHierarchyTree.vue'
import TeamSelector from '~/components/TeamSelector.vue'

const { t } = useI18n()
const route = useRoute()

useSeoMeta({
  title: computed(() => t('labels.teams.pageTitle')),
})

const activeTeamId = computed(() => route.params.team as string | undefined)
</script>

<template>
  <AppHeader>
    <div class="flex items-start">
      <TeamSelector size="sm" />
    </div>
  </AppHeader>
  <div class="flex w-full grow bg-background pt-14">
    <aside class="w-sm shrink-0 border-r border-border bg-surface">
      <TeamHierarchyTree
        :title="$t('labels.teams.hierarchyTitle')"
        :selected-team-id="activeTeamId"
      />
    </aside>
    <main class="content-grid mx-auto">
      <RouterView />
    </main>
  </div>
</template>
