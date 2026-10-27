<script setup lang="ts">
import AiSkillEditor from '~/components/ai-settings/AiSkillEditor.vue'

const route = useRoute()
const spaceId = computed(() => route.params.space as string)
const owner = computed(() => ({ kind: 'space' as const, id: spaceId.value }))

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManage = computed(() => access.hasAbility('ai.manage'))
</script>

<template>
  <div class="content-grid">
    <AiSkillEditor
      :owner="owner"
      :skill-id="route.params.skillId as string"
      :can-manage="canManage"
    />
  </div>
</template>
