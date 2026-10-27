<script setup lang="ts">
import AiGlossaryDetail from '~/components/ai-settings/AiGlossaryDetail.vue'

const route = useRoute()
const owner = computed(() => ({ kind: 'team' as const, id: route.params.team as string }))
</script>

<template>
  <AiGlossaryDetail
    :owner="owner"
    :glossary-id="route.params.glossaryId as string"
    can-manage
  />
</template>
