<script setup lang="ts">
import Icon from '~/components/Icon.vue'

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

import Logo from '~/assets/logo.svg'

const { useSpacesQuery } = useSpaces()
const { data: spaces } = useSpacesQuery()

const router = useRouter()
const route = useRoute()
const commandOpen = inject('commandOpen')

const selectedSpaceId = computed({
  get: () => route.params?.space as string | undefined,
  set: (space: string) => {
    router.push({
      name: 'space',
      params: {
        space,
      },
    })
  },
})

const selectedSpace = computed(() => {
  return spaces.value?.find((space) => space.id === selectedSpaceId.value) ?? null
})

const toDashboard = () => {
  router.push('/')
}

const toSpaceDashboard = () => {
  if (selectedSpaceId.value) {
    router.push(`/${selectedSpaceId.value}`)
  }
}

const openQuickActions = () => {
  if (typeof commandOpen === 'object' && commandOpen !== null) {
    commandOpen.value = true
  }
}

const openAccountSettings = () => {
  router.push({ name: 'account-settings-index' })
}

const switchSpace = (spaceId: string) => {
  selectedSpaceId.value = spaceId
}

const createNewSpace = () => {
  router.push('/spaces/new')
}

const logout = () => {
  useAuth().logout()
}

const isSpaceSelected = computed(() => !!selectedSpace.value)
</script>

<template>
  <div
    class="fixed top-0 z-20 flex h-14 w-full items-center gap-3 border-b border-border bg-surface p-3 select-none"
  >
    <div class="flex items-center gap-3">
      <DropdownMenu>
        <DropdownMenuTrigger
          class="flex cursor-pointer gap-2 rounded-lg bg-secondary p-2 transition-colors duration-200 hover:bg-elevated data-[state=open]:bg-elevated"
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
            <DropdownMenuItem @select="toDashboard">
              {{ $t('actions.toDashboard') }}
            </DropdownMenuItem>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuGroup>
            <DropdownMenuLabel v-if="selectedSpace">
              {{ selectedSpace.name }}
            </DropdownMenuLabel>
            <DropdownMenuItem
              v-if="isSpaceSelected"
              @select="toSpaceDashboard"
            >
              Space Dashboard
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
                  :class="[
                    'w-full cursor-pointer',
                    space.id === selectedSpaceId ? 'bg-blue-600 text-primary' : '',
                  ]"
                  @select="switchSpace(space.id)"
                >
                  {{ space.name }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="createNewSpace">
                  {{ $t('actions.spaces.add') }}
                </DropdownMenuItem>
              </DropdownMenuSubContent>
            </DropdownMenuSub>
          </DropdownMenuGroup>
          <DropdownMenuSeparator />
          <DropdownMenuGroup>
            <DropdownMenuItem @click="openAccountSettings()">
              {{ $t('actions.user.account') }}
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
    <div
      id="appHeaderActions"
      class="ml-auto"
    >
      <slot name="headerActions" />
    </div>
  </div>
</template>
