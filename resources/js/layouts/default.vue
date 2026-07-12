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

useSpaceBroadcasts(spaceId)
useUserNotifications()
</script>

<template>
  <div class="flex min-h-svh flex-col pt-14">
    <AppHeader />
    <PendingSubscriptionBanner
      v-if="spaceId"
      :space-id="spaceId"
    />
    <div class="flex flex-1 min-h-0">
      <AppSidebar />
      <!-- overflow-x-clip (not hidden): a hidden overflow would make <main> the sticky
     containment box, breaking every position:sticky sidebar/nav inside pages -->
      <main class="flex min-h-0 grow w-[calc(100%-3.5rem)] overflow-x-clip">
        <slot />
      </main>
    </div>
  </div>
</template>
