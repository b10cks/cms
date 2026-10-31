<script setup lang="ts">
import AiKnowledgeList from '~/components/ai-settings/AiKnowledgeList.vue'
import AiKnowledgeNewMenu from '~/components/ai-settings/AiKnowledgeNewMenu.vue'
import AiSkillsList from '~/components/ai-settings/AiSkillsList.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { TooltipIcon } from '~/components/ui/tooltip'

const route = useRoute()
const { t } = useI18n()
const teamId = computed(() => route.params.team as string)
const aiOwner = computed(() => ({ kind: 'team' as const, id: teamId.value }))

useSeoMeta({ title: computed(() => t('labels.teamWorkspace.nav.ai')) })
</script>

<template>
  <div class="content-grid">
    <ContentHeader :header="t('labels.teamWorkspace.nav.ai')" />

    <AiSkillsList
      :owner="aiOwner"
      :title="t('components.aiSettings.skills.title')"
      can-manage
    >
      <template #actions>
        <TooltipIcon>
          <span class="block max-w-sm">{{ t('components.aiSettings.skills.description') }}</span>
        </TooltipIcon>
        <Button
          as-child
          size="sm"
          variant="outline"
        >
          <RouterLink :to="{ name: 'team-ai-skill', params: { team: teamId, skillId: 'new' } }">
            <Icon name="lucide:plus" />
            {{ t('components.aiSettings.skills.create') }}
          </RouterLink>
        </Button>
      </template>
    </AiSkillsList>
    <AiKnowledgeList
      :owner="aiOwner"
      :title="t('components.aiSettings.knowledge.title')"
      can-manage
    >
      <template #actions>
        <TooltipIcon>
          <span class="block max-w-sm">{{ t('components.aiSettings.knowledge.description') }}</span>
        </TooltipIcon>
        <AiKnowledgeNewMenu
          :owner="aiOwner"
          size="sm"
        />
      </template>
    </AiKnowledgeList>
  </div>
</template>
