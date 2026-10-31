<script setup lang="ts">
import { useStorage } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import TeamOptionRow from '~/components/teams/TeamOptionRow.vue'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { Spinner } from '~/components/ui/spinner'
import { isClient } from '~/lib/env'
import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { filterTeamTree, pushRecentTeam } from '~/lib/team-switcher'
import { cn } from '~/lib/utils'
import type { TeamResource } from '~/types/teams'

/**
 * The team switcher: looks like a Select, opens a popover with a fuzzy search
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
    // Section pages keep their section; detail pages (a skill, a glossary) fall back to the overview.
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
  sm: 'h-8! text-sm',
  md: 'h-10',
}
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      :disabled="isLoading"
      :aria-label="t('labels.teamWorkspace.switcher.label')"
      :class="
        cn(
          'flex w-full cursor-pointer items-center justify-between gap-2 rounded-md border border-input-border bg-input py-2 pr-2 pl-3 text-start text-sm font-semibold whitespace-nowrap shadow-sm ring-offset-background focus:ring-1 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:opacity-50',
          sizeClasses[props.size]
        )
      "
    >
      <div
        v-if="isLoading"
        class="flex items-center gap-2"
      >
        <Spinner class="shrink-0" />
        <span>{{ t('labels.loading') }}</span>
      </div>
      <TeamOptionRow
        v-else-if="currentTeam"
        compact
        :name="currentTeam.name"
        :icon="currentTeam.icon"
        :color="currentTeam.color"
        :type="currentTeam.type"
      />
      <div
        v-else
        class="flex items-center gap-2"
      >
        <Icon
          name="lucide:users"
          class="shrink-0"
        />
        <span>{{ t('labels.teams.selectPlaceholder') }}</span>
      </div>
      <Icon
        name="lucide:chevron-down"
        size="1rem"
        class="shrink-0 text-muted"
      />
    </PopoverTrigger>

    <PopoverContent
      align="start"
      :side-offset="4"
      class="flex max-h-[min(28rem,var(--reka-popover-content-available-height))]! w-80 min-w-(--reka-popover-trigger-width) flex-col p-1!"
    >
      <div class="mb-1 flex shrink-0 items-center gap-2 rounded-md border border-border bg-background py-1 pr-2 pl-2 shadow-sm">
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
          class="h-6 min-w-0 flex-1 bg-transparent text-sm text-primary outline-none placeholder:text-muted"
          @keydown="onKeydown"
        >
        <span
          v-if="isSearching"
          aria-live="polite"
          :class="['shrink-0 text-sm tabular-nums', matchCount > 0 ? 'text-muted' : 'text-destructive']"
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
        class="min-h-0 flex-1 overflow-y-auto"
      >
        <template
          v-for="(option, index) in options"
          :key="`${option.group}-${option.team.id}`"
        >
          <div
            v-if="!isSearching && index === 0 && option.group === 'recent'"
            class="px-2 py-1.5 text-sm font-semibold text-muted"
            role="presentation"
          >
            {{ t('labels.teamWorkspace.switcher.recent') }}
          </div>
          <div
            v-if="!isSearching && option.group === 'tree' && index > 0 && options[index - 1]?.group !== 'tree'"
            class="-mx-1 my-1 h-px bg-border"
            role="presentation"
          />
          <div
            :id="optionId(index)"
            role="option"
            :aria-selected="option.team.id === currentTeam?.id"
            :aria-disabled="option.group === 'context' ? undefined : false"
            :class="[
              'relative flex w-full cursor-pointer items-center rounded-sm py-1.5 pr-8 pl-2 text-sm outline-none select-none',
              index === activeIndex
                ? 'bg-accent text-accent-foreground [&_.text-info]:text-accent-foreground [&_.text-info]:underline'
                : '',
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
            />
          </div>
        </template>
      </div>

      <template v-if="canCreateSubTeam">
        <div class="-mx-1 my-1 h-px shrink-0 bg-border" />
        <button
          type="button"
          class="flex w-full shrink-0 cursor-pointer items-center gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm text-muted outline-none select-none hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:text-accent-foreground"
          @click="createSubTeam"
        >
          <Icon name="lucide:plus" />
          {{ t('labels.teamWorkspace.switcher.newSubTeam') }}
        </button>
      </template>
    </PopoverContent>
  </Popover>
</template>
