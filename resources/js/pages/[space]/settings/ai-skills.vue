<script setup lang="ts">
import AiSchedulesPanel from '~/components/ai-settings/AiSchedulesPanel.vue'
import AiSkillsList from '~/components/ai-settings/AiSkillsList.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const owner = computed(() => ({ kind: 'space' as const, id: spaceId.value }))

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManage = computed(() => access.hasAbility('ai.manage'))

useSeoMeta({
  title: computed(() => t('components.aiSettings.skills.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('components.aiSettings.skills.title')"
      :description="$t('components.aiSettings.skills.description')"
    >
      <template #actions>
        <Button
          v-if="canManage"
          as-child
          variant="primary"
        >
          <RouterLink :to="{ name: 'space-settings-ai-skill', params: { space: spaceId, skillId: 'new' } }">
            <Icon name="lucide:plus" />
            {{ $t('components.aiSettings.skills.create') }}
          </RouterLink>
        </Button>
      </template>
    </ContentHeader>
    <AiSkillsList
      :owner="owner"
      :can-manage="canManage"
    />
    <AiSchedulesPanel
      v-if="canManage"
      :space-id="spaceId"
      class="mt-4"
    />
  </div>
</template>
