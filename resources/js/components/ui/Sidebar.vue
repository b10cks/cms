<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useDark, useToggle } from '@vueuse/core'
import { Avatar } from '~/components/ui/avatar'
import {
  DropdownMenu,
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
import { getLocale, locales } from '~/plugins/i18n'

const router = useRouter()
const isDark = useDark()
const toggleDark = useToggle(isDark)
const { handleUpdateLanguage } = useUserSettings()

const { user, logout } = useAuth()

const spaceId = inject('spaceId')

const menu = [
  { icon: 'lucide:home', label: 'Home', route: 'space' },
  { icon: 'lucide:feather', label: 'Content', route: 'space-content' },
  { icon: 'lucide:blocks', label: 'Blocks', route: 'space-blocks' },
  { icon: 'lucide:images', label: 'Assets', route: 'space-assets-index' },
  { icon: 'lucide:database-zap', label: 'Data sets', route: 'space-datasources' },
  { icon: 'lucide:split', label: 'Redirects', route: 'space-redirects' },
  { icon: 'lucide:rocket', label: 'Releases', route: 'space-releases' },
  { icon: 'lucide:settings', label: 'Settings', route: 'space-settings-index' },
]

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
  handleUpdateLanguage(lang)
}

const currentLocale = computed(() => getLocale())
const availableLocales = locales
</script>

<template>
  <div class="flex h-full w-14 flex-col overflow-hidden border-r border-r-border p-3 select-none">
    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
      <div class="relative flex w-full min-w-0 flex-col gap-2">
        <SimpleTooltip
          v-for="m in menu"
          :key="m.label"
          :tooltip="m.label"
          side="right"
        >
          <RouterLink
            :to="buildLink(m.route)"
            class="flex size-8 items-center justify-center rounded-lg transition-colors duration-200 ease-butter hover:bg-border"
            active-class="text-primary bg-border"
          >
            <Icon
              :name="m.icon"
              size="1.25rem"
              stroke-width="5"
            />
          </RouterLink>
        </SimpleTooltip>
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
            <DropdownMenuItem disabled>{{ user.email }}</DropdownMenuItem>
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
