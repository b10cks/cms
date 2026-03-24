<script setup lang="ts">
import { useClipboard } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '~/components/ui/dropdown-menu'
import { useAuthorization } from '~/composables/useAuthorization'
import { getActionAccessRequirement } from '~/lib/access-control'

const props = defineProps<{
  space: SpaceResource
}>()


const emit = defineEmits<{
  archive: [space: SpaceResource]
  assignBadge: [space: SpaceResource]
}>()


const router = useRouter()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))


const canOpenSettings = computed(() =>
  access.canAccessRoute(getActionAccessRequirement('space.settings'))
)
const canUpdateSpace = computed(() => access.hasAbility('space.update'))
const canArchive = computed(() =>
  access.canAccessRoute(getActionAccessRequirement('space.archive'))
)


const openSpace = () => {
  router.push({ name: 'space', params: { space: props.space.id } })
}


const openInNewTab = () => {
  window.open(props.space.id, '_blank')
}


const copyLink = () => {
  const url = new URL(
    window.location.origin +
      router.resolve({ name: 'space', params: { space: props.space.id } }).href
  )
  useClipboard().copy(url.toString())
}


const openSettings = () => {
  router.push({ name: 'space-settings-index', params: { space: props.space.id } })
}
</script>

<template>
  <DropdownMenuContent align="start">
    <DropdownMenuItem @select="openSpace">
      <Icon name="lucide:home" />
      <span>{{ $t('actions.open') }}</span>
    </DropdownMenuItem>
    <DropdownMenuItem @select="openInNewTab">
      <Icon name="lucide:external-link" />
      <span>{{ $t('actions.newTab') }}</span>
    </DropdownMenuItem>
    <DropdownMenuItem @select="copyLink">
      <Icon name="lucide:copy" />
      <span>{{ $t('actions.copyLink') }}</span>
    </DropdownMenuItem>
    <DropdownMenuItem
      v-if="canOpenSettings"
      @select="openSettings"
    >
      <Icon name="lucide:cog" />
      <span>{{ $t('actions.settings') }}</span>
    </DropdownMenuItem>
    <template v-if="canUpdateSpace">
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="emit('assignBadge', space)">
        <Icon name="lucide:tag" />
        <span>{{ $t('actions.spaces.assignBadge') }}</span>
      </DropdownMenuItem>
    </template>
    <template v-if="canArchive">
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="emit('archive', space)">
        <Icon name="lucide:archive" />
        <span>{{ $t('actions.archive') }}</span>
      </DropdownMenuItem>
    </template>
  </DropdownMenuContent>
</template>
