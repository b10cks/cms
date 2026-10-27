<script setup lang="ts">
import AiSkillEditor from '~/components/ai-settings/AiSkillEditor.vue'

const route = useRoute()
const owner = computed(() => ({ kind: 'team' as const, id: route.params.team as string }))
</script>

<template>
  <AiSkillEditor
    :owner="owner"
    :skill-id="route.params.skillId as string"
    can-manage
  />
</template>
