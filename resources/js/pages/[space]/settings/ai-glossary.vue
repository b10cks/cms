<script setup lang="ts">
import AiGlossaryDetail from '~/components/ai-settings/AiGlossaryDetail.vue'

const route = useRoute()
const spaceId = computed(() => route.params.space as string)
const owner = computed(() => ({ kind: 'space' as const, id: spaceId.value }))

const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManage = computed(() => access.hasAbility('ai.manage'))

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)
const languages = computed(() => {
  const settings = space.value?.settings
  return (settings?.languages ?? [])
    .map((language) => language.code)
    .filter((code) => code !== settings?.default_language)
})
</script>

<template>
  <div class="content-grid">
    <AiGlossaryDetail
      :owner="owner"
      :glossary-id="route.params.glossaryId as string"
      :can-manage="canManage"
      :languages="languages"
    />
  </div>
</template>
