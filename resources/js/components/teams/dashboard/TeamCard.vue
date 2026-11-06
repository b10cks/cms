<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import { Avatar } from '~/components/ui/avatar'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import HighlightedText from '~/components/ui/HighlightedText.vue'
import { getPresenceColor } from '~/components/ui/presence-colors'
import type { TeamsDashboardCard } from '~/types/team-workspace'

/**
 * One top team on the teams start page, laid out like a small team overview:
 * the header (mark, name, type), the overview's KPIs for the whole subtree,
 * the sub-teams, and the signals an owner acts on. The whole card opens the
 * team; sub-team chips and the menu sit above that link.
 */
const props = defineProps<{
  team: TeamsDashboardCard
  /** Matched characters of the name, from the page search. */
  highlight?: number[]
}>()

const emit = defineEmits<{ delete: [team: TeamsDashboardCard] }>()

const { t } = useI18n()
const { formatRelativeTime } = useFormat()

/** Teams without a color get a stable one from the palette, so cards don't all look alike. */
const accent = computed(() => props.team.color || getPresenceColor(props.team.id).value)

const figures = computed(() => [
  {
    label: t('labels.teamWorkspace.kpis.members'),
    value: props.team.people_count,
    detail: t('labels.teamsDashboard.card.active', { count: props.team.active_people }),
  },
  {
    label: t('labels.teamWorkspace.kpis.spaces'),
    value: props.team.spaces_count,
    detail: t('labels.teamsDashboard.card.published', { count: props.team.published_spaces }),
  },
  {
    label: t('labels.teamsDashboard.card.subTeams'),
    value: props.team.sub_team_count,
    detail: undefined,
  },
])

const needsAttention = computed(() => props.team.teams_without_owner > 0 || props.team.pending_invites > 0)

const morePeople = computed(() => Math.max(props.team.people_count - props.team.people.length, 0))
const moreChildren = computed(() => Math.max(props.team.sub_team_count - props.team.children.length, 0))
</script>

<template>
  <article
    class="group relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-soft-sm transition-[border-color,box-shadow,translate] duration-200 focus-within:border-border-strong hover:-translate-y-0.5 hover:border-border-strong hover:shadow-soft"
  >
    <header class="flex items-start gap-3 px-5 pt-5">
      <TeamAvatar
        :name="team.name"
        :avatar="team.avatar"
        :color="accent"
        size="lg"
        class="shrink-0"
      />

      <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center gap-2">
          <h3 class="truncate text-panel font-semibold text-primary">
            <RouterLink
              :to="{ name: 'team-overview', params: { team: team.id } }"
              class="outline-none after:absolute after:inset-0 after:content-['']"
            >
              <HighlightedText
                :text="team.name"
                :highlight="highlight"
              />
            </RouterLink>
          </h3>
          <Badge
            v-if="team.type"
            type="outline"
            size="sm"
            class="shrink-0"
          >
            {{ team.type }}
          </Badge>
        </div>
        <p
          v-if="team.description"
          class="truncate text-sm text-muted"
          :title="team.description"
        >
          {{ team.description }}
        </p>
      </div>

      <div class="relative z-10 flex shrink-0 items-center gap-1.5">
        <Badge
          v-if="team.sso"
          variant="success"
          size="sm"
          class="gap-1"
          :title="t('labels.teamsDashboard.card.ssoHint')"
        >
          <Icon
            name="lucide:shield-check"
            class="size-3.5"
          />
          {{ t('labels.teamsDashboard.card.sso') }}
        </Badge>
        <DropdownMenu v-if="team.can_update || team.can_delete">
          <DropdownMenuTrigger as-child>
            <Button
              variant="ghost"
              size="toolbar"
              :aria-label="t('labels.teamsDashboard.card.actions', { name: team.name })"
            >
              <Icon name="lucide:ellipsis" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem as-child>
              <RouterLink :to="{ name: 'team-people', params: { team: team.id } }">
                <Icon name="lucide:users" />
                {{ t('labels.teamsDashboard.card.people') }}
              </RouterLink>
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="team.can_update"
              as-child
            >
              <RouterLink :to="{ name: 'team-settings', params: { team: team.id } }">
                <Icon name="lucide:settings" />
                {{ t('labels.teamsDashboard.card.settings') }}
              </RouterLink>
            </DropdownMenuItem>
            <template v-if="team.can_delete">
              <DropdownMenuSeparator />
              <DropdownMenuItem
                class="text-destructive-foreground"
                @select="emit('delete', team)"
              >
                <Icon name="lucide:trash-2" />
                {{ t('actions.delete') }}
              </DropdownMenuItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>

    <dl class="mx-5 mt-4 grid grid-cols-3 gap-3 border-y border-border py-3">
      <div
        v-for="figure in figures"
        :key="figure.label"
        class="min-w-0"
      >
        <dt class="truncate text-sm text-muted">{{ figure.label }}</dt>
        <dd class="text-figure font-semibold text-primary tabular-nums">{{ figure.value }}</dd>
        <dd
          v-if="figure.detail"
          class="truncate text-sm text-muted"
        >
          {{ figure.detail }}
        </dd>
      </div>
    </dl>

    <div class="flex flex-1 flex-col gap-4 px-5 pt-4 pb-4">
      <div
        v-if="team.children.length"
        class="relative z-10 flex flex-wrap gap-1.5"
      >
        <RouterLink
          v-for="child in team.children"
          :key="child.id"
          :to="{ name: 'team-overview', params: { team: child.id } }"
          class="flex max-w-full min-w-0 items-center gap-1.5 rounded-md bg-chip py-0.5 pr-2 pl-1 text-sm text-chip-foreground transition-colors hover:bg-tree-selected"
        >
          <TeamAvatar
            :name="child.name"
            :avatar="child.avatar"
            :color="child.color || getPresenceColor(child.id).value"
            size="sm"
          />
          <span class="truncate">{{ child.name }}</span>
        </RouterLink>
        <span
          v-if="moreChildren"
          class="flex items-center px-1 text-sm text-muted"
        >
          {{ t('labels.teamsDashboard.card.moreTeams', { count: moreChildren }) }}
        </span>
      </div>

      <div class="mt-auto flex items-center gap-3">
        <div
          v-if="team.people.length"
          class="flex items-center"
        >
          <div class="flex -space-x-1.5">
            <Avatar
              v-for="person in team.people"
              :key="person.id"
              :name="person.name"
              :avatar="person.avatar"
              :title="person.name"
              class="size-7! rounded-full! ring-2 ring-card"
            />
          </div>
          <span
            v-if="morePeople"
            class="ml-2 text-sm text-muted tabular-nums"
          >
            +{{ morePeople }}
          </span>
        </div>

        <div class="ml-auto flex min-w-0 items-center gap-1.5">
          <template v-if="needsAttention">
            <Badge
              v-if="team.teams_without_owner"
              variant="warning"
              size="sm"
              class="gap-1"
            >
              <Icon
                name="lucide:user-x"
                class="size-3.5"
              />
              {{ t('labels.teamsDashboard.card.withoutOwner', { count: team.teams_without_owner }, team.teams_without_owner) }}
            </Badge>
            <Badge
              v-if="team.pending_invites"
              variant="info"
              size="sm"
              class="gap-1"
              :title="t('labels.teamWorkspace.kpis.pendingInvites')"
            >
              <Icon
                name="lucide:mail"
                class="size-3.5"
              />
              {{ team.pending_invites }}
            </Badge>
          </template>
          <span
            v-else-if="team.content_updated_at"
            class="truncate text-sm text-muted"
          >
            {{ t('labels.teamsDashboard.card.edited', { time: formatRelativeTime(team.content_updated_at) }) }}
          </span>
        </div>
      </div>
    </div>
  </article>
</template>
