<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import AppSidebar from '~/components/AppSidebar.vue'
import PendingSubscriptionBanner from '~/components/PendingSubscriptionBanner.vue'
import { runtimeConfig } from '~/lib/runtime-config'

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

const { t } = useI18n()
const { settings } = useUserSettings()

/** The first usable search/filter field of the page currently in `<main>`. */
const focusPageSearch = () => {
  const root = document.getElementById('main-content')
  if (!root) return

  const candidates = root.querySelectorAll<HTMLInputElement>(
    '[data-shortcut-search], input[type="search"]'
  )

  for (const input of candidates) {
    if (input.disabled || input.offsetParent === null) continue

    input.focus()
    input.select()
    return
  }
}

useShortcut({
  keys: '/',
  description: () => t('shortcuts.global.search'),
  handler: focusPageSearch,
})

useShortcut({
  keys: 'mod+b',
  description: () => t('shortcuts.global.toggleSidebar'),
  handler: () => {
    settings.extendedSidebar = !settings.extendedSidebar
  },
})
</script>

<template>
  <div class="flex min-h-svh flex-col pt-14">
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-primary focus:shadow-soft-lg focus:ring-1 focus:ring-ring focus:outline-none"
    >
      {{ $t('shortcuts.global.skipToContent') }}
    </a>
    <AppHeader />
    <PendingSubscriptionBanner
      v-if="spaceId && runtimeConfig.public.features.billing"
      :space-id="spaceId"
    />
    <div class="flex flex-1 min-h-0">
      <AppSidebar />
      <!-- overflow-x-clip (not hidden): a hidden overflow would make <main> the sticky
     containment box, breaking every position:sticky sidebar/nav inside pages -->
      <main
        id="main-content"
        tabindex="-1"
        class="flex min-h-0 grow w-[calc(100%-3.5rem)] overflow-x-clip focus:outline-none"
      >
        <slot />
      </main>
    </div>
  </div>
</template>
