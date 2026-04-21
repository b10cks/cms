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
  DropdownMenuLabel,
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
import { runtimeConfig } from '~/lib/runtime-config'
import { getLocale, locales } from '~/plugins/i18n'

const router = useRouter()
const { t } = useI18n()
const isDark = useDark()
const toggleDark = useToggle(isDark)

const { user, logout } = useAuth()
const { settings, extendedSidebar } = useUserSettings()
const { useProviderNotesQuery } = useProvider()

const isExtendedSidebar = computed(() => extendedSidebar.value)

const spaceId = inject<Ref<string | undefined>>('spaceId')
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => (spaceId?.value ? { space_id: spaceId.value } : {})))

const menu = computed(() => {
  if (!spaceId?.value) {
    return []
  }

  return access.filterVisibleItems(spaceNavigationItems).filter((item) => {
    if (item.routeName !== 'space-settings-index') {
      return true
    }

    return access.canAccessRoute('space-settings-index')
  })
})

const buildLink = (name: string) => {
  if (!spaceId?.value) {
    return { name }
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

const menuLabel = (label: string) => {
  return label.startsWith('labels.') ? String(t(label)) : label
}

const appVersion = computed(() => runtimeConfig.public.appVersion || '')
const sidebarMenu = computed(() => runtimeConfig.public.sidebarMenu || [])
const { data: providerNotesResponse } = useProviderNotesQuery(computed(() => ({ per_page: 10 })))
const providerNotes = computed(() => (providerNotesResponse.value?.data ?? []).slice(0, 10))

const openSidebarLink = (href?: string) => {
  if (!href) {
    return
  }

  window.open(href, href.startsWith('/') ? '_self' : '_blank', 'noopener,noreferrer')
}

const noteLabel = (note: ProviderNote) => {
  return note.title?.trim() || note.url?.trim() || String(t('labels.provider.sidebar.untitledNote'))
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
          v-bind="isExtendedSidebar ? {} : { tooltip: menuLabel(m.label), side: 'right' }"
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
              class="line-clamp-2 text-2xs leading-tight"
            >
              {{ menuLabel(m.label) }}
            </span>
          </RouterLink>
        </component>
      </div>
    </div>
    <div class="flex flex-col items-center gap-2">
      <DropdownMenu class="px-2">
        <DropdownMenuTrigger
          :class="[
            'w-full flex items-center justify-center rounded-lg transition-colors duration-200 ease-butter hover:bg-border',
            isExtendedSidebar ? 'flex-col gap-1 text-center py-2' : 'size-8',
          ]"
        >
          <Icon name="lucide:circle-question-mark" />
          <span
            v-if="isExtendedSidebar"
            class="line-clamp-2 text-2xs leading-tight"
          >
            {{ t('labels.provider.sidebar.help') }}
          </span>
          <DropdownMenuContent
            class="min-w-48"
            align="end"
          >
            <DropdownMenuLabel v-if="appVersion">
              <h4 class="text-primary font-semibold">b10cks</h4>
              <span>v{{ appVersion }}</span>
            </DropdownMenuLabel>
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                <Icon name="lucide:megaphone" />
                <span>{{ t('labels.provider.notes.title') }}</span>
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent
                side="right"
                align="start"
                class="min-w-56"
              >
                <DropdownMenuItem
                  v-for="note in providerNotes"
                  :key="note.id"
                  @select="openSidebarLink(note.url || undefined)"
                >
                  <Icon
                    v-if="note.icon"
                    :name="`lucide:${note.icon}`"
                    :style="note.color ? { color: note.color } : undefined"
                  />
                  <span class="truncate">
                    {{ noteLabel(note) }}
                  </span>
                </DropdownMenuItem>
                <DropdownMenuItem
                  v-if="providerNotes.length === 0"
                  disabled
                >
                  <span>{{ t('labels.provider.sidebar.noNotes') }}</span>
                </DropdownMenuItem>
              </DropdownMenuSubContent>
            </DropdownMenuSub>
            <template v-if="sidebarMenu.length > 0">
              <DropdownMenuSeparator />
              <DropdownMenuItem
                v-for="item in sidebarMenu"
                :key="`${item.label}-${item.href}`"
                @select="openSidebarLink(item.href)"
              >
                <Icon
                  v-if="item.icon"
                  :name="item.icon"
                />
                <span>{{ item.label }}</span>
              </DropdownMenuItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenuTrigger>
      </DropdownMenu>
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
