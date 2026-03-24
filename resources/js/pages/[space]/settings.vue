<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { spaceSettingsNavigationItems } from '~/lib/access-control'

const route = useRoute()
const router = useRouter()
const spaceId = computed<string>(() => route.params.space as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const items = computed(() => access.filterVisibleItems(spaceSettingsNavigationItems))


watch(
  [items, () => route.name],
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
      <nav class="sticky flex flex-col space-y-1">
        <RouterLink
          v-for="item in items"
          :key="item.routeName"
          :to="{ name: item.routeName, params: { space: $route.params.space } }"
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
          <span>{{ $t(item.label) }}</span>
        </RouterLink>
      </nav>
    </aside>
    <div class="flex-1 pb-6">
      <RouterView />
    </div>
  </div>
</template>
