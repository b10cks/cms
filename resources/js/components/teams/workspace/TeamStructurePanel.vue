<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import TeamStructureCard from '~/components/teams/workspace/TeamStructureCard.vue'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { canMoveTeam, nestTeams, type TeamTreeNode } from '~/lib/team-switcher'
import type { TeamStructureNode } from '~/types/team-workspace'

/**
 * The structure panel of the team overview: an org chart of the team's
 * subtree with spaces as leaves, or an indented list. Dropping a team on
 * another emits `move`; the page confirms it.
 */
const props = defineProps<{
  nodes: TeamStructureNode[] | undefined
  currentTeamId: string
  peopleCount: number | null
}>()

const emit = defineEmits<{
  move: [teamId: string, parentId: string]
}>()

const { t } = useI18n()

const mode = ref<'chart' | 'list'>('chart')
const draggingId = ref<string | null>(null)
const dropTargetId = ref<string | null>(null)

const nodes = computed(() => props.nodes ?? [])
const tree = computed(() => nestTeams(nodes.value))
const byId = computed(() => new Map(nodes.value.map((node) => [node.id, node])))
const canDrag = computed(() => nodes.value.some((node) => node.can_move))
const spaceCount = computed(() => nodes.value.reduce((sum, node) => sum + node.spaces.length, 0))

interface ListRow {
  node: TeamStructureNode
  depth: number
}

const listRows = computed<ListRow[]>(() => {
  const rows: ListRow[] = []
  const walk = (items: TeamTreeNode<TeamStructureNode>[], depth: number) => {
    for (const item of items) {
      rows.push({ node: item.team, depth })
      walk(item.children, depth + 1)
    }
  }
  walk(tree.value, 0)
  return rows
})

const accepts = (targetId: string) =>
  !!draggingId.value &&
  !!byId.value.get(targetId)?.can_adopt &&
  canMoveTeam(nodes.value, draggingId.value, targetId)

const onDragStart = (teamId: string, event: DragEvent) => {
  draggingId.value = teamId
  event.dataTransfer?.setData('text/plain', teamId)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

const onDragOver = (teamId: string, event: DragEvent) => {
  if (!accepts(teamId)) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
  dropTargetId.value = teamId
}

const onDragLeave = (teamId: string) => {
  if (dropTargetId.value === teamId) dropTargetId.value = null
}

const reset = () => {
  draggingId.value = null
  dropTargetId.value = null
}

const onDrop = (teamId: string) => {
  const moved = draggingId.value
  const ok = accepts(teamId)
  reset()
  if (moved && ok) emit('move', moved, teamId)
}

const meta = computed(() =>
  props.nodes
    ? [
        t('labels.teamWorkspace.structure.teams', { count: nodes.value.length }),
        t('labels.teams.spaceCount', { count: spaceCount.value }),
        props.peopleCount === null
          ? null
          : t('labels.teamWorkspace.structure.people', { count: props.peopleCount }),
      ]
        .filter(Boolean)
        .join(', ')
    : undefined
)
</script>

<template>
  <Panel
    :title="t('labels.teamWorkspace.structure.title')"
    :meta="meta"
  >
    <template #actions>
      <span
        v-if="canDrag"
        class="hidden text-sm text-muted lg:inline"
      >
        {{ t('labels.teamWorkspace.structure.hint') }}
      </span>
      <Tabs v-model="mode">
        <TabsList>
          <TabsTrigger value="chart">
            <Icon name="lucide:network" />
            {{ t('labels.teamWorkspace.structure.chart') }}
          </TabsTrigger>
          <TabsTrigger value="list">
            <Icon name="lucide:list-tree" />
            {{ t('labels.teamWorkspace.structure.list') }}
          </TabsTrigger>
        </TabsList>
      </Tabs>
    </template>

    <div
      v-if="!nodes.length"
      class="flex justify-center py-10"
    >
      <Skeleton class="h-32 w-52" />
    </div>

    <div
      v-else-if="mode === 'chart'"
      class="-mx-5 -mb-5 overflow-x-auto bg-[radial-gradient(var(--color-border)_1px,transparent_1px)] bg-size-[16px_16px] px-5 py-8"
    >
      <ul class="org-chart">
        <TeamStructureCard
          v-for="root in tree"
          :key="root.team.id"
          :node="root"
          :current-team-id="currentTeamId"
          :dragging-id="draggingId"
          :drop-target-id="dropTargetId"
          @dragstart="onDragStart"
          @dragend="reset"
          @dragover="onDragOver"
          @dragleave="onDragLeave"
          @drop="onDrop"
        />
      </ul>
    </div>

    <ul
      v-else
      class="flex flex-col"
    >
      <template
        v-for="row in listRows"
        :key="row.node.id"
      >
        <li
          :draggable="row.node.can_move"
          :class="[
            'flex h-8 items-center gap-2 rounded-md pr-2 transition-colors hover:bg-tree-hover',
            row.node.can_move && 'cursor-grab',
            draggingId === row.node.id && 'opacity-50',
            dropTargetId === row.node.id && 'bg-tree-drop ring-1 ring-ring/40',
            row.node.id === currentTeamId && 'bg-tree-selected font-semibold text-tree-selected-foreground',
          ]"
          :style="{ paddingLeft: `${0.5 + row.depth * 1.25}rem` }"
          @dragstart="onDragStart(row.node.id, $event)"
          @dragend="reset"
          @dragover="onDragOver(row.node.id, $event)"
          @dragleave="onDragLeave(row.node.id)"
          @drop.prevent="onDrop(row.node.id)"
        >
          <TeamAvatar
            :name="row.node.name"
            :avatar="row.node.avatar"
            :color="row.node.color"
            size="sm"
          />
          <RouterLink
            :to="{ name: 'team-overview', params: { team: row.node.id } }"
            class="min-w-0 flex-1 truncate hover:underline"
            draggable="false"
          >
            {{ row.node.name }}
          </RouterLink>
          <span class="shrink-0 text-sm text-muted tabular-nums">
            {{ t('labels.teamWorkspace.structure.people', { count: row.node.people_count }) }}
          </span>
        </li>
        <li
          v-for="space in row.node.spaces"
          :key="space.id"
          class="flex h-8 items-center gap-2 rounded-md pr-2 text-sm transition-colors hover:bg-tree-hover"
          :style="{ paddingLeft: `${1 + (row.depth + 1) * 1.25}rem` }"
        >
          <span
            :class="['size-1.5 shrink-0 rounded-full', space.published ? 'bg-success' : 'bg-muted']"
          />
          <RouterLink
            :to="{ name: 'space', params: { space: space.id } }"
            class="min-w-0 truncate hover:underline"
          >
            {{ space.name }}
          </RouterLink>
        </li>
      </template>
    </ul>
  </Panel>
</template>

<style scoped>
.org-chart {
  display: flex;
  justify-content: center;
  width: max-content;
  min-width: 100%;
}

.org-chart :deep(.org-children) {
  display: flex;
  justify-content: center;
  position: relative;
  padding-top: 1.5rem;
}

.org-chart :deep(.org-node) {
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  padding: 1.5rem 0.5rem 0;
}

.org-chart > :deep(.org-node) {
  padding-top: 0;
}

.org-chart :deep(.org-children > .org-node::before),
.org-chart :deep(.org-children > .org-node::after) {
  content: '';
  position: absolute;
  top: 0;
  right: 50%;
  width: 50%;
  height: 1.5rem;
  border-top: 1px solid var(--color-border-strong);
}

.org-chart :deep(.org-children > .org-node::after) {
  right: auto;
  left: 50%;
  border-left: 1px solid var(--color-border-strong);
}

.org-chart :deep(.org-children > .org-node:only-child::before) {
  display: none;
}

.org-chart :deep(.org-children > .org-node:only-child::after) {
  border-top: 0;
}

.org-chart :deep(.org-children > .org-node:first-child:not(:only-child)::before),
.org-chart :deep(.org-children > .org-node:last-child:not(:only-child)::after) {
  border: 0 none;
}

.org-chart :deep(.org-children > .org-node:last-child:not(:only-child)::before) {
  border-right: 1px solid var(--color-border-strong);
  border-top-right-radius: 6px;
}

.org-chart :deep(.org-children > .org-node:first-child:not(:only-child)::after) {
  border-top-left-radius: 6px;
}

.org-chart :deep(.org-node > .org-children::before) {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  height: 1.5rem;
  border-left: 1px solid var(--color-border-strong);
}
</style>
