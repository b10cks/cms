<script setup lang="ts">
import AiDocumentEditor from '~/components/ai-settings/AiDocumentEditor.vue'

const route = useRoute()
const owner = computed(() => ({ kind: 'team' as const, id: route.params.team as string }))
</script>

<template>
  <AiDocumentEditor
    :owner="owner"
    :document-id="route.params.documentId as string"
    :initial-type="route.query.type === 'style' ? 'style' : 'reference'"
    can-manage
  />
</template>
