<script setup lang="ts">
import AiDocumentEditor from '~/components/ai-settings/AiDocumentEditor.vue'

const route = useRoute()
const spaceId = computed(() => route.params.space as string)
const owner = computed(() => ({ kind: 'space' as const, id: spaceId.value }))

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManage = computed(() => access.hasAbility('ai.manage'))
</script>

<template>
  <div class="content-grid">
    <AiDocumentEditor
      :owner="owner"
      :document-id="route.params.documentId as string"
      :can-manage="canManage"
      :initial-type="route.query.type === 'style' ? 'style' : 'reference'"
    />
  </div>
</template>
