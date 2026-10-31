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
  <div class="flex h-full w-full bg-background">
    <aside class="p-6 xl:w-1/5">
      <nav class="sticky top-20 flex flex-col space-y-1">
        <RouterLink
          v-for="item in items"
          :key="item.routeName"
          :to="linkTo(item.routeName)"
          :aria-current="item.routeName === route.name ? 'page' : undefined"
          :class="[
            'flex items-center gap-2 rounded-md px-4 py-2',
            'transition-colors duration-200 hover:bg-secondary',
            'cursor-pointer font-semibold whitespace-nowrap',
            item.routeName === route.name && 'bg-secondary text-primary',
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
    <div class="min-w-0 flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
