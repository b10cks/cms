<script setup lang="ts">
import { useDark, useToggle } from '@vueuse/core'
import type { Ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { Switch } from '~/components/ui/switch'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { spaceNavigationItems } from '~/lib/access-control'
import { getLocale, locales } from '~/plugins/i18n'

const router = useRouter()
const isDark = useDark()
const toggleDark = useToggle(isDark)


const { user, logout } = useAuth()
const { settings, extendedSidebar } = useUserSettings()


const isExtendedSidebar = computed(() => extendedSidebar.value)


const spaceId = inject<Ref<string | undefined>>('spaceId')
const { useAccessControl } = useAuthorization()
const access = useAccessControl(
  computed(() => ({
    ...(spaceId?.value ? { space_id: spaceId.value } : {}),
  }))
)


const menu = computed(() =>
  access.filterVisibleItems(spaceNavigationItems).filter((item) => {
    if (item.routeName !== 'space-settings-index') {
      return true
    }

    return access.canAccessRoute('space-settings-index')
  })
)


const buildLink = (name: string) => {
  if (!spaceId?.value) {
    return { name: 'index' }
  }
  return {
    name,
    params: {
      space: spaceId.value,
    },
  }
}


const handleLanguageChange = (lang: string) => {
  settings.languageIso = lang
}


const currentLocale = computed(() => getLocale())
const availableLocales = locales
</script>

<template>
  <div
    :class="[
      'flex h-full flex-col overflow-hidden border-r border-r-border select-none transition-all',
      isExtendedSidebar ? 'w-18 p-1 pb-3' : 'w-14 p-3',
    ]"
  >
    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
      <div
        :class="['relative flex w-full min-w-0 flex-col', isExtendedSidebar ? 'gap-1' : 'gap-2']"
      >
        <component
          :is="isExtendedSidebar ? 'div' : SimpleTooltip"
          v-for="m in menu"
          :key="m.label"
          v-bind="isExtendedSidebar ? {} : { tooltip: m.label, side: 'right' }"
        >
          <RouterLink
            :to="buildLink(m.routeName)"
            :class="[
              'w-full flex items-center justify-center rounded-lg transition-colors duration-200 ease-butter hover:bg-border',
              isExtendedSidebar ? 'flex-col gap-1 p-2 text-center' : 'size-8 ',
            ]"
            active-class="text-primary bg-border"
          >
            <Icon
              :name="m.icon"
              size="20"
            />
            <span
              v-if="isExtendedSidebar"
              class="line-clamp-2 text-[10px] leading-tight"
            >
              {{ m.label.startsWith('labels.') ? $t(m.label) : m.label }}
            </span>
          </RouterLink>
        </component>
      </div>
    </div>
    <div class="flex flex-col items-center gap-2">
      <div class="flex flex-col items-center border-t-2 border-t-border pt-3">
        <DropdownMenu>
          <DropdownMenuTrigger>
            <Avatar
              v-if="user"
              :avatar="user.avatar"
              :name="`${user.firstname} ${user.lastname}`.trim()"
            />
          </DropdownMenuTrigger>
          <DropdownMenuContent
            class="min-w-48"
            align="start"
          >
            <DropdownMenuItem disabled>{{ user?.email }}</DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuCheckboxItem
              :model-value="isExtendedSidebar"
              @select="settings.extendedSidebar = !isExtendedSidebar"
            >
              <span>{{ $t('labels.navigation.extendedSidebar', 'Extended sidebar') }}</span>
            </DropdownMenuCheckboxItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem @select="toggleDark()">
              <span>{{ $t('labels.account.themes.darkMode') }}</span>
              <Switch
                :model-value="isDark"
                class="ml-auto"
              >
                <template #thumb>
                  <Icon
                    v-if="isDark"
                    name="lucide:moon"
                    size="0.75rem"
                  />
                  <Icon
                    v-else
                    name="lucide:sun"
                    size="0.75rem"
                  />
                </template>
              </Switch>
            </DropdownMenuItem>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                <Icon name="lucide:languages" />
                <span>{{ $t('labels.account.settings.language') }}</span>
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent
                side="right"
                align="start"
                class="min-w-40"
              >
                <DropdownMenuRadioGroup :model-value="currentLocale">
                  <DropdownMenuRadioItem
                    v-for="loc in availableLocales"
                    :key="loc.code"
                    :value="loc.code"
                    @select="handleLanguageChange(loc.code)"
                  >
                    <span>{{ loc.name }}</span>
                  </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
              </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuItem @click="router.push('/account/settings')">
              <Icon name="lucide:cog" />
              <span>{{ $t('actions.user.account') }}</span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem @select="logout()">
              <Icon name="lucide:log-out" />
              <span>{{ $t('actions.logout') }}</span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  </div>
</template>
