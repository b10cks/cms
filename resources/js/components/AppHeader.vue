<script setup lang="ts">
import type { Ref } from 'vue'
import { RouterLink } from 'vue-router'

import Logo from '~/assets/logo.svg'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

const { useSpacesQuery } = useSpaces()
const { data: spaces } = useSpacesQuery({ per_page: 1000 })
const { selectedTeam } = useGlobalTeam()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(
  computed(() => ((selectedTeam.value?.id ? { team_id: selectedTeam.value.id } : {})))
)
const canCreateSpace = computed(() => access.canAccessRoute('spaces-new'))

const route = useRoute()
const commandOpen = inject<Ref<boolean>>('commandOpen')

const selectedSpaceId = computed(() => route.params?.space as string | undefined)

const selectedSpace = computed(() => {
  return spaces.value?.find((space) => space.id === selectedSpaceId.value) ?? null
})

const spaceRoute = (space: string) => ({ name: 'space', params: { space } })

const openQuickActions = () => {
  if (typeof commandOpen === 'object' && commandOpen !== null) {
    commandOpen.value = true
  }
}

const logout = () => {
  useAuth().logout()
}
</script>

<template>
  <div
    class="fixed top-0 z-20 flex h-14 w-full items-center gap-3 border-b border-sidebar-border bg-sidebar text-sidebar-foreground p-3 select-none"
  >
    <div class="flex items-center gap-3">
      <DropdownMenu>
        <DropdownMenuTrigger
          class="flex cursor-pointer gap-2 rounded-lg bg-sidebar-accent/60 p-2 transition-colors duration-200 hover:bg-sidebar-accent data-[state=open]:bg-sidebar-accent"
        >
          <Logo
            alt="b10cks"
            class="size-4 text-primary"
          />
          <Icon
            name="lucide:chevron-down"
            class="-mr-1"
          />
        </DropdownMenuTrigger>
        <DropdownMenuContent
          class="min-w-56"
          align="start"
        >
          <DropdownMenuGroup>
            <DropdownMenuItem as-child>
              <RouterLink to="/">
                {{ $t('actions.toDashboard') }}
              </RouterLink>
            </DropdownMenuItem>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuGroup>
            <DropdownMenuLabel
              v-if="selectedSpace"
              class="flex items-center gap-2"
            >
              <NuxtImg
                v-if="selectedSpace.icon"
                :src="selectedSpace.icon"
                :alt="selectedSpace.name"
                :width="20"
                :height="20"
                class="size-5 rounded-sm object-cover"
              />
              <Icon
                v-else
                name="lucide:cuboid"
                class="text-muted"
              />
              <span class="truncate">{{ selectedSpace.name }}</span>
              <SpaceBadge
                v-if="selectedSpace.badge"
                :badge="selectedSpace.badge"
                size="2xs"
              />
            </DropdownMenuLabel>
            <DropdownMenuItem
              v-if="selectedSpaceId"
              as-child
            >
              <RouterLink :to="spaceRoute(selectedSpaceId)">
                Space Dashboard
              </RouterLink>
            </DropdownMenuItem>
            <DropdownMenuItem @select="openQuickActions">
              {{ $t('actions.quickActions') }}
              <DropdownMenuShortcut>⌘K</DropdownMenuShortcut>
            </DropdownMenuItem>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuGroup>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                {{ $t('actions.spaces.switch') }}
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent>
                <DropdownMenuItem
                  v-for="space in spaces"
                  :key="space.id"
                  as-child
                  :class="[
                    'w-full cursor-pointer',
                    space.id === selectedSpaceId ? 'bg-accent text-accent-foreground' : '',
                  ]"
                >
                  <RouterLink :to="spaceRoute(space.id)">
                    <NuxtImg
                      v-if="space.icon"
                      :src="space.icon"
                      :alt="space.name"
                      :width="20"
                      :height="20"
                      class="size-5 rounded-sm object-cover"
                    />
                    <Icon
                      v-else
                      name="lucide:cuboid"
                      class="text-muted"
                    />
                    <span class="truncate">{{ space.name }}</span>
                    <SpaceBadge
                      v-if="space.badge"
                      :badge="space.badge"
                      size="2xs"
                    />
                  </RouterLink>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  v-if="canCreateSpace"
                  as-child
                >
                  <RouterLink to="/spaces/new">
                    <Icon name="lucide:plus" />
                    {{ $t('actions.spaces.add') }}
                  </RouterLink>
                </DropdownMenuItem>
              </DropdownMenuSubContent>
            </DropdownMenuSub>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuGroup>
            <DropdownMenuItem as-child>
              <RouterLink :to="{ name: 'account-settings-index' }">
                {{ $t('actions.user.account') }}
              </RouterLink>
            </DropdownMenuItem>
            <DropdownMenuItem @select="logout()">
              {{ $t('actions.logout') }}
            </DropdownMenuItem>
          </DropdownMenuGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
    <slot>
      <div id="appHeader" />
    </slot>
    <div class="ml-auto flex items-center gap-2">
      <div id="appHeaderActions">
        <slot name="headerActions" />
      </div>
    </div>
  </div>
</template>
