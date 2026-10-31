<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Skeleton } from '~/components/ui/skeleton'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { nestTeams, type TeamTreeNode } from '~/lib/team-switcher'
import type {
  AccessMatrixCell,
  AccessMatrixPerson,
  AccessMatrixSpace,
} from '~/types/team-workspace'

/**
 * People by spaces of a team's subtree. Inherited access (from a team role)
 * is a dashed muted chip, a direct space role a solid one. Editable cells
 * open a role menu; read-only cells explain why in a tooltip.
 */
const props = defineProps<{
  teamId: string
}>()

const { t } = useI18n()
const { formatDate } = useFormat()
const { useAccessMatrixQuery, useSetSpaceRoleMutation } = useTeamWorkspace()

const ALL = '__all'
const filter = ref<string>(ALL)
const filterTeamId = computed(() => (filter.value === ALL ? null : filter.value))

const { data: matrix, isLoading, isFetching } = useAccessMatrixQuery(
  computed(() => props.teamId),
  filterTeamId
)
const setRole = useSetSpaceRoleMutation()

const teamOptions = computed(() => {
  const rows: { id: string; name: string; depth: number }[] = []
  const walk = (nodes: TeamTreeNode<{ id: string; name: string; parent_id: string | null }>[], depth: number) => {
    for (const node of nodes) {
      rows.push({ id: node.team.id, name: node.team.name, depth })
      walk(node.children, depth + 1)
    }
  }
  walk(nestTeams(matrix.value?.teams ?? []), 0)
  return rows
})

const reason = (cell: AccessMatrixCell) =>
  cell.reason ? t(`labels.teamWorkspace.matrix.reasons.${cell.reason}`) : ''

const inheritedLabel = (cell: AccessMatrixCell) =>
  cell.inherited
    ? t('labels.teamWorkspace.matrix.inherited', {
        role: cell.inherited.name,
        team: cell.inherited.via ?? '',
      })
    : ''

const change = (person: AccessMatrixPerson, space: AccessMatrixSpace, role: string | null) => {
  const cell = person.cells[space.id]
  if (role === (cell.direct?.key ?? null)) return
  setRole.mutate({
    teamId: props.teamId,
    spaceId: space.id,
    userId: person.id,
    role,
    isMember: !!cell.direct,
  })
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <Select
        v-if="teamOptions.length > 1"
        v-model="filter"
      >
        <SelectTrigger
          class="w-64"
          :aria-label="t('labels.teamWorkspace.matrix.filter')"
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem :value="ALL">
            {{ t('labels.teamWorkspace.matrix.allTeams') }}
          </SelectItem>
          <SelectItem
            v-for="team in teamOptions"
            :key="team.id"
            :value="team.id"
          >
            <span :style="{ paddingLeft: `${team.depth}rem` }">{{ team.name }}</span>
          </SelectItem>
        </SelectContent>
      </Select>
      <div class="flex items-center gap-4 text-sm text-muted">
        <span class="flex items-center gap-2">
          <span class="h-5 w-8 rounded border border-dashed border-border-strong" />
          {{ t('labels.teamWorkspace.matrix.legendInherited') }}
        </span>
        <span class="flex items-center gap-2">
          <span class="h-5 w-8 rounded bg-input" />
          {{ t('labels.teamWorkspace.matrix.legendDirect') }}
        </span>
      </div>
    </div>

    <Skeleton
      v-if="isLoading"
      class="h-48 w-full"
    />

    <p
      v-else-if="matrix && matrix.spaces.length === 0"
      class="rounded-lg border border-border py-10 text-center text-sm text-muted"
    >
      {{ t('labels.teamWorkspace.matrix.empty') }}
    </p>

    <div
      v-else-if="matrix"
      :class="[
        'overflow-x-auto rounded-lg border border-border transition-opacity duration-200',
        isFetching && 'opacity-60',
      ]"
    >
      <table class="w-full border-collapse text-sm">
        <thead>
          <tr class="border-b border-border">
            <th
              scope="col"
              class="sticky left-0 z-10 min-w-56 bg-card px-4 py-3 text-left font-medium text-muted"
            >
              {{ t('labels.teamWorkspace.matrix.person') }}
            </th>
            <th
              v-for="space in matrix.spaces"
              :key="space.id"
              scope="col"
              class="min-w-36 px-3 py-3 text-left align-bottom font-medium"
            >
              <span class="block truncate text-primary">{{ space.name }}</span>
              <span class="block truncate font-normal text-muted">{{ space.team_name }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="person in matrix.people"
            :key="person.id"
            class="border-b border-border last:border-b-0"
          >
            <th
              scope="row"
              class="sticky left-0 z-10 bg-card px-4 py-2 text-left font-normal"
            >
              <div class="flex items-center gap-3">
                <Avatar
                  :name="person.name"
                  :avatar="person.avatar"
                  class="size-7 rounded-full"
                />
                <div class="min-w-0">
                  <div class="truncate font-medium text-primary">{{ person.name }}</div>
                  <div class="truncate text-muted">
                    {{ person.team_role ? `${person.team_role.name}, ${person.team_role.via}` : person.email }}
                  </div>
                </div>
              </div>
            </th>
            <td
              v-for="space in matrix.spaces"
              :key="space.id"
              class="px-3 py-2"
            >
              <div class="flex items-center gap-1">
                <DropdownMenu v-if="person.cells[space.id].direct && person.cells[space.id].editable">
                  <DropdownMenuTrigger
                    class="flex h-7 max-w-full cursor-pointer items-center gap-1 rounded-md bg-input px-2 text-primary hover:bg-tree-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="t('labels.teamWorkspace.matrix.edit', { name: person.name, space: space.name })"
                  >
                    <span class="truncate">{{ person.cells[space.id].direct?.name }}</span>
                    <Icon
                      v-if="person.cells[space.id].expires_at"
                      name="lucide:clock"
                      class="shrink-0 text-warning-foreground"
                    />
                    <Icon
                      name="lucide:chevron-down"
                      class="size-4 shrink-0 text-muted"
                    />
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start">
                    <DropdownMenuLabel v-if="person.cells[space.id].expires_at">
                      {{ t('labels.teamWorkspace.access.until', { date: formatDate(person.cells[space.id].expires_at as string) }) }}
                    </DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                      :model-value="person.cells[space.id].direct?.key"
                      @update:model-value="(role) => change(person, space, role as string)"
                    >
                      <DropdownMenuRadioItem
                        v-for="role in space.assignable_roles"
                        :key="role.key"
                        :value="role.key"
                      >
                        {{ role.name }}
                      </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      class="text-destructive"
                      @select="change(person, space, null)"
                    >
                      <Icon name="lucide:x" />
                      {{ t('labels.teamWorkspace.matrix.removeDirect') }}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>

                <SimpleTooltip
                  v-else-if="person.cells[space.id].direct"
                  :tooltip="reason(person.cells[space.id])"
                >
                  <span class="flex h-7 items-center rounded-md bg-input px-2 text-primary">
                    <span class="truncate">{{ person.cells[space.id].direct?.name }}</span>
                  </span>
                </SimpleTooltip>

                <SimpleTooltip
                  v-else-if="person.cells[space.id].inherited"
                  :tooltip="inheritedLabel(person.cells[space.id])"
                >
                  <span
                    class="flex h-7 items-center rounded-md border border-dashed border-border-strong px-2 text-muted"
                  >
                    <span class="truncate">{{ person.cells[space.id].inherited?.name }}</span>
                  </span>
                </SimpleTooltip>

                <DropdownMenu v-if="!person.cells[space.id].direct && person.cells[space.id].editable">
                  <DropdownMenuTrigger as-child>
                    <Button
                      size="icon"
                      variant="ghost"
                      class="size-7"
                      :aria-label="t('labels.teamWorkspace.matrix.add', { name: person.name, space: space.name })"
                    >
                      <Icon name="lucide:plus" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start">
                    <DropdownMenuLabel>{{ t('labels.teamWorkspace.matrix.addTitle') }}</DropdownMenuLabel>
                    <DropdownMenuItem
                      v-for="role in space.assignable_roles"
                      :key="role.key"
                      @select="change(person, space, role.key)"
                    >
                      {{ role.name }}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
