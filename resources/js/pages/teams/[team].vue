<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { useRoutePreload } from '~/composables/useRoutePreload'
import { teamNavigationItems } from '~/lib/access-control'

/** The team workspace: a settings-style left nav, the section on the right. */
const route = useRoute()
const router = useRouter()
const teamId = computed<string>(() => route.params.team as string)

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ team_id: teamId.value })))
const items = computed(() => access.filterVisibleItems(teamNavigationItems))

const { useTeamPeopleQuery } = usePeople()
const { useTeamOverviewQuery } = useTeamWorkspace()
const canSeePeople = computed(() => items.value.some((item) => item.routeName === 'team-people'))
const { data: people } = useTeamPeopleQuery(teamId, { per_page: 1 }, canSeePeople)
const { data: overview } = useTeamOverviewQuery(teamId)

const counts = computed<Record<string, number | undefined>>(() => ({
  'team-people': people.value?.counts.members,
  'team-spaces': overview.value?.kpis.spaces.total,
}))

const { preloadRoute, cancelPreload } = useRoutePreload()
const linkTo = (routeName: string) => ({ name: routeName, params: { team: teamId.value } })

/** Detail pages (a skill, a glossary) name the nav entry they belong to in `meta.settingsNav`. */
const activeNavItem = computed(() =>
  typeof route.meta.settingsNav === 'string' ? route.meta.settingsNav : route.name
)

watch(
  [items, activeNavItem],
  ([visibleItems, routeName]) => {
    if (visibleItems.length === 0 || typeof routeName !== 'string') return

    if (!visibleItems.some((item) => item.routeName === routeName)) {
      router.replace({ name: visibleItems[0].routeName, params: { team: teamId.value } })
    }
  },
  { immediate: true }
)

const { selectTeam, findTeamById } = useGlobalTeam()
watch(
  [teamId, () => findTeamById(teamId.value)],
  ([id, team]) => {
    if (team) selectTeam(id)
  },
  { immediate: true }
)

provide('teamId', teamId)
</script>

<template>
  <div class="flex min-h-full w-full bg-background">
    <aside class="shrink-0 p-6 xl:w-1/5">
      <nav class="sticky top-20 flex flex-col space-y-1">
        <RouterLink
          v-for="item in items"
          :key="item.routeName"
          :to="linkTo(item.routeName)"
          :aria-current="item.routeName === activeNavItem ? 'page' : undefined"
          :class="[
            'icon-anim flex items-center gap-2 rounded-md px-4 py-2',
            'transition-colors duration-200 hover:bg-secondary',
            'cursor-pointer font-semibold whitespace-nowrap',
            item.routeName === activeNavItem && 'bg-secondary text-primary',
          ]"
          @mouseenter="preloadRoute(linkTo(item.routeName))"
          @focusin="preloadRoute(linkTo(item.routeName))"
          @mouseleave="cancelPreload(linkTo(item.routeName))"
          @focusout="cancelPreload(linkTo(item.routeName))"
        >
          <Icon
            :name="item.icon"
            class="shrink-0"
          />
          <span class="flex-1">{{ $t(item.label) }}</span>
          <span
            v-if="counts[item.routeName] !== undefined"
            class="text-sm font-normal text-muted tabular-nums"
          >
            {{ counts[item.routeName] }}
          </span>
        </RouterLink>
      </nav>
    </aside>
    <div class="min-w-0 flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
