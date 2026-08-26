<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import TeamOptionRow from '~/components/teams/TeamOptionRow.vue'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Spinner } from '~/components/ui/spinner'
import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { cn } from '~/lib/utils'

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
  sm: 'h-8! text-sm',
  md: 'h-10',
  lg: 'h-12 text-lg',
}

const handleSelect = (value: unknown) => {
  selectTeam(typeof value === 'string' ? value : null)
}

const hierarchicalTeams = computed(() => flattenTeamHierarchy(teams?.value?.data ?? []))
</script>

<template>
  <Select
    aria-label="Team"
    :model-value="selectedTeamId"
    :disabled="isLoading"
    @update:model-value="handleSelect"
  >
    <SelectTrigger :class="cn('justify-between', sizeClasses[size])">
      <SelectValue>
        <div
          v-if="isLoading"
          class="flex items-center gap-2"
        >
          <Spinner class="shrink-0" />
          <span>{{ $t('labels.loading') }}</span>
        </div>
        <TeamOptionRow
          v-else-if="selectedTeam"
          compact
          :name="selectedTeam.name"
          :icon="selectedTeam.icon"
          :color="selectedTeam.color"
          :type="selectedTeam.type"
        />
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
        <TeamOptionRow
          :name="item.team.name"
          :icon="item.team.icon"
          :color="item.team.color"
          :type="item.team.type"
          :depth="item.depth"
        />
      </SelectItem>
      <SelectItem
        v-if="!(teams && teams.data?.length) && !isLoading"
        value=""
        disabled
        class="text-muted-foreground text-center"
      >
        {{ $t('labels.teams.empty') }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
