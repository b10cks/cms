<script setup lang="ts">
import { useStorage } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import TeamOptionRow from '~/components/teams/TeamOptionRow.vue'
import { Badge } from '~/components/ui/badge'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { Spinner } from '~/components/ui/spinner'
import { isClient } from '~/lib/env'
import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { filterTeamTree, pushRecentTeam } from '~/lib/team-switcher'
import { cn } from '~/lib/utils'
import type { TeamResource } from '~/types/teams'

/**
 * The team switcher: a quiet header trigger that opens a menu with a fuzzy search
 * that filters the team tree like the content tree does (matches plus their
 * ancestors, ↑/↓ walk the matches), recent teams and the nested tree. Inside
 * the team area it follows and changes the route's team; on the start page it
 * changes the globally selected team.
 */
const props = withDefaults(defineProps<{ size?: 'sm' | 'md' }>(), { size: 'md' })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { selectedTeam, isLoading, selectTeam, teams } = useGlobalTeam()

const recentIds = isClient
  ? useStorage<string[]>('team-switcher-recent', [])
  : ref<string[]>([])

const open = ref(false)
const query = ref('')
const activeIndex = ref(0)
const listId = useId()

const allTeams = computed<TeamResource[]>(() => (teams.value?.data ?? []) as TeamResource[])
const byId = computed(() => new Map(allTeams.value.map((team) => [team.id, team])))
const tree = computed(() => flattenTeamHierarchy(allTeams.value))

const routeTeamId = computed(() =>
  typeof route.params.team === 'string' ? route.params.team : null
)
const currentTeam = computed(
  () => (routeTeamId.value ? byId.value.get(routeTeamId.value) : null) ?? selectedTeam.value
)

interface SwitcherOption {
  team: TeamResource
  depth: number
  indices?: number[]
  group: 'match' | 'context' | 'recent' | 'tree'
}

const isSearching = computed(() => query.value.trim().length > 0)

const filtered = computed(() => filterTeamTree(query.value, tree.value))

const recent = computed<SwitcherOption[]>(() =>
  recentIds.value
    .filter((id) => id !== currentTeam.value?.id)
    .map((id) => byId.value.get(id))
    .filter((team): team is TeamResource => !!team)
    .slice(0, 3)
    .map((team) => ({ team, depth: 0, group: 'recent' as const }))
)

const options = computed<SwitcherOption[]>(() =>
  isSearching.value
    ? filtered.value.rows.map((row) => ({
        team: row.team,
        depth: row.depth,
        indices: row.indices,
        group: row.isMatch ? ('match' as const) : ('context' as const),
      }))
    : [...recent.value, ...tree.value.map((entry) => ({ ...entry, group: 'tree' as const }))]
)

/** Rows the keyboard visits: while searching only the matches, like the content tree. */
const navigable = computed(() =>
  options.value.flatMap((option, index) => (option.group === 'context' ? [] : [index]))
)

const matchCount = computed(() => navigable.value.length)

const optionId = (index: number) => `${listId}-option-${index}`

watch(query, () => {
  activeIndex.value = isSearching.value ? Math.max(filtered.value.best, 0) : 0
  nextTick(scrollActiveIntoView)
})

watch(open, (isOpen) => {
  if (!isOpen) return
  query.value = ''
  const current = options.value.findIndex(
    (option) => option.group === 'tree' && option.team.id === currentTeam.value?.id
  )
  activeIndex.value = Math.max(current, 0)
  nextTick(scrollActiveIntoView)
})

const scrollActiveIntoView = () => {
  document.getElementById(optionId(activeIndex.value))?.scrollIntoView({ block: 'nearest' })
}

const moveActive = (direction: 1 | -1) => {
  const indices = navigable.value
  if (indices.length === 0) return
  const position = indices.indexOf(activeIndex.value)
  const next = position === -1 ? 0 : (position + direction + indices.length) % indices.length
  activeIndex.value = indices[next]
  nextTick(scrollActiveIntoView)
}

const choose = (team: TeamResource) => {
  open.value = false
  recentIds.value = pushRecentTeam(recentIds.value, team.id)
  selectTeam(team.id)

  if (routeTeamId.value && routeTeamId.value !== team.id) {
    // Section pages keep their section; detail pages (a skill, a document) fall back to the overview.
    const keepSection = Object.keys(route.params).length === 1 && typeof route.name === 'string'
    router.push({
      name: keepSection ? (route.name as string) : 'team-overview',
      params: { team: team.id },
    })
  }
}

const onKeydown = (event: KeyboardEvent) => {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      moveActive(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      moveActive(-1)
      break
    case 'Enter': {
      event.preventDefault()
      const option = options.value[activeIndex.value]
      if (option) choose(option.team)
      break
    }
  }
}

const canCreateSubTeam = computed(() => !!currentTeam.value?.can_create_child)

const createSubTeam = () => {
  if (!currentTeam.value) return
  open.value = false
  router.push({
    name: 'team-overview',
    params: { team: currentTeam.value.id },
    query: { create: 'sub-team' },
  })
}

const sizeClasses = {
  sm: 'h-8',
  md: 'h-9',
}

/** The current team's ancestors, root first. */
const ancestors = computed(() => {
  const chain: TeamResource[] = []
  let parentId = currentTeam.value?.parent_id
  while (parentId && chain.length < 20) {
    const parent = byId.value.get(parentId)
    if (!parent) break
    chain.unshift(parent)
    parentId = parent.parent_id
  }
  return chain
})
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      :disabled="isLoading"
      :aria-label="t('labels.teamWorkspace.switcher.label')"
      :class="
        cn(
          'flex max-w-[min(28rem,100%)] min-w-0 cursor-pointer items-center gap-2 rounded-lg bg-sidebar-accent/60 pr-2 pl-2.5 text-start text-sm font-semibold whitespace-nowrap text-primary transition-colors duration-100 outline-none hover:bg-sidebar-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=open]:bg-sidebar-accent',
          sizeClasses[props.size]
        )
      "
    >
      <span
        v-if="isLoading"
        class="flex items-center gap-2"
      >
        <Spinner class="shrink-0" />
        <span>{{ t('labels.loading') }}</span>
      </span>
      <template v-else-if="currentTeam">
        <Icon
          :name="`lucide:${currentTeam.icon ?? 'users'}`"
          :style="{ color: currentTeam.color ?? undefined }"
          :class="['shrink-0', !currentTeam.color && 'text-muted']"
        />
        <span
          v-if="ancestors.length"
          class="flex min-w-0 shrink items-center gap-1.5 font-medium text-muted"
        >
          <span
            v-if="ancestors.length > 1"
            :title="ancestors.slice(0, -1).map((team) => team.name).join(' / ')"
          >…</span>
          <span
            v-if="ancestors.length > 1"
            aria-hidden="true"
          >/</span>
          <span class="max-w-40 truncate">{{ ancestors[ancestors.length - 1].name }}</span>
          <span aria-hidden="true">/</span>
        </span>
        <span class="min-w-0 truncate">{{ currentTeam.name }}</span>
        <Badge
          v-if="currentTeam.type"
          variant="secondary"
          size="sm"
        >
          {{ currentTeam.type }}
        </Badge>
      </template>
      <span
        v-else
        class="flex items-center gap-2"
      >
        <Icon
          name="lucide:users"
          class="shrink-0 text-muted"
        />
        <span>{{ t('labels.teams.selectPlaceholder') }}</span>
      </span>
      <Icon
        name="lucide:chevrons-up-down"
        size="1rem"
        class="-ml-0.5 shrink-0 text-muted"
      />
    </PopoverTrigger>

    <PopoverContent
      align="start"
      :side-offset="6"
      :collision-padding="8"
      class="flex max-h-[min(30rem,var(--reka-popover-content-available-height))]! w-96 max-w-[calc(100vw-1rem)] flex-col overflow-hidden overscroll-contain border-popover-border! p-0! [--menu-row-ring:transparent]"
    >
      <div class="flex shrink-0 items-center gap-2 border-b border-popover-border px-3">
        <Icon
          name="lucide:search"
          class="shrink-0 text-muted"
        />
        <input
          v-model="query"
          type="text"
          role="combobox"
          aria-autocomplete="list"
          :aria-expanded="true"
          :aria-controls="listId"
          :aria-activedescendant="options.length ? optionId(activeIndex) : undefined"
          :aria-label="t('labels.teamWorkspace.switcher.search')"
          :placeholder="t('labels.teamWorkspace.switcher.search')"
          class="h-10 min-w-0 flex-1 bg-transparent text-sm text-primary outline-none placeholder:text-input-muted"
          @keydown="onKeydown"
        >
        <span
          v-if="isSearching"
          aria-live="polite"
          :class="['shrink-0 text-sm tabular-nums', matchCount > 0 ? 'text-muted' : 'text-destructive-ink']"
        >
          {{
            matchCount > 0
              ? t('labels.contentTree.search.matches', {
                current: Math.max(navigable.indexOf(activeIndex), 0) + 1,
                total: matchCount,
              })
              : t('labels.contentTree.search.noMatches')
          }}
        </span>
      </div>

      <div
        :id="listId"
        role="listbox"
        :aria-label="t('labels.teamWorkspace.switcher.label')"
        class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-1"
      >
        <template
          v-for="(option, index) in options"
          :key="`${option.group}-${option.team.id}`"
        >
          <div
            v-if="!isSearching && index === 0 && option.group === 'recent'"
            class="px-2 py-1.5 text-sm font-medium text-muted"
            role="presentation"
          >
            {{ t('labels.teamWorkspace.switcher.recent') }}
          </div>
          <div
            v-if="!isSearching && option.group === 'tree' && index > 0 && options[index - 1]?.group !== 'tree'"
            class="-mx-1 my-1 h-px bg-popover-border"
            role="presentation"
          />
          <div
            :id="optionId(index)"
            role="option"
            :aria-selected="option.team.id === currentTeam?.id"
            :aria-disabled="option.group === 'context' ? undefined : false"
            :data-highlighted="index === activeIndex ? '' : undefined"
            :class="[
              'menu-row cursor-pointer py-0 pr-8',
              option.group === 'context' && index !== activeIndex ? 'text-muted' : '',
            ]"
            @mouseenter="activeIndex = index"
            @mousedown.prevent
            @click="choose(option.team)"
          >
            <span
              v-if="option.team.id === currentTeam?.id && option.group !== 'recent'"
              class="absolute right-2 flex items-center justify-center"
            >
              <Icon name="lucide:check" />
            </span>
            <TeamOptionRow
              :name="option.team.name"
              :icon="option.team.icon"
              :color="option.team.color"
              :type="option.team.type"
              :depth="option.group === 'recent' ? 0 : option.depth"
              :highlight="option.indices"
              guides
            />
          </div>
        </template>
      </div>

      <template v-if="canCreateSubTeam">
        <div class="h-px shrink-0 bg-popover-border" />
        <div class="shrink-0 p-1">
          <button
            type="button"
            class="menu-row w-full cursor-pointer text-muted hover:text-primary focus-visible:bg-state-selected focus-visible:text-primary"
            @click="createSubTeam"
          >
            <Icon name="lucide:plus" />
            {{ t('labels.teamWorkspace.switcher.newSubTeam') }}
          </button>
        </div>
      </template>
    </PopoverContent>
  </Popover>
</template>
