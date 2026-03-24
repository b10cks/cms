<script setup lang="ts">
import { useMagicKeys } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import SpaceBadge from '~/components/space/SpaceBadge.vue'
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '~/components/ui/command'
import { getActionAccessRequirement } from '~/lib/access-control'

const route = useRoute()
const router = useRouter()


const spaceId = computed<string>(() => route.params.space as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canViewBlocks = computed(() =>
  access.canAccessRoute(getActionAccessRequirement('command.blocks'))
)
const canViewContent = computed(() =>
  access.canAccessRoute(getActionAccessRequirement('command.content'))
)


const { useSpacesQuery } = useSpaces()
const { data: spaces } = useSpacesQuery({})
const { useBlocksQuery } = useBlocks(spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 }, canViewBlocks)
const { useContentMenuQuery } = useContentMenu(spaceId)
const { data: contents } = useContentMenuQuery(canViewContent)


const keys = useMagicKeys()
const triggerKey = keys['Cmd+K']


const open = inject('commandOpen', ref(true))
function handleOpenChange() {
  open.value = !open.value
}


watch(triggerKey, (v) => {
  if (v) {
    handleOpenChange()
  }
})


const jumpTo = (url: string) => {
  router.push(url)
  open.value = false
}
</script>

<template>
  <CommandDialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <CommandInput :placeholder="$t('labels.command.input.placeholder')" />
    <CommandList>
      <CommandEmpty>{{ $t('labels.command.empty') }}</CommandEmpty>
      <CommandGroup
        v-if="canViewBlocks && blocks"
        heading="Blocks"
      >
        <CommandItem
          v-for="block in blocks.data"
          :key="block.id"
          :value="block.id"
          class="flex items-center gap-2"
          @select="jumpTo(`/${spaceId}/blocks/${block.id}`)"
        >
          <Icon
            :name="`lucide:${block.icon}`"
            :style="{ color: block.color }"
          />
          <span>{{ block.name }}</span>
        </CommandItem>
      </CommandGroup>
      <CommandGroup
        v-if="canViewContent && contents"
        heading="Contents"
      >
        <CommandItem
          v-for="content in contents"
          :key="content.id"
          :value="content.id"
          class="flex items-center gap-2"
          @select="jumpTo(`/${spaceId}/content/${content.id}`)"
        >
          <Icon
            :name="`lucide:${content.icon}`"
            :style="{ color: content.color }"
          />
          <span>{{ content.name }}</span>
        </CommandItem>
      </CommandGroup>
      <CommandGroup heading="Spaces">
        <CommandItem
          v-for="space in spaces"
          :key="space.id"
          :value="space.id"
          class="flex items-center gap-2"
          @select="jumpTo(`/${space.id}`)"
        >
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
          <span>{{ space.name }}</span>
          <SpaceBadge
            v-if="space.badge"
            :badge="space.badge"
            size="xs"
            class="ml-auto"
          />
        </CommandItem>
      </CommandGroup>
    </CommandList>
  </CommandDialog>
</template>
