<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { useRoutePreload } from '~/composables/useRoutePreload'
import { spaceSettingsNavigationItems } from '~/lib/access-control'

const route = useRoute()
const router = useRouter()
const spaceId = computed<string>(() => route.params.space as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const items = computed(() => access.filterVisibleItems(spaceSettingsNavigationItems))

const { preloadRoute, cancelPreload } = useRoutePreload()
const linkTo = (routeName: string) => ({ name: routeName, params: { space: spaceId.value } })

/** Detail pages (a skill, a glossary) name the nav entry they belong to in `meta.settingsNav`. */
const activeNavItem = computed(() =>
  typeof route.meta.settingsNav === 'string' ? route.meta.settingsNav : route.name
)

watch(
  [items, activeNavItem],
  ([visibleItems, routeName]) => {
    if (visibleItems.length === 0 || typeof routeName !== 'string') {
      return
    }

    const currentIsVisible = visibleItems.some((item) => item.routeName === routeName)

    if (!currentIsVisible) {
      router.replace({
        name: visibleItems[0].routeName,
        params: { space: spaceId.value },
      })
    }
  },
  { immediate: true }
)

provide('spaceId', spaceId)
</script>

<template>
  <div class="flex h-full w-full bg-background">
    <aside class="p-6 xl:w-1/5">
      <nav class="sticky top-20 flex flex-col space-y-1">
        <RouterLink
          v-for="item in items"
          :key="item.routeName"
          :to="linkTo(item.routeName)"
          :aria-current="item.routeName === activeNavItem ? 'page' : undefined"
          :class="[
            'flex items-center gap-2 rounded-md px-4 py-2',
            'transition-colors duration-200 hover:bg-secondary',
            'cursor-pointer font-semibold whitespace-nowrap',
            item.routeName === activeNavItem && 'bg-secondary text-primary',
          ]"
          @mouseenter="preloadRoute(linkTo(item.routeName))"
          @focusin="preloadRoute(linkTo(item.routeName))"
          @mouseleave="cancelPreload(linkTo(item.routeName))"
          @focusout="cancelPreload(linkTo(item.routeName))"
        >
          <Icon
            :name="item.icon"
            class="shrink-0"
          />
          <span>{{ $t(item.label) }}</span>
        </RouterLink>
      </nav>
    </aside>
    <div class="flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
