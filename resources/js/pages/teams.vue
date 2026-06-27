<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import TeamHierarchyTree from '~/components/teams/TeamHierarchyTree.vue'
import TeamSelector from '~/components/TeamSelector.vue'

const { t } = useI18n()
const router = useRouter()

useSeoMeta({
  title: computed(() => t('labels.teams.pageTitle')),
})

const handleHierarchySelect = (teamId: string) => {
  router.push({ name: 'team', params: { team: teamId } })
}
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
        @select="handleHierarchySelect"
      />
    </aside>
    <main class="content-grid mx-auto">
      <RouterView />
    </main>
  </div>
</template>
