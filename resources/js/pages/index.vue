<script setup lang="ts">
import { SelectTrigger } from 'reka-ui'
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import type { SpaceQueryParams } from '~/api/resources/spaces'
import SpaceIcon from '~/assets/images/space.svg?component'
import AppHeader from '~/components/AppHeader.vue'
import Icon from '~/components/Icon.vue'
import CreateBlueprintDialog from '~/components/space/CreateBlueprintDialog.vue'
import SpaceBadgeDialog from '~/components/space/SpaceBadgeDialog.vue'
import SpaceCard from '~/components/space/SpaceCard.vue'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import TeamSelector from '~/components/TeamSelector.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { getPresenceColor } from '~/components/ui/presence-colors'
import { Select, SelectContent, SelectItem } from '~/components/ui/select'
import { Skeleton } from '~/components/ui/skeleton'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'
import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { useI18n } from '~/plugins/i18n'
import type { TeamResource } from '~/types/teams'

const { $t, t } = useI18n()
const { useSpacesQuery, useArchiveSpaceMutation } = useSpaces()
const route = useRoute()
const router = useRouter()
const { selectedTeam, hasTeams, teams, isLoadingTeams } = useGlobalTeam()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(
  computed(() => (selectedTeam.value?.id ? { team_id: selectedTeam.value.id } : {}))
)

const canCreateSpace = computed(() => access.canAccessRoute('spaces-new'))
const isRootUser = computed(() => access.authorization.value?.is_root ?? false)

useSeoMeta({
  title: computed(() => {
    const title = t('labels.spaces.title')
    const team = selectedTeam.value?.name
    return team ? `${team}: ${title}` : title
  }),
})

const sort = computed({
  get() {
    return (route.query.sort as string) || '-updated_at'
  },
  set(value: string) {
    router.replace({
      query: {
        ...route.query,
        sort: value,
      },
    })
  },
})

const archived = computed({
  get() {
    return route.query.archived === 'true'
  },
  set(value: boolean) {
    router.replace({
      query: {
        ...route.query,
        archived: value ? 'true' : undefined,
      },
    })
  },
})

const spaceFilter = computed<SpaceQueryParams>(() => {
  return {
    archived: archived.value || undefined,
    sort: sort.value || '-updated_at',
    team_id: selectedTeam.value?.id,
    sub_teams: true,
    per_page: 1000,
  }
})

// Without a team the request would return every space the user can reach, so wait for the teams.
const { data: spaces, isLoading } = useSpacesQuery(spaceFilter, () => !isLoadingTeams.value)

// The space stays set after closing, so the dialog can animate out.
const badgeDialogSpace = ref<SpaceResource | null>(null)
const isBadgeDialogOpen = ref(false)
const openBadgeDialog = (space: SpaceResource) => {
  badgeDialogSpace.value = space
  isBadgeDialogOpen.value = true
}
const blueprintSourceSpace = ref<SpaceResource | null>(null)
const isCreateBlueprintDialogOpen = ref(false)

const { alert } = useAlertDialog()
const archiveMutation = useArchiveSpaceMutation()

const handleArchive = async (space: SpaceResource) => {
  const confirmed = await alert.confirm(`Are you sure you want to archive "${space.name}"?`, {
    title: 'Archive Space',
    confirmLabel: 'Archive',
    cancelLabel: 'Cancel',
  })

  if (confirmed) {
    archiveMutation.mutate(space.id)
  }
}

const openCreateBlueprintDialog = (space: SpaceResource) => {
  blueprintSourceSpace.value = space
  isCreateBlueprintDialogOpen.value = true
}

const handleBlueprintDialogOpenChange = (value: boolean | undefined) => {
  const open = value === true
  isCreateBlueprintDialogOpen.value = open

  if (!open) {
    blueprintSourceSpace.value = null
  }
}

const getSortLabel = (sort: string) => {
  return $t(sort.replace(/^[+-]?(\w*)/, 'labels.sort.$1') + (sort.startsWith('-') ? 'Desc' : 'Asc'))
}

interface SubtreeTeam {
  team: TeamResource
  /** Teams between the selected team and this one, nearest last. */
  path: TeamResource[]
}

/** The selected team and every team below it, in tree order. */
const subtree = computed<SubtreeTeam[]>(() => {
  const rootId = selectedTeam.value?.id
  if (!rootId) return []

  const entries = flattenTeamHierarchy((teams.value?.data ?? []) as TeamResource[])
  const start = entries.findIndex((entry) => entry.team.id === rootId)
  if (start === -1) return []

  const rootDepth = entries[start].depth
  const result: SubtreeTeam[] = [{ team: entries[start].team, path: [] }]
  const chain: TeamResource[] = []
  for (const entry of entries.slice(start + 1)) {
    if (entry.depth <= rootDepth) break
    chain.length = entry.depth - rootDepth - 1
    result.push({ team: entry.team, path: [...chain] })
    chain.push(entry.team)
  }
  return result
})

const hasSubTeams = computed(() => subtree.value.length > 1)

type Scope = 'all' | 'direct' | 'subTeams'

const scope = computed<Scope>({
  get() {
    const value = route.query.scope
    return value === 'direct' || value === 'subTeams' ? value : 'all'
  },
  set(value) {
    router.replace({ query: { ...route.query, scope: value === 'all' ? undefined : value } })
  },
})

const query = ref('')

const isDirect = (space: SpaceResource) => space.team_id === selectedTeam.value?.id

const scopeTest: Record<Scope, (space: SpaceResource) => boolean> = {
  all: () => true,
  direct: isDirect,
  subTeams: (space) => !isDirect(space),
}

const scopes = computed(() =>
  (Object.keys(scopeTest) as Scope[]).map((key) => ({
    value: key,
    label: t(`labels.spaces.scope.${key}`),
    count: (spaces.value ?? []).filter(scopeTest[key]).length,
  }))
)

interface SpaceGroup {
  key: string
  /** `null` for spaces of a team the user cannot see. */
  team: TeamResource | null
  path: TeamResource[]
  entries: { space: SpaceResource; indices?: number[] }[]
}

/** Spaces in scope and matching the search, grouped by team in tree order. Empty groups are dropped. */
const groups = computed<SpaceGroup[]>(() => {
  const normalized = prepareFuzzyQuery(query.value)
  const byTeam = new Map<string, SpaceGroup['entries']>()

  for (const space of spaces.value ?? []) {
    if (!scopeTest[scope.value](space)) continue
    const match = normalized ? fuzzyMatch(normalized, prepareFuzzyTarget(space.name)) : null
    if (normalized && !match) continue

    const key = space.team_id ?? ''
    byTeam.set(key, [...(byTeam.get(key) ?? []), { space, indices: match?.indices }])
  }

  const result: SpaceGroup[] = []
  for (const { team, path } of subtree.value) {
    const entries = byTeam.get(team.id)
    if (!entries) continue
    result.push({ key: team.id, team, path, entries })
    byTeam.delete(team.id)
  }

  const rest = [...byTeam.values()].flat()
  if (rest.length) result.push({ key: 'other', team: null, path: [], entries: rest })

  return result
})

const showGroupHeaders = computed(() => hasSubTeams.value && scope.value !== 'direct')
</script>

<template>
  <AppHeader>
    <div class="flex items-start">
      <TeamSelector size="sm" />
    </div>
    <template #headerActions>
      <div class="flex items-center gap-3">
        <Button
          v-if="isRootUser"
          size="sm"
          variant="outline"
          :as="RouterLink"
          :to="{ name: 'provider-dashboard' }"
        >
          <Icon name="lucide:shield-check" />
          <span>{{ $t('labels.provider.title') }}</span>
        </Button>
        <template v-if="selectedTeam">
          <Button
            v-if="selectedTeam.can_view_detail"
            size="sm"
            :as="RouterLink"
            :to="`/teams/${selectedTeam.id}`"
          >
            <Icon name="lucide:users" />
            <span>{{ selectedTeam.user_count }}</span>
          </Button>
          <Button
            v-if="selectedTeam.can_manage_members"
            size="sm"
            :as="RouterLink"
            :to="{ name: 'team', params: { team: selectedTeam.id } }"
          >
            <Icon name="lucide:user-plus" />
            {{ $t('actions.inviteMember') }}
          </Button>
          <Button
            v-if="canCreateSpace"
            :as="RouterLink"
            size="sm"
            variant="primary"
            :to="{ name: 'spaces-new' }"
          >
            {{ $t('actions.spaces.add') }}
          </Button>
        </template>
      </div>
    </template>
  </AppHeader>

  <CreateBlueprintDialog
    :open="isCreateBlueprintDialogOpen"
    :team-id="blueprintSourceSpace?.team_id ?? selectedTeam?.id ?? null"
    :source-space="blueprintSourceSpace"
    @update:open="handleBlueprintDialogOpenChange"
  />

  <div class="flex w-full grow bg-background pt-14">
    <aside class="w-56 shrink-0 bg-surface">
      <div class="flex flex-col gap-4 p-3">
        <div
          v-if="isRootUser || hasTeams"
          class="flex flex-col gap-px text-sm"
        >
          <h4 class="mb-2 font-semibold text-primary">
            {{ $t('labels.teams.pageTitle') }}
          </h4>
          <RouterLink
            class="flex items-center gap-1 rounded-md p-2 font-medium"
            :to="{ name: 'teams-index' }"
          >
            <Icon name="lucide:users" />
            <span>{{ $t('labels.teams.listTitle') }}</span>
          </RouterLink>
        </div>
        <hr
          v-if="isRootUser || hasTeams"
          class="border-border"
        />
        <div class="flex flex-col gap-px text-sm">
          <h4 class="mb-2 font-semibold text-primary">
            {{ $t('labels.spaces.title') }}
          </h4>
          <RouterLink
            class="flex items-center gap-1 rounded-md p-2 font-medium"
            :class="[spaceFilter.archived ? '' : 'bg-secondary text-primary']"
            :to="{ name: 'index', query: { ...route.query, archived: undefined } }"
          >
            <Icon name="lucide:layout-grid" />
            <span>{{ $t('labels.spaces.sidebar.all') }}</span>
          </RouterLink>
          <RouterLink
            class="flex items-center gap-1 rounded-md p-2 font-medium"
            :class="[spaceFilter.archived ? 'bg-secondary text-primary' : '']"
            :to="{ name: 'index', query: { ...route.query, archived: 'true' } }"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ $t('labels.spaces.sidebar.archived') }}</span>
          </RouterLink>
        </div>
      </div>
    </aside>

    <main class="content-grid mx-auto">
      <div class="flex flex-col gap-8">
        <ContentHeader :header="$t('labels.spaces.title')">
          <Select v-model="sort">
            <SelectTrigger class="flex items-center gap-2">
              <span class="truncate">
                {{ getSortLabel(sort) }}
              </span>
              <Icon name="lucide:chevron-down" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="+name">{{ $t('labels.sort.nameAsc') }}</SelectItem>
              <SelectItem value="-name">{{ $t('labels.sort.nameDesc') }}</SelectItem>
              <SelectItem value="+created_at">{{ $t('labels.sort.created_atAsc') }}</SelectItem>
              <SelectItem value="-created_at">{{ $t('labels.sort.created_atDesc') }}</SelectItem>
              <SelectItem value="+updated_at">{{ $t('labels.sort.updated_atAsc') }}</SelectItem>
              <SelectItem value="-updated_at">{{ $t('labels.sort.updated_atDesc') }}</SelectItem>
            </SelectContent>
          </Select>
        </ContentHeader>

        <div
          v-if="spaces?.length"
          class="flex flex-wrap items-center gap-3"
        >
          <div class="flex h-9 w-72 items-center gap-2 rounded-md border border-input-border bg-input px-3 shadow-sm focus-within:ring-1 focus-within:ring-ring">
            <Icon
              name="lucide:search"
              class="shrink-0 text-muted"
            />
            <input
              v-model="query"
              type="search"
              :placeholder="t('labels.spaces.search')"
              :aria-label="t('labels.spaces.search')"
              class="min-w-0 flex-1 bg-transparent text-sm text-primary outline-none placeholder:text-muted"
            >
          </div>

          <Tabs
            v-if="hasSubTeams"
            v-model="scope"
          >
            <TabsList>
              <TabsTrigger
                v-for="item in scopes"
                :key="item.value"
                :value="item.value"
              >
                {{ item.label }}
                <span class="text-muted tabular-nums">{{ item.count }}</span>
              </TabsTrigger>
            </TabsList>
          </Tabs>
        </div>

        <div
          v-if="isLoading"
          class="grid w-full gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
          <Skeleton
            v-for="index in 4"
            :key="index"
            class="h-48 rounded-lg"
          />
        </div>

        <div
          v-else-if="groups.length"
          class="flex flex-col gap-10"
        >
          <section
            v-for="group in groups"
            :key="group.key"
            class="flex flex-col gap-4"
          >
            <header
              v-if="showGroupHeaders"
              class="flex min-w-0 items-center gap-2.5"
            >
              <template v-if="group.team">
                <TeamAvatar
                  :name="group.team.name"
                  :avatar="group.team.avatar"
                  :color="group.team.color || getPresenceColor(group.team.id).value"
                  size="md"
                />
                <h3 class="flex min-w-0 items-center gap-1.5 text-panel font-semibold">
                  <template
                    v-for="ancestor in group.path"
                    :key="ancestor.id"
                  >
                    <span class="truncate font-normal text-muted">{{ ancestor.name }}</span>
                    <Icon
                      name="lucide:chevron-right"
                      class="shrink-0 text-muted"
                    />
                  </template>
                  <RouterLink
                    v-if="group.team.can_view_detail"
                    :to="{ name: 'team-overview', params: { team: group.team.id } }"
                    class="truncate text-primary hover:underline"
                  >
                    {{ group.team.name }}
                  </RouterLink>
                  <span
                    v-else
                    class="truncate text-primary"
                  >{{ group.team.name }}</span>
                </h3>
              </template>
              <h3
                v-else
                class="text-panel font-semibold text-primary"
              >
                {{ t('labels.spaces.otherTeams') }}
              </h3>
              <span class="text-sm text-muted tabular-nums">{{ group.entries.length }}</span>
            </header>

            <div class="grid w-full gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              <SpaceCard
                v-for="entry in group.entries"
                :key="entry.space.id"
                :space="entry.space"
                :highlight="entry.indices"
                @archive="handleArchive"
                @assign-badge="openBadgeDialog"
                @create-blueprint="openCreateBlueprintDialog"
              />
            </div>
          </section>
        </div>

        <div
          v-else-if="spaces?.length"
          class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong py-16 text-center"
        >
          <Icon
            name="lucide:search-x"
            class="size-8 text-muted"
          />
          <p class="text-sm text-muted">{{ t('labels.spaces.noMatches') }}</p>
        </div>

        <div
          v-else
          class="flex min-h-96 w-full flex-col items-center justify-center gap-6 text-center"
        >
          <SpaceIcon class="w-32 text-muted" />
          <div class="space-y-2">
            <h3 class="text-xl font-semibold text-primary">
              {{ $t('labels.spaces.emptyTitle') }}
            </h3>
            <p class="max-w-md text-sm text-muted">
              {{ $t('labels.spaces.emptyDescription') }}
            </p>
          </div>
          <Button
            v-if="canCreateSpace"
            :as="RouterLink"
            variant="primary"
            :to="{ name: 'spaces-new' }"
          >
            <Icon name="lucide:plus" />
            <span>{{ $t('actions.spaces.add') }}</span>
          </Button>
        </div>
      </div>

      <SpaceBadgeDialog
        v-if="badgeDialogSpace"
        v-model:open="isBadgeDialogOpen"
        :space="badgeDialogSpace"
      />
    </main>
  </div>
</template>
