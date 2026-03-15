<script setup lang="ts">
import Icon from '~/components/Icon.vue'

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

provide('spaceId', spaceId)
</script>

<template>
  <div class="flex h-full w-full bg-background">
    <aside class="p-6 xl:w-1/5">
      <nav class="sticky top-20 flex flex-col space-y-1">
        <RouterLink
          v-for="item in items"
          :key="item.name"
          :to="{ name: item.name, params: { space: $route.params.space } }"
          exact-active-class="bg-secondary text-primary"
          :class="[
            'flex items-center gap-2 rounded-md px-4 py-2',
            'transition-colors duration-200 hover:bg-secondary',
            'cursor-pointer font-semibold whitespace-nowrap',
          ]"
        >
          <Icon
            :name="item.icon"
            class="shrink-0"
          />
          <span>{{ $t(item.title) }}</span>
        </RouterLink>
      </nav>
    </aside>
    <div class="flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
