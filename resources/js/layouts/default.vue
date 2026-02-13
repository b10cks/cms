<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import AppSidebar from '~/components/AppSidebar.vue'

const { useCurrentSpaceQuery } = useSpaces()
const { data: currentSpace } = useCurrentSpaceQuery()

const route = useRoute()
const spaceId = computed(() => (route.params?.space as string) || null)

useSeoMeta({
  titleTemplate: (title) => {
    const parts = [title]
    if (currentSpace.value?.name) {
      parts.push(currentSpace.value.name)
    }
    parts.push('b10cks')
    return parts.filter(Boolean).join(' – ')
  },
})

provide('spaceId', spaceId)
</script>

<template>
  <div class="flex min-h-svh w-full flex-col pt-14">
    <AppHeader>
      <template #default>
        <div id="appHeader" />
      </template>
      <template #actions>
        <div id="appActions" />
      </template>
    </AppHeader>
    <div class="flex w-full grow">
      <AppSidebar />
      <main class="flex w-full grow">
        <slot />
      </main>
    </div>
  </div>
</template>
