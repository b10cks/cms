<script setup lang="ts">
import StopIcon from '~/assets/images/error.svg?component'
import Icon from '~/components/Icon.vue'
import StatTile from '~/components/stats/StatTile.vue'
import CreateTeamDialog from '~/components/teams/CreateTeamDialog.vue'
import TeamCard from '~/components/teams/dashboard/TeamCard.vue'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Card, CardContent } from '~/components/ui/card'
import HighlightedText from '~/components/ui/HighlightedText.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { getPresenceColor } from '~/components/ui/presence-colors'
import { useAuthorization } from '~/composables/useAuthorization'
import { hasRootWriteAccess } from '~/lib/access-control'
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'
import type { TeamsDashboardCard } from '~/types/team-workspace'
import type { CreateTeamPayload, TeamHierarchyItem } from '~/types/teams'

type Segment = 'all' | 'nested' | 'sso' | 'attention'
type SortKey = 'name' | 'people' | 'spaces' | 'edited'
type View = 'cards' | 'tree'

const PAGE_SIZE = 24

const { t } = useI18n()
const { alert } = useAlertDialog()

useSeoMeta({ title: computed(() => t('labels.teams.pageTitle')) })

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({})))
const isRootUser = computed(() => access.authorization.value?.is_root ?? false)
// Top-level teams and team types are root writes: they need a running elevation.
const canWriteAsRoot = computed(() => hasRootWriteAccess(access.authorization.value))

const { useTeamsDashboardQuery } = useTeamWorkspace()
const { useTeamHierarchyQuery, useCreateTeamMutation, useDeleteTeamMutation } = useTeams()

const { data: dashboard, isLoading, isError } = useTeamsDashboardQuery()
const { data: hierarchyData } = useTeamHierarchyQuery()
const hierarchy = computed(() => hierarchyData.value ?? [])

const createTeam = useCreateTeamMutation()
const deleteTeam = useDeleteTeamMutation()

const hierarchyHasCreatableNode = (items: TeamHierarchyItem[]): boolean =>
  items.some((item) => item.can_create_child || hierarchyHasCreatableNode(item.children ?? []))
const canCreateTeam = computed(() => canWriteAsRoot.value || hierarchyHasCreatableNode(hierarchy.value))

const summary = computed(() => dashboard.value?.summary)

/** A personal team with nothing below it is a sign-up artifact, not an organisation: list it compactly. */
const isPersonal = (team: TeamsDashboardCard) => team.type === 'personal' && team.sub_team_count === 0
const cards = computed(() => (dashboard.value?.teams ?? []).filter((team) => !isPersonal(team)))
const personalTeams = computed(() => (dashboard.value?.teams ?? []).filter(isPersonal))

const visiblePersonalTeams = computed(() => {
  const normalized = prepareFuzzyQuery(query.value)
  return personalTeams.value.flatMap((team) => {
    if (!normalized) return [{ team, indices: undefined as number[] | undefined }]
    const match = fuzzyMatch(normalized, prepareFuzzyTarget(team.name))
    return match ? [{ team, indices: match.indices }] : []
  })
})

const monthLabels = computed(() =>
  Array.from({ length: 12 }, (_, index) => {
    const date = new Date()
    date.setDate(1)
    date.setMonth(date.getMonth() - (11 - index))
    return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
  })
)

const seriesChange = (series: number[] | undefined) => {
  if (!series || series.length < 2) return undefined
  const delta = series[series.length - 1] - series[series.length - 2]
  return delta === 0
    ? undefined
    : { value: delta, label: String(Math.abs(delta)), title: t('labels.teamsDashboard.tiles.sinceLastMonth') }
}

const tiles = computed(() => {
  const value = (amount: number | undefined) => (amount === undefined ? null : String(amount))
  const s = summary.value
  return [
    {
      label: t('labels.teamsDashboard.tiles.teams'),
      value: value(s?.teams),
      detail: t('labels.teamsDashboard.tiles.topTeams', { count: dashboard.value?.teams.length ?? 0 }, dashboard.value?.teams.length ?? 0),
      series: s?.teams_series,
      periods: monthLabels.value,
      change: seriesChange(s?.teams_series),
    },
    {
      label: t('labels.teamsDashboard.tiles.people'),
      value: value(s?.people),
      detail: t('labels.teamsDashboard.tiles.active', { count: s?.active_people ?? 0 }),
    },
    {
      label: t('labels.teamsDashboard.tiles.spaces'),
      value: value(s?.spaces),
      series: s?.spaces_series,
      periods: monthLabels.value,
      change: seriesChange(s?.spaces_series),
      color: 'var(--series-2)',
    },
    {
      label: t('labels.teamsDashboard.tiles.sso'),
      value: value(s?.sso_teams),
      detail: t('labels.teamsDashboard.tiles.ssoDetail', { count: s?.teams ?? 0 }),
    },
    {
      label: t('labels.teamsDashboard.tiles.attention'),
      value: value(s ? s.teams_without_owner + s.pending_invites : undefined),
      detail: t('labels.teamsDashboard.tiles.attentionDetail', {
        owners: s?.teams_without_owner ?? 0,
        invites: s?.pending_invites ?? 0,
      }),
    },
  ]
})

const query = ref('')
const segment = ref<Segment>('all')
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({ column: 'name', direction: 'asc' })
const view = ref<View>('cards')
const limit = ref(PAGE_SIZE)

const segmentTest: Record<Segment, (team: TeamsDashboardCard) => boolean> = {
  all: () => true,
  nested: (team) => team.sub_team_count > 0,
  sso: (team) => team.sso,
  attention: (team) => team.teams_without_owner > 0 || team.pending_invites > 0,
}

const segments = computed(() =>
  (Object.keys(segmentTest) as Segment[]).map((key) => ({
    value: key,
    label: t(`labels.teamsDashboard.segments.${key}`),
    count: cards.value.filter(segmentTest[key]).length,
  }))
)

/** Ascending comparators; the sort direction flips them. */
const sorters: Record<SortKey, (a: TeamsDashboardCard, b: TeamsDashboardCard) => number> = {
  name: (a, b) => a.name.localeCompare(b.name),
  people: (a, b) => a.people_count - b.people_count,
  spaces: (a, b) => a.spaces_count - b.spaces_count,
  edited: (a, b) => (a.content_updated_at ?? '').localeCompare(b.content_updated_at ?? ''),
}

const sortOptions = computed(() =>
  (Object.keys(sorters) as SortKey[]).map((key) => ({
    value: key,
    label: t(`labels.teamsDashboard.sort.${key}`),
  }))
)

const compareCards = (a: TeamsDashboardCard, b: TeamsDashboardCard) => {
  const sorter = sorters[sortBy.value.column as SortKey] ?? sorters.name
  return sortBy.value.direction === 'asc' ? sorter(a, b) : sorter(b, a)
}

/** Cards in the segment; a query ranks by fuzzy score and highlights, like the team switcher. */
const visibleCards = computed(() => {
  const inSegment = cards.value.filter(segmentTest[segment.value])
  const normalized = prepareFuzzyQuery(query.value)

  if (!normalized) {
    return [...inSegment].sort(compareCards).map((team) => ({ team, indices: undefined }))
  }

  return inSegment
    .flatMap((team) => {
      const match = fuzzyMatch(normalized, prepareFuzzyTarget(team.name))
      return match ? [{ team, indices: match.indices, score: match.score }] : []
    })
    .sort((a, b) => b.score - a.score)
})

watch([query, segment, sortBy], () => {
  limit.value = PAGE_SIZE
})

const shownCards = computed(() => visibleCards.value.slice(0, limit.value))

interface TreeRow {
  team: TeamHierarchyItem
  depth: number
}

const treeRows = computed<TreeRow[]>(() => {
  const rows: TreeRow[] = []
  const normalized = prepareFuzzyQuery(query.value)
  const walk = (items: TeamHierarchyItem[], depth: number) => {
    for (const team of [...items].sort((a, b) => a.name.localeCompare(b.name))) {
      if (!normalized || fuzzyMatch(normalized, prepareFuzzyTarget(team.name))) {
        rows.push({ team, depth: normalized ? 0 : depth })
      }
      walk(team.children ?? [], depth + 1)
    }
  }
  walk(hierarchy.value, 0)
  return rows
})

const handleCreateTeam = (payload: CreateTeamPayload) => createTeam.mutate(payload)

const confirmDelete = async (team: TeamsDashboardCard) => {
  const confirmed = await alert.confirm(t('labels.teams.deleteConfirm.message', { name: team.name }), {
    title: t('labels.teams.deleteConfirm.title'),
    confirmLabel: t('labels.teams.deleteConfirm.confirmLabel'),
    variant: 'destructive',
  })
  if (confirmed) deleteTeam.mutate(team.id)
}
</script>

<template>
  <div class="content-grid w-full content-start pb-12">
    <ContentHeader :header="t('labels.teams.pageTitle')">
      <template #after-header>
        <Badge
          v-if="isRootUser"
          variant="warning"
          size="sm"
        >
          {{ t('labels.teamsDashboard.instance') }}
        </Badge>
      </template>
      <template #actions>
        <div class="flex items-center gap-2">
          <Button
            v-if="isRootUser"
            variant="outline"
            as-child
          >
            <RouterLink :to="{ name: 'provider-dashboard' }">
              <Icon name="lucide:gauge" />
              {{ t('labels.teamsDashboard.providerConsole') }}
            </RouterLink>
          </Button>
          <CreateTeamDialog
            v-if="canCreateTeam"
            :hierarchy="hierarchy"
            :is-root="canWriteAsRoot"
            @submit="handleCreateTeam"
          />
        </div>
      </template>
    </ContentHeader>

    <Card
      v-if="isError"
      variant="surface"
    >
      <CardContent class="flex flex-col items-center gap-3 py-12! text-center">
        <StopIcon class="w-32 text-muted" />
        <h2 class="font-semibold text-primary">{{ t('labels.teams.loadError.title') }}</h2>
        <p class="mx-auto max-w-2xl text-sm text-muted">{{ t('labels.teams.loadError.description') }}</p>
      </CardContent>
    </Card>

    <template v-else>
      <dl class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
        <StatTile
          v-for="tile in tiles"
          :key="tile.label"
          v-bind="tile"
        />
      </dl>

      <div class="mt-8 flex flex-wrap items-center gap-3">
        <div class="flex h-9 w-72 items-center gap-2 rounded-md border border-input-border bg-input px-3 shadow-sm focus-within:ring-1 focus-within:ring-ring">
          <Icon
            name="lucide:search"
            class="shrink-0 text-muted"
          />
          <input
            v-model="query"
            type="search"
            :placeholder="t('labels.teamsDashboard.search')"
            :aria-label="t('labels.teamsDashboard.search')"
            class="min-w-0 flex-1 bg-transparent text-sm text-primary outline-none placeholder:text-muted"
          >
        </div>

        <Tabs
          v-if="view === 'cards'"
          v-model="segment"
        >
          <TabsList>
            <TabsTrigger
              v-for="item in segments"
              :key="item.value"
              :value="item.value"
            >
              {{ item.label }}
              <span class="text-muted tabular-nums">{{ item.count }}</span>
            </TabsTrigger>
          </TabsList>
        </Tabs>

        <div class="ml-auto flex items-center gap-3">
          <SortSelect
            v-if="view === 'cards'"
            v-model="sortBy"
            :options="sortOptions"
          />
          <Tabs v-model="view">
            <TabsList>
              <TabsTrigger value="cards">
                <Icon name="lucide:layout-grid" />
                {{ t('labels.teamsDashboard.views.cards') }}
              </TabsTrigger>
              <TabsTrigger value="tree">
                <Icon name="lucide:list-tree" />
                {{ t('labels.teamsDashboard.views.tree') }}
              </TabsTrigger>
            </TabsList>
          </Tabs>
        </div>
      </div>

      <div
        v-if="view === 'cards'"
        class="mt-4"
      >
        <div
          v-if="isLoading"
          class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
          <Skeleton
            v-for="index in 6"
            :key="index"
            class="h-80 rounded-xl"
          />
        </div>

        <div
          v-else-if="visibleCards.length === 0 && (cards.length > 0 || visiblePersonalTeams.length === 0)"
          class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong py-16 text-center"
        >
          <Icon
            name="lucide:users"
            class="size-8 text-muted"
          />
          <p class="text-sm text-muted">
            {{ dashboard?.teams.length ? t('labels.teamsDashboard.noMatches') : t('labels.teams.empty') }}
          </p>
          <CreateTeamDialog
            v-if="!dashboard?.teams.length && canCreateTeam"
            :hierarchy="hierarchy"
            :is-root="canWriteAsRoot"
            @submit="handleCreateTeam"
          />
        </div>

        <template v-else>
          <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <TeamCard
              v-for="entry in shownCards"
              :key="entry.team.id"
              :team="entry.team"
              :highlight="entry.indices"
              @delete="confirmDelete"
            />
          </div>
          <div
            v-if="visibleCards.length > shownCards.length"
            class="mt-6 flex justify-center"
          >
            <Button
              variant="outline"
              @click="limit += PAGE_SIZE"
            >
              {{ t('labels.teamsDashboard.showMore', { count: visibleCards.length - shownCards.length }) }}
            </Button>
          </div>
        </template>

        <Panel
          v-if="!isLoading && segment === 'all' && visiblePersonalTeams.length"
          class="mt-8"
          :title="t('labels.teamsDashboard.personal.title')"
          :meta="t('labels.teamsDashboard.treeMeta', { count: visiblePersonalTeams.length }, visiblePersonalTeams.length)"
          flush
        >
          <ul class="grid gap-x-2 px-2 pb-2 sm:grid-cols-2 xl:grid-cols-3">
            <li
              v-for="entry in visiblePersonalTeams"
              :key="entry.team.id"
            >
              <RouterLink
                :to="{ name: 'team-overview', params: { team: entry.team.id } }"
                class="flex h-11 items-center gap-2.5 rounded-md px-3 text-sm transition-colors hover:bg-tree-hover"
              >
                <TeamAvatar
                  :name="entry.team.name"
                  :avatar="entry.team.avatar"
                  :color="entry.team.color || getPresenceColor(entry.team.id).value"
                  size="md"
                />
                <span class="min-w-0 truncate font-semibold text-primary">
                  <HighlightedText
                    :text="entry.team.name"
                    :highlight="entry.indices"
                  />
                </span>
                <span class="ml-auto flex shrink-0 items-center gap-3 text-muted tabular-nums">
                  <span class="flex items-center gap-1">
                    <Icon name="lucide:users" />
                    {{ entry.team.people_count }}
                  </span>
                  <span class="flex items-center gap-1">
                    <Icon name="lucide:box" />
                    {{ entry.team.spaces_count }}
                  </span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </Panel>
      </div>

      <Panel
        v-else
        class="mt-4"
        :title="t('labels.teamsDashboard.views.tree')"
        :meta="t('labels.teamsDashboard.treeMeta', { count: treeRows.length }, treeRows.length)"
        flush
      >
        <ul class="pb-2">
          <li
            v-for="row in treeRows"
            :key="row.team.id"
          >
            <RouterLink
              :to="{ name: 'team-overview', params: { team: row.team.id } }"
              class="flex h-10 items-center gap-2.5 pr-5 text-sm transition-colors hover:bg-tree-hover"
              :style="{ paddingLeft: `${1.25 + row.depth * 1.25}rem` }"
            >
              <TeamAvatar
                :name="row.team.name"
                :avatar="row.team.avatar"
                :color="row.team.color"
                size="md"
              />
              <span class="min-w-0 truncate font-semibold text-primary">{{ row.team.name }}</span>
              <Badge
                v-if="row.team.type"
                type="outline"
                size="sm"
              >
                {{ row.team.type }}
              </Badge>
              <span class="ml-auto flex shrink-0 items-center gap-4 text-muted tabular-nums">
                <span class="flex items-center gap-1.5">
                  <Icon name="lucide:users" />
                  {{ row.team.user_count ?? 0 }}
                </span>
                <span class="flex items-center gap-1.5">
                  <Icon name="lucide:box" />
                  {{ row.team.spaces_count ?? 0 }}
                </span>
              </span>
            </RouterLink>
          </li>
        </ul>
      </Panel>
    </template>
  </div>
</template>
