<script setup lang="ts">
import AiKnowledgeSettings from '~/components/ai-settings/AiKnowledgeSettings.vue'
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
    <ContentHeader :header="$t('components.aiSettings.knowledge.title')" />
    <div class="divide-y divide-border pt-6">
      <AiKnowledgeSettings
        :owner="owner"
        :can-manage="canManage"
      />
    </div>
  </div>
</template>
