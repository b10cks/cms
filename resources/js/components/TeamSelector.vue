<script setup lang="ts">
import { useStorage } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import HighlightedText from '~/components/ui/HighlightedText.vue'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { Spinner } from '~/components/ui/spinner'
import { isClient } from '~/lib/env'
import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { pushRecentTeam, searchTeams } from '~/lib/team-switcher'
import { cn } from '~/lib/utils'
import type { TeamResource } from '~/types/teams'

/**
 * The team switcher: a search popover with recent teams and the nested team
 * tree. Inside the team area it follows and changes the route's team; on the
 * start page it changes the globally selected team.
 */
withDefaults(defineProps<{ size?: 'sm' | 'md' }>(), { size: 'md' })

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
  group: 'match' | 'recent' | 'tree'
}

const matches = computed<SwitcherOption[]>(() =>
  searchTeams(query.value, tree.value).map((match) => ({ ...match, group: 'match' as const }))
)

const recent = computed<SwitcherOption[]>(() =>
  recentIds.value
    .filter((id) => id !== currentTeam.value?.id)
    .map((id) => byId.value.get(id))
    .filter((team): team is TeamResource => !!team)
    .slice(0, 3)
    .map((team) => ({ team, depth: 0, group: 'recent' as const }))
)

const isSearching = computed(() => query.value.trim().length > 0)

const options = computed<SwitcherOption[]>(() =>
  isSearching.value
    ? matches.value
    : [...recent.value, ...tree.value.map((entry) => ({ ...entry, group: 'tree' as const }))]
)

const optionId = (index: number) => `${listId}-option-${index}`

watch(query, () => {
  activeIndex.value = 0
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
  const total = options.value.length
  if (total === 0) return
  activeIndex.value = (activeIndex.value + direction + total) % total
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

const parentName = (team: TeamResource) =>
  team.parent_id ? (byId.value.get(team.parent_id)?.name ?? '') : ''
</script>

<template>
  <Popover v-model:open="open">
    <PopoverTrigger
      :disabled="isLoading"
      :aria-label="t('labels.teamWorkspace.switcher.label')"
      :class="
        cn(
          'flex max-w-72 min-w-0 cursor-pointer items-center gap-2 rounded-lg bg-sidebar-accent/60 px-2 transition-colors duration-200 hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[state=open]:bg-sidebar-accent',
          size === 'sm' ? 'h-8 text-sm' : 'h-10'
        )
      "
    >
      <template v-if="isLoading">
        <Spinner class="shrink-0" />
        <span>{{ t('labels.loading') }}</span>
      </template>
      <template v-else-if="currentTeam">
        <TeamAvatar
          :name="currentTeam.name"
          :avatar="currentTeam.avatar"
          :color="currentTeam.color"
          size="sm"
        />
        <span class="truncate font-semibold text-primary">{{ currentTeam.name }}</span>
      </template>
      <template v-else>
        <Icon
          name="lucide:users"
          class="shrink-0"
        />
        <span>{{ t('labels.teams.selectPlaceholder') }}</span>
      </template>
      <Icon
        name="lucide:chevrons-up-down"
        class="size-4 shrink-0 text-muted"
      />
    </PopoverTrigger>

    <PopoverContent
      align="start"
      class="flex max-h-[min(32rem,var(--reka-popover-content-available-height))] w-80 flex-col p-0"
    >
      <div class="flex items-center gap-2 border-b border-border px-3">
        <Icon
          name="lucide:search"
          class="size-4 shrink-0 text-muted"
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
          class="h-10 w-full bg-transparent text-sm text-primary outline-none placeholder:text-muted"
          @keydown="onKeydown"
        >
      </div>

      <div
        :id="listId"
        role="listbox"
        :aria-label="t('labels.teamWorkspace.switcher.label')"
        class="min-h-0 flex-1 overflow-y-auto p-1.5"
      >
        <p
          v-if="isSearching && options.length === 0"
          class="px-2 py-3 text-sm text-muted"
        >
          {{ t('labels.teamWorkspace.switcher.noMatches') }}
        </p>

        <template
          v-for="(option, index) in options"
          :key="`${option.group}-${option.team.id}`"
        >
          <div
            v-if="!isSearching && index === 0 && option.group === 'recent'"
            class="px-2 pt-1.5 pb-1 text-sm text-muted"
            role="presentation"
          >
            {{ t('labels.teamWorkspace.switcher.recent') }}
          </div>
          <div
            v-if="!isSearching && option.group === 'tree' && options[index - 1]?.group !== 'tree'"
            :class="['px-2 pb-1 text-sm text-muted', index > 0 ? 'mt-1.5 border-t border-border pt-2.5' : 'pt-1.5']"
            role="presentation"
          >
            {{ t('labels.teamWorkspace.switcher.allTeams') }}
          </div>
          <div
            :id="optionId(index)"
            role="option"
            :aria-selected="index === activeIndex"
            :class="[
              'flex h-8 cursor-pointer items-center gap-2 rounded-md pr-2 text-sm',
              index === activeIndex ? 'bg-tree-hover' : '',
              option.team.id === currentTeam?.id && option.group !== 'recent'
                ? 'font-semibold text-primary'
                : '',
            ]"
            :style="{ paddingLeft: `${0.5 + option.depth}rem` }"
            @mouseenter="activeIndex = index"
            @mousedown.prevent
            @click="choose(option.team)"
          >
            <TeamAvatar
              :name="option.team.name"
              :avatar="option.team.avatar"
              :color="option.team.color"
              size="sm"
            />
            <span class="min-w-0 flex-1 truncate">
              <HighlightedText
                :text="option.team.name"
                :highlight="option.indices"
              />
            </span>
            <span
              v-if="option.group === 'recent'"
              class="shrink-0 truncate text-sm text-muted"
            >
              {{ parentName(option.team) }}
            </span>
            <span
              v-else
              class="shrink-0 text-sm text-muted tabular-nums"
            >
              {{ t('labels.teams.spaceCount', { count: option.team.spaces_count ?? 0 }) }}
            </span>
            <Icon
              v-if="option.team.id === currentTeam?.id && option.group !== 'recent'"
              name="lucide:check"
              class="size-4 shrink-0 text-primary"
            />
          </div>
        </template>
      </div>

      <button
        v-if="canCreateSubTeam"
        type="button"
        class="flex h-10 shrink-0 cursor-pointer items-center gap-2 border-t border-border px-3 text-sm text-muted transition-colors hover:bg-tree-hover hover:text-primary focus-visible:bg-tree-hover focus-visible:outline-none"
        @click="createSubTeam"
      >
        <Icon
          name="lucide:plus"
          class="size-4"
        />
        {{ t('labels.teamWorkspace.switcher.newSubTeam') }}
      </button>
    </PopoverContent>
  </Popover>
</template>
