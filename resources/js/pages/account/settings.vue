<script setup lang="ts">
import AppHeader from '~/components/AppHeader.vue'
import Icon from '~/components/Icon.vue'
import { useRoutePreload } from '~/composables/useRoutePreload'
interface NavItem {
  title: string
  name: string
  icon: string
  disabled?: boolean
}

const items: NavItem[] = [
  {
    title: 'labels.account.profile.title',
    name: 'account-settings-index',
    icon: 'lucide:user',
  },
  {
    title: 'labels.account.invites.title',
    name: 'account-settings-invites',
    icon: 'lucide:mail',
  },
  {
    title: 'labels.account.security.title',
    name: 'account-settings-security',
    icon: 'lucide:shield',
  },
]

const { preloadRoute, cancelPreload } = useRoutePreload()
</script>

<template>
  <AppHeader />

  <div class="flex w-full grow flex-col bg-background pt-14 md:flex-row">
    <aside class="min-w-0 bg-surface md:w-64">
      <nav class="flex gap-1 overflow-x-auto p-1 md:sticky md:top-20 md:flex-col md:gap-0 md:space-y-1 md:overflow-visible">
        <RouterLink
          v-for="item in items"
          :key="item.name"
          :to="{ name: item.name }"
          class="nav-row"
          @mouseenter="preloadRoute({ name: item.name })"
          @focusin="preloadRoute({ name: item.name })"
          @mouseleave="cancelPreload({ name: item.name })"
          @focusout="cancelPreload({ name: item.name })"
        >
          <Icon
            :name="item.icon"
            class="shrink-0"
          />
          <span>{{ $t(item.title) }}</span>
        </RouterLink>
      </nav>
    </aside>
    <div class="min-w-0 flex-1">
      <RouterView />
    </div>
  </div>
</template>
