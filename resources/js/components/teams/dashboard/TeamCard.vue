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
 * One top team on the teams start page: a wash in the team color, the mark,
 * its subtree totals and the signals an owner acts on. The whole card opens
 * the team; sub-team chips and the menu sit above that link.
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

const wash = computed(() => ({
  backgroundImage: `radial-gradient(120% 140% at 0% 0%, color-mix(in oklch, ${accent.value} 34%, transparent), transparent 70%), linear-gradient(180deg, color-mix(in oklch, ${accent.value} 14%, transparent), transparent)`,
}))

const figures = computed(() => [
  { label: t('labels.teamsDashboard.card.people'), value: props.team.people_count },
  { label: t('labels.teamsDashboard.card.spaces'), value: props.team.spaces_count },
  { label: t('labels.teamsDashboard.card.subTeams'), value: props.team.sub_team_count },
])

const morePeople = computed(() => Math.max(props.team.people_count - props.team.people.length, 0))
const moreChildren = computed(() => Math.max(props.team.sub_team_count - props.team.children.length, 0))
</script>

<template>
  <article
    class="group relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-soft-sm transition-[border-color,box-shadow,translate] duration-200 focus-within:border-border-strong hover:-translate-y-0.5 hover:border-border-strong hover:shadow-soft"
  >
    <div
      class="relative h-24 shrink-0"
      :style="wash"
    >
      <div class="absolute top-3 right-3 z-10 flex items-center gap-1.5">
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
        <Badge
          v-if="team.type"
          type="outline"
          size="sm"
          class="bg-card/70 backdrop-blur-sm"
        >
          {{ team.type }}
        </Badge>
        <DropdownMenu v-if="team.can_update || team.can_delete">
          <DropdownMenuTrigger as-child>
            <Button
              variant="ghost"
              size="toolbar"
              class="bg-card/70 backdrop-blur-sm"
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
    </div>

    <div class="-mt-10 flex flex-1 flex-col gap-4 px-5 pb-4">
      <TeamAvatar
        :name="team.name"
        :avatar="team.avatar"
        :color="accent"
        size="xl"
        class="relative ring-4 ring-card"
      />

      <div class="min-w-0">
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
        <p
          v-if="team.description"
          class="mt-0.5 line-clamp-2 text-sm text-muted"
        >
          {{ team.description }}
        </p>
      </div>

      <dl class="grid grid-cols-3 gap-3 border-y border-border py-3">
        <div
          v-for="figure in figures"
          :key="figure.label"
          class="min-w-0"
        >
          <dt class="truncate text-sm text-muted">{{ figure.label }}</dt>
          <dd class="text-panel font-semibold text-primary tabular-nums">{{ figure.value }}</dd>
        </div>
      </dl>

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
          >
            <Icon
              name="lucide:mail"
              class="size-3.5"
            />
            {{ team.pending_invites }}
          </Badge>
          <span
            v-if="!team.teams_without_owner && team.content_updated_at"
            class="truncate text-sm text-muted"
          >
            {{ t('labels.teamsDashboard.card.edited', { time: formatRelativeTime(team.content_updated_at) }) }}
          </span>
        </div>
      </div>
    </div>
  </article>
</template>
