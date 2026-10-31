<script setup lang="ts">
import TeamAvatar from '~/components/teams/TeamAvatar.vue'
import { Avatar } from '~/components/ui/avatar'
import type { TeamTreeNode } from '~/lib/team-switcher'
import type { TeamStructureNode } from '~/types/team-workspace'

/** One team in the org chart, with its children below it. Recursive. */
defineProps<{
  node: TeamTreeNode<TeamStructureNode>
  currentTeamId: string
  draggingId: string | null
  dropTargetId: string | null
}>()

const emit = defineEmits<{
  dragstart: [teamId: string, event: DragEvent]
  dragend: []
  dragover: [teamId: string, event: DragEvent]
  dragleave: [teamId: string]
  drop: [teamId: string]
}>()
</script>

<template>
  <li class="org-node">
    <div
      :draggable="node.team.can_move"
      :aria-grabbed="node.team.can_move ? draggingId === node.team.id : undefined"
      :class="[
        'w-52 rounded-lg border bg-card text-left shadow-soft-sm transition-colors',
        node.team.id === currentTeamId ? 'border-accent' : 'border-border',
        node.team.can_move && 'cursor-grab active:cursor-grabbing',
        draggingId === node.team.id && 'opacity-50',
        dropTargetId === node.team.id && 'bg-tree-drop ring-1 ring-ring/40',
      ]"
      @dragstart="emit('dragstart', node.team.id, $event)"
      @dragend="emit('dragend')"
      @dragover="emit('dragover', node.team.id, $event)"
      @dragleave="emit('dragleave', node.team.id)"
      @drop.prevent="emit('drop', node.team.id)"
    >
      <div class="flex flex-col gap-2 p-3">
        <RouterLink
          :to="{ name: 'team-overview', params: { team: node.team.id } }"
          class="flex min-w-0 items-center gap-2 font-semibold text-primary hover:underline"
          draggable="false"
        >
          <TeamAvatar
            :name="node.team.name"
            :avatar="node.team.avatar"
            :color="node.team.color"
            size="sm"
          />
          <span class="truncate">{{ node.team.name }}</span>
        </RouterLink>
        <div class="flex items-center justify-between gap-2">
          <div class="flex -space-x-1">
            <Avatar
              v-for="person in node.team.people"
              :key="person.id"
              :name="person.name"
              :avatar="person.avatar"
              size="sm"
              class="size-5 rounded-full ring-2 ring-card"
              :title="person.name"
            />
          </div>
          <span class="text-sm text-muted tabular-nums">
            {{ $t('labels.teamWorkspace.structure.people', { count: node.team.people_count }) }}
          </span>
        </div>
      </div>
      <ul
        v-if="node.team.spaces.length"
        class="flex flex-col gap-1 border-t border-border px-3 py-2"
      >
        <li
          v-for="space in node.team.spaces"
          :key="space.id"
          class="flex min-w-0 items-center gap-2 text-sm"
        >
          <span
            :class="['size-1.5 shrink-0 rounded-full', space.published ? 'bg-success' : 'bg-muted']"
            :title="
              space.published
                ? $t('labels.teamWorkspace.structure.published')
                : $t('labels.teamWorkspace.structure.unpublished')
            "
          />
          <RouterLink
            :to="{ name: 'space', params: { space: space.id } }"
            class="truncate hover:underline"
            draggable="false"
          >
            {{ space.name }}
          </RouterLink>
        </li>
      </ul>
    </div>
    <ul
      v-if="node.children.length"
      class="org-children"
    >
      <TeamStructureCard
        v-for="child in node.children"
        :key="child.team.id"
        :node="child"
        :current-team-id="currentTeamId"
        :dragging-id="draggingId"
        :drop-target-id="dropTargetId"
        @dragstart="(id, event) => emit('dragstart', id, event)"
        @dragend="emit('dragend')"
        @dragover="(id, event) => emit('dragover', id, event)"
        @dragleave="(id) => emit('dragleave', id)"
        @drop="(id) => emit('drop', id)"
      />
    </ul>
  </li>
</template>
