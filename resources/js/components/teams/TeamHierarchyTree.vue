<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { TreeItemToggleEvent } from 'reka-ui'
import { TreeItem, TreeRoot } from 'reka-ui'
import IconName from '~/components/ui/IconName.vue'
import { Badge } from '~/components/ui/badge'
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
      class="px-2 py-4 text-sm text-destructive"
    >
      {{ error }}
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

        <IconName
          :icon="item.value.icon || 'users'"
          :color="item.value.color || undefined"
          :name="item.value.name"
          class="shrink-0"
        />

        <div class="ml-auto flex items-center gap-1">
          <Badge
            v-if="item.value.user_count"
            size="sm"
            variant="outline"
            class="gap-1"
          >
            <Icon name="lucide:users" />
            {{ item.value.user_count }}
          </Badge>
          <Badge
            v-if="item.value.spaces_count"
            size="sm"
            variant="outline"
            class="gap-1"
          >
            <Icon name="lucide:box" />
            {{ item.value.spaces_count }}
          </Badge>
        </div>
      </TreeItem>
    </TreeRoot>
  </div>
</template>
