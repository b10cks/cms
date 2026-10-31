<script setup lang="ts">
import TeamActivityList from '~/components/teams/workspace/TeamActivityList.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)

const page = ref(1)
const perPage = ref(25)
const params = computed(() => ({ page: page.value, per_page: perPage.value }))

const { useTeamAuditLogsQuery } = useTeamWorkspace()
const { data, isLoading, isFetching } = useTeamAuditLogsQuery(teamId, params)

watch(teamId, () => (page.value = 1))

useSeoMeta({ title: computed(() => t('labels.teamWorkspace.activity.title')) })
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="t('labels.teamWorkspace.activity.title')"
      :description="t('labels.teamWorkspace.activity.description')"
    />

    <Panel :class="isFetching && !isLoading ? 'opacity-60 transition-opacity' : 'transition-opacity'">
      <Skeleton
        v-if="isLoading"
        class="mt-5 h-48 w-full"
      />
      <p
        v-else-if="!data?.data.length"
        class="py-10 text-center text-sm text-muted"
      >
        {{ t('labels.teamWorkspace.activity.empty') }}
      </p>
      <TeamActivityList
        v-else
        :entries="data.data"
      />
    </Panel>

    <TablePaginationFooter
      v-if="data?.meta"
      :meta="data.meta"
      :current-page="page"
      :per-page="perPage"
      @update:current-page="(value) => (page = value)"
      @update:per-page="(value) => ((perPage = value), (page = 1))"
    />
  </div>
</template>
