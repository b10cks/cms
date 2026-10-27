<script setup lang="ts">
import AiKnowledgeList from '~/components/ai-settings/AiKnowledgeList.vue'
import AiKnowledgeNewMenu from '~/components/ai-settings/AiKnowledgeNewMenu.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const owner = computed(() => ({ kind: 'space' as const, id: spaceId.value }))

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManage = computed(() => access.hasAbility('ai.manage'))

useSeoMeta({
  title: computed(() => t('components.aiSettings.knowledge.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('components.aiSettings.knowledge.title')"
      :description="$t('components.aiSettings.knowledge.description')"
    >
      <template #actions>
        <AiKnowledgeNewMenu
          v-if="canManage"
          :owner="owner"
        />
      </template>
    </ContentHeader>
    <AiKnowledgeList
      :owner="owner"
      :can-manage="canManage"
    />
  </div>
</template>
