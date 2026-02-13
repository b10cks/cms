<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Badge } from '~/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { cn } from '~/lib/utils'
import type { TeamResource } from '~/types/teams'

const { selectedTeam, selectedTeamId, isLoading, selectTeam, teams } = useGlobalTeam()

withDefaults(
  defineProps<{
    size?: 'sm' | 'md' | 'lg'
  }>(),
  {
    size: 'md',
  }
)

const sizeClasses = {
  sm: 'h-8 text-sm',
  md: 'h-10',
  lg: 'h-12 text-lg',
}

const handleSelect = (value: string) => {
  if (value === 'clear') {
    selectTeam(null)
  } else {
    selectTeam(value)
  }
}

onMounted(() => {
  if (!selectTeam) {
    selectTeam(null)
  }
})

const getTeamIcon = (team: Pick<TeamResource, 'icon' | 'type'>) => {
  return team.icon ? `lucide:${team.icon}` : 'lucide:users'
}

interface TreeNode {
  team: Readonly<TeamResource>
  children: TreeNode[]
}

const hierarchicalTeams = computed(() => {
  const list = teams?.value ?? []
  const nodeMap = new Map<string, TreeNode>()
  const roots: TreeNode[] = []

  for (const t of list) {
    nodeMap.set(t.id, { team: t as Readonly<TeamResource>, children: [] })
  }

  for (const t of list) {
    const node = nodeMap.get(t.id)!
    const pid = t.parent_id ?? null
    if (pid && nodeMap.has(pid)) {
      nodeMap.get(pid)!.children.push(node)
    } else {
      roots.push(node)
    }
  }

  const sortNodes = (arr: TreeNode[]) => {
    arr.sort((a, b) => a.team.name.localeCompare(b.team.name))
    for (const n of arr) sortNodes(n.children)
  }
  sortNodes(roots)

  const flat: Array<{ team: Readonly<TeamResource>; depth: number }> = []
  const walk = (arr: TreeNode[], depth: number) => {
    for (const n of arr) {
      flat.push({ team: n.team, depth })
      if (n.children.length) walk(n.children, depth + 1)
    }
  }
  walk(roots, 0)
  return flat
})
</script>

<template>
  <Select
    aria-label="Team"
    :model-value="selectedTeamId || undefined"
    :disabled="isLoading"
    @update:model-value="handleSelect"
  >
    <SelectTrigger :class="cn('justify-between', sizeClasses[size])">
      <SelectValue>
        <div
          v-if="isLoading"
          class="flex items-center gap-2"
        >
          <Icon
            name="lucide:loader-2"
            class="shrink-0 animate-spin"
          />
          <span>{{ $t('labels.loading') }}</span>
        </div>
        <div
          v-else-if="selectedTeam"
          class="flex min-w-0 items-center gap-2 pr-2"
        >
          <Icon
            :name="getTeamIcon(selectedTeam)"
            class="shrink-0"
            :style="{ color: selectedTeam.color }"
          />
          <span class="truncate">{{ selectedTeam.name }}</span>
          <Badge
            v-if="selectedTeam.type"
            variant="surface"
            size="sm"
          >
            {{ selectedTeam.type }}
          </Badge>
        </div>
        <div
          v-else
          class="flex items-center gap-2"
        >
          <Icon
            name="lucide:users"
            class="shrink-0"
          />
          <span>{{ $t('labels.teams.selectPlaceholder') }}</span>
        </div>
      </SelectValue>
    </SelectTrigger>
    <SelectContent v-if="hierarchicalTeams.length">
      <SelectItem
        v-for="item in hierarchicalTeams"
        :key="item.team.id"
        :value="item.team.id"
        class="cursor-pointer"
      >
        <div class="flex w-full items-center justify-between gap-2">
          <div
            class="flex min-w-0 items-center gap-2"
            :style="{ paddingLeft: `${item.depth * 16}px` }"
          >
            <Icon
              :name="getTeamIcon(item.team)"
              class="shrink-0"
              :style="{ color: item.team.color }"
            />
            <span
              class="truncate"
              :style="{ color: item.team.color }"
              >{{ item.team.name }}
            </span>
          </div>
          <Badge
            v-if="item.team.type"
            variant="surface"
            size="sm"
          >
            {{ item.team.type }}
          </Badge>
        </div>
      </SelectItem>
      <SelectItem
        v-if="!(teams && teams.length) && !isLoading"
        value=""
        disabled
        class="text-muted-foreground text-center"
      >
        {{ $t('labels.teams.empty') }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
