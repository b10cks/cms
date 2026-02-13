<script setup lang="ts">
import { AvatarList } from '~/components/ui/avatar'
import type { SpaceResource } from '~/types/spaces'

const props = defineProps<{
  space: SpaceResource
}>()

const { getSpaceUsers, peekSpacePresence } = useSpacePresencePeek()

const users = computed(() => getSpaceUsers(props.space.id))

onMounted(() => {
  peekSpacePresence(props.space.id)
})
</script>

<template>
  <AvatarList
    v-if="users.length > 0"
    :users="users"
    :max="3"
    size="sm"
  />
</template>
