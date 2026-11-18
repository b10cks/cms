<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '~/components/ui/sheet'
import Sidebar from '~/components/ui/Sidebar.vue'
import { spaceNavigationItems } from '~/lib/access-control'

/** The destinations an urgent edit needs. Everything else stays one tap away under More. */
const PRIMARY_ROUTES = ['space', 'space-content-index', 'space-assets-index', 'space-releases']

const route = useRoute()
const { t } = useI18n()

const spaceId = computed(() => route.params.space as string | undefined)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => (spaceId.value ? { space_id: spaceId.value } : {})))

const tabs = computed(() =>
  spaceId.value
    ? access
        .filterVisibleItems(spaceNavigationItems)
        .filter((item) => PRIMARY_ROUTES.includes(item.routeName))
    : []
)

const isActive = (routeName: string) => {
  const current = String(route.name)

  return routeName === 'space'
    ? current === routeName
    : current.startsWith(routeName.replace(/-index$/, ''))
}

const moreOpen = ref(false)
const moreActive = computed(
  () => moreOpen.value || !tabs.value.some((tab) => isActive(tab.routeName))
)

// An open entry or thread is a detail view: it has its own back button and needs the height.
const isDetailView = computed(() => Boolean(route.params.contentId || route.params.thread))

watch(
  () => route.fullPath,
  () => {
    moreOpen.value = false
  }
)

const tabClass = (active: boolean) => [
  'flex h-full min-w-0 flex-1 cursor-pointer flex-col items-center justify-center gap-0.5 text-xs font-medium focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
  active ? 'text-sidebar-accent-foreground' : 'text-sidebar-muted',
]
</script>

<template>
  <nav
    v-if="!isDetailView"
    data-phone-tabbar
    :aria-label="t('labels.mainNavigation')"
    class="fixed inset-x-0 bottom-0 z-20 flex h-14 border-t border-sidebar-border bg-sidebar"
  >
    <RouterLink
      v-for="tab in tabs"
      :key="tab.routeName"
      :to="{ name: tab.routeName, params: { space: spaceId } }"
      :class="tabClass(isActive(tab.routeName))"
      :aria-current="isActive(tab.routeName) ? 'page' : undefined"
    >
      <Icon
        :name="tab.icon"
        size="20"
      />
      <span class="max-w-full truncate px-1">{{ t(tab.label) }}</span>
    </RouterLink>
    <Sheet v-model:open="moreOpen">
      <SheetTrigger :class="tabClass(moreActive)">
        <Icon
          name="lucide:ellipsis"
          size="20"
        />
        <span>{{ t('labels.navigation.more') }}</span>
      </SheetTrigger>
      <SheetContent
        side="bottom"
        class="flex max-h-[85dvh] flex-col gap-0 rounded-t-xl bg-sidebar p-0!"
        :aria-describedby="undefined"
      >
        <SheetTitle class="px-4 py-3.5 text-panel">{{ t('labels.navigation.more') }}</SheetTitle>
        <div class="flex min-h-0 flex-1 flex-col">
          <Sidebar
            labelled
            :exclude-routes="PRIMARY_ROUTES"
          />
        </div>
      </SheetContent>
    </Sheet>
  </nav>
</template>
