<script setup lang="ts">
import type { AiResolvedSkill } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import { CommandGroup, CommandItem, useCommand } from '~/components/ui/command'
import { aiSkillLabel } from '~/lib/aiSkills'

const props = defineProps<{
  spaceId: string
}>()

const emit = defineEmits<{
  start: [task: { prompt: string; skill: AiResolvedSkill | null }]
}>()

const { t } = useI18n()
const { filterState } = useCommand()
const { data: skills } = useAiSurfaceSkills(() => props.spaceId, 'cmdk')

const search = computed(() => filterState.search.trim())
</script>

<template>
  <CommandItem
    v-if="search"
    :key="`ask:${search}`"
    value="ai:ask"
    class="flex items-center gap-2"
    @select="emit('start', { prompt: search, skill: null })"
  >
    <Icon
      name="lucide:sparkles"
      class="text-ai"
    />
    <span class="truncate">{{ $t('components.aiCommand.ask', { prompt: search }) }}</span>
  </CommandItem>
  <CommandGroup
    v-if="skills?.length"
    :heading="$t('components.aiCommand.skills')"
  >
    <CommandItem
      v-for="skill in skills"
      :key="skill.name"
      :value="`ai:skill:${skill.name}`"
      class="flex items-center gap-2"
      @select="emit('start', { prompt: search, skill })"
    >
      <Icon
        name="lucide:sparkles"
        class="text-ai"
      />
      <span class="font-medium">{{ aiSkillLabel(skill.name, t) }}</span>
      <span class="truncate text-sm text-muted">{{ skill.description }}</span>
    </CommandItem>
  </CommandGroup>
</template>
