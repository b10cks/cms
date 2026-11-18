<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { animatedIcons } from '~/components/icons'
import { useRoutePreload } from '~/composables/useRoutePreload'

interface NavItem {
  title: string
  name: string
  icon: string
}

const items: NavItem[] = [
  {
    title: 'labels.automations.title',
    name: 'space-automations-index',
    icon: 'lucide:workflow',
  },
  {
    title: 'labels.automationActions.title',
    name: 'space-automation-actions',
    icon: 'lucide:zap',
  },
  {
    title: 'labels.automationExecutions.title',
    name: 'space-automation-executions',
    icon: 'lucide:history',
  },
]

const route = useRoute()
const spaceId = computed<string>(() => route.params.space as string)

const { preloadRoute, cancelPreload } = useRoutePreload()
const linkTo = (name: string) => ({ name, params: { space: spaceId.value } })

provide('spaceId', spaceId)
</script>

<template>
  <div class="flex h-full w-full flex-col bg-background md:flex-row">
    <aside class="min-w-0 px-2 pt-2 md:shrink-0 md:p-6 xl:w-1/5">
      <nav class="flex gap-1 overflow-x-auto pb-2 md:sticky md:top-20 md:flex-col md:gap-0 md:space-y-1 md:overflow-visible md:pb-0">
        <RouterLink
          v-for="item in items"
          :key="item.name"
          :to="linkTo(item.name)"
          exact-active-class="bg-secondary text-primary"
          :class="[
            'icon-anim flex items-center gap-2 rounded-md px-4 py-2',
            'transition-colors duration-200 hover:bg-secondary',
            'cursor-pointer font-semibold whitespace-nowrap',
          ]"
          @mouseenter="preloadRoute(linkTo(item.name))"
          @focusin="preloadRoute(linkTo(item.name))"
          @mouseleave="cancelPreload(linkTo(item.name))"
          @focusout="cancelPreload(linkTo(item.name))"
        >
          <component
            :is="animatedIcons[item.icon]"
            v-if="animatedIcons[item.icon]"
            :size="16"
            class="shrink-0"
          />
          <Icon
            v-else
            :name="item.icon"
            class="shrink-0"
          />
          <span>{{ $t(item.title) }}</span>
        </RouterLink>
      </nav>
    </aside>
    <div class="min-w-0 flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
