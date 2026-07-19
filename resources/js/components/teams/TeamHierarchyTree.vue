<script setup lang="ts">
import type { TreeItemToggleEvent } from 'reka-ui'
import { TreeItem, TreeRoot } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import type { TeamHierarchyItem } from '~/types/teams'

const props = defineProps<{
  selectedTeamId?: string
  title?: string
}>()

const emit = defineEmits<{
  select: [teamId: string]
}>()

const { useTeamHierarchyQuery } = useTeams()
const { data: hierarchyData, isLoading, error } = useTeamHierarchyQuery()

const expandedTeams = ref<string[]>([])

const rootItems = computed(() => {
  if (!hierarchyData.value) return []
  return hierarchyData.value
})

const getChildren = (item: TeamHierarchyItem): TeamHierarchyItem[] => {
  return item.children || []
}

const getKey = (item: TeamHierarchyItem): string => {
  return item.id
}

const handleSelect = (teamId: string) => {
  emit('select', teamId)
}

const handleToggle = (e: TreeItemToggleEvent<TeamHierarchyItem>) => {
  if (e.detail.originalEvent instanceof PointerEvent) {
    e.preventDefault()
  }
}

const toggleExpanded = (teamId: string) => {
  const index = expandedTeams.value.indexOf(teamId)
  if (index > -1) {
    expandedTeams.value.splice(index, 1)
  } else {
    expandedTeams.value.push(teamId)
  }
}
</script>

<template>
  <div>
    <div
      v-if="isLoading"
      class="flex items-center justify-center py-4"
    >
      <span class="text-sm text-muted">{{ $t('labels.loading') }}</span>
    </div>

    <div
      v-else-if="error"
      class="flex items-center gap-2 px-2 py-4 text-sm text-destructive"
    >
      <Icon name="lucide:alert-circle" />
      {{ $t('labels.teams.hierarchyError') }}
    </div>

    <TreeRoot
      v-slot="{ flattenItems }"
      v-model:expanded="expandedTeams"
      class="w-full list-none p-2 select-none"
      :items="rootItems"
      :get-children="getChildren"
      :get-key="getKey"
    >
      <h2
        v-if="title && !isLoading"
        class="px-2 pt-1 pb-3 text-sm font-semibold text-primary"
      >
        {{ title }}
      </h2>

      <TreeItem
        v-for="item in flattenItems"
        v-slot="{ isExpanded }"
        :key="item._id"
        :style="{ 'padding-left': `${item.level - 0.5}rem` }"
        v-bind="item.bind"
        :class="[
          'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none',
          'transition-colors duration-200 hover:bg-border',
          'cursor-pointer',
          item.value.id === selectedTeamId ? 'bg-border text-primary' : '',
        ]"
        @select="handleSelect(item.value.id)"
        @toggle="handleToggle"
      >
        <button
          v-if="item.value.children && item.value.children.length > 0"
          class="h-4 w-3"
          @click.stop.prevent="toggleExpanded(item.value.id)"
        >
          <Icon
            name="lucide:chevron-right"
            :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
          />
        </button>
        <span
          v-else
          class="size-3"
        />

        <span
          class="flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-md bg-surface"
          :style="!item.value.avatar && item.value.color ? { backgroundColor: `${item.value.color}26` } : undefined"
        >
          <NuxtImg
            v-if="item.value.avatar"
            :src="item.value.avatar"
            :alt="item.value.name"
            :width="24"
            :height="24"
            class="size-full object-cover"
          />
          <Icon
            v-else
            :name="`lucide:${item.value.icon || 'users'}`"
            :style="{ color: item.value.color || undefined }"
            class="text-muted"
          />
        </span>

        <span class="truncate">{{ item.value.name }}</span>

        <div
          class="ml-auto flex shrink-0 items-center gap-3 text-xs text-muted tabular-nums"
        >
          <span
            v-if="item.value.user_count"
            class="flex items-center gap-1"
            :title="$t('labels.teams.memberCount', { count: item.value.user_count })"
          >
            <Icon name="lucide:users" />
            {{ item.value.user_count }}
          </span>
          <span
            v-if="item.value.spaces_count"
            class="flex items-center gap-1"
            :title="$t('labels.teams.spaceCount', { count: item.value.spaces_count })"
          >
            <Icon name="lucide:box" />
            {{ item.value.spaces_count }}
          </span>
        </div>
      </TreeItem>
    </TreeRoot>
  </div>
</template>
