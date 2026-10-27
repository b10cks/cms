<script setup lang="ts">
import type { AiResolvedSkill } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { aiSkillLabel } from '~/lib/aiSkills'

defineProps<{
  skills: readonly AiResolvedSkill[]
  disabled?: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  select: [skill: AiResolvedSkill]
}>()

const { t } = useI18n()
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button
        type="button"
        size="xs"
        variant="ai"
        class="gap-1"
        :disabled="disabled || skills.length === 0"
        :loading="loading"
      >
        <Icon name="lucide:sparkles" />
        {{ $t('components.aiActions.trigger') }}
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent
      align="start"
      class="w-72"
    >
      <DropdownMenuLabel>{{ $t('components.aiActions.label') }}</DropdownMenuLabel>
      <DropdownMenuItem
        v-for="skill in skills"
        :key="skill.name"
        class="flex flex-col items-start gap-0.5"
        @select="emit('select', skill)"
      >
        <span class="font-medium">{{ aiSkillLabel(skill.name, t) }}</span>
        <span class="text-sm text-muted-foreground">{{ skill.description }}</span>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
