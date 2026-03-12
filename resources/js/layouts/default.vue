<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import AppSidebar from '~/components/AppSidebar.vue'
import PendingSubscriptionBanner from '~/components/PendingSubscriptionBanner.vue'

const { useCurrentSpaceQuery } = useSpaces()
const { data: currentSpace } = useCurrentSpaceQuery()

const route = useRoute()
const spaceId = computed(() => (route.params?.space as string) || null)

useSeoMeta({
  titleTemplate: (title) => {
    const parts = [title]
    if (currentSpace?.value?.name) {
      parts.push(currentSpace.value.name)
    }
    parts.push('b10cks')
    return parts.filter(Boolean).join(' – ')
  },
})

provide('spaceId', spaceId)
</script>

<template>
  <div class="flex min-h-svh flex-col pt-14">
    <AppHeader>
      <template #default>
        <div id="appHeader" />
      </template>
      <template #actions>
        <div id="appActions" />
      </template>
    </AppHeader>
    <PendingSubscriptionBanner
      v-if="spaceId"
      :space-id="spaceId"
    />
    <div class="flex flex-1">
      <AppSidebar />
      <main class="flex grow w-[calc(100%-3.5rem)]">
        <slot />
      </main>
    </div>
  </div>
</template>
