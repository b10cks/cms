<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { useRoutePreload } from '~/composables/useRoutePreload'
import { providerNavigationItems } from '~/lib/access-control'

const route = useRoute()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({})))
const items = computed(() => access.filterVisibleItems(providerNavigationItems))

const { preloadRoute, cancelPreload } = useRoutePreload()
const linkTo = (routeName: string) => ({ name: routeName })
</script>

<template>
  <Teleport
    defer
    to="#appHeader"
  >
    <Badge
      variant="warning"
      class="gap-1.5"
    >
      <Icon
        name="lucide:shield"
        size="0.875rem"
      />
      {{ $t('providerConsole.badge') }}
    </Badge>
  </Teleport>
  <div class="flex h-full w-full flex-col bg-background md:flex-row">
    <aside class="min-w-0 px-2 pt-2 md:shrink-0 md:p-6 xl:w-1/5">
      <nav class="flex gap-1 overflow-x-auto pb-2 md:sticky md:top-20 md:flex-col md:gap-0 md:space-y-1 md:overflow-visible md:pb-0">
        <RouterLink
          v-for="item in items"
          :key="item.routeName"
          :to="linkTo(item.routeName)"
          :aria-current="item.routeName === route.name ? 'page' : undefined"
          class="nav-row"
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
    <div class="min-w-0 flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
