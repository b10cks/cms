<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { TreeItemToggleEvent } from 'reka-ui'
import { TreeItem, TreeRoot } from 'reka-ui'
import CreateContentDialog from '~/components/content/CreateContentDialog.vue'
import { AvatarList } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { SimpleTooltip } from '~/components/ui/tooltip'
import type { ContentResource } from '~/types/contents'

const props = defineProps<{
  title?: string
  spaceId: string
}>()

const route = useRoute()
const router = useRouter()

const { alert } = useAlertDialog()
const { useSpacesQuery } = useSpaces()
const { data: spaces } = useSpacesQuery()
const { useContentMenuQuery, getChildren, getRootItems } = useContentMenu(props.spaceId)
const { useUpdateContentMutation, useDeleteContentMutation } = useContent(props.spaceId)

const { mutate: updateContent } = useUpdateContentMutation()
const { mutate: deleteContent } = useDeleteContentMutation()

const { settings } = useSpaceSettings(props.spaceId)

const { isLoading, error, data } = useContentMenuQuery()
const rootItems = computed(() => getRootItems(data.value) || [])

const { getUsersForContent } = useContentMenuPresence(props.spaceId)

const selectedItemId = ref<string | null>(null)
const currentlyEditingId = ref<string | null>(null)
const showCreateDialog = ref(false)
const createParentId = ref<string | null>(null)

const selectedSpace = computed(() => {
  return spaces.value?.find((space) => space.id === props.spaceId) || null
})

function handleRename(newName: string, contentId: string) {
  if (contentId && newName) {
    updateContent({ id: contentId, payload: { name: newName } })
  }
  currentlyEditingId.value = null
}

function handleEditStart(contentId: string) {
  currentlyEditingId.value = contentId
}

function handleEditCancel() {
  currentlyEditingId.value = null
}

const handleSelect = (contentId: string) => {
  let name = route.name
  if (name === 'space-content') {
    name = 'space-content-contentId'
  }
  selectedItemId.value = contentId
  router.push({
    ...route,
    name,
    hash: '',
    params: {
      space: route.params.space,
      contentId,
    },
  })
}

const handleToggle = (e: TreeItemToggleEvent<ContentResource>) => {
  if (e.detail.originalEvent instanceof PointerEvent) {
    e.preventDefault()
  }
}

const toggleExpanded = (contentId: string) => {
  const expanded = settings.value.content.expanded || []
  const index = expanded.indexOf(contentId)
  if (index > -1) {
    expanded.splice(index, 1)
  } else {
    expanded.push(contentId)
  }
  settings.value.content.expanded = expanded
}

// Set the current item based on the route
const setCurrentItemFromRoute = () => {
  if (route.params.contentId) {
    selectedItemId.value = route.params.contentId as string
  }
}

// Watch for route changes to update selection
watch(
  () => route.params.contentId,
  () => {
    setCurrentItemFromRoute()
  },
  { immediate: true }
)

// Refresh content menu on component mount
onMounted(() => {
  setCurrentItemFromRoute()
})

const initCreate = (parentId: string | null) => {
  createParentId.value = parentId
  showCreateDialog.value = true
}

const initDelete = async (item: ContentResource) => {
  if (!(await alert.confirm('Are you sure you want to delete this item?'))) {
    return
  }
  deleteContent(item.id)
}
</script>

<template>
  <aside>
    <div
      v-if="isLoading"
      class="flex items-center justify-center py-4"
    >
      <span class="text-sm text-muted">Loading...</span>
    </div>

    <div
      v-else-if="error"
      class="px-2 py-4 text-sm text-destructive"
    >
      {{ error }}
    </div>

    <TreeRoot
      v-slot="{ flattenItems }"
      v-model:expanded="settings.content.expanded"
      class="w-full list-none p-2 select-none"
      :items="rootItems"
      :get-children="(item) => getChildren(data, item.id)"
      :get-key="({ id }) => id"
    >
      <h2
        v-if="title && !isLoading"
        class="px-2 pt-1 pb-3 text-sm font-semibold text-primary"
      >
        {{ title }}
      </h2>

      <div
        v-if="selectedSpace"
        class="ml-2 flex items-center gap-2 py-1 font-semibold"
      >
        <Icon
          name="lucide:globe"
          class="shrink-0 text-primary"
        />
        <span class="truncate">{{ selectedSpace.name }}</span>
        <Button
          class="ml-auto"
          variant="ghost"
          size="xs"
          @click="initCreate(null)"
        >
          <Icon name="lucide:plus" />
        </Button>
      </div>

      <TreeItem
        v-for="item in flattenItems"
        v-slot="{ isExpanded }"
        :key="item._id"
        :style="{ 'padding-left': `${item.level - 0.5}rem` }"
        v-bind="item.bind"
        :class="[
          'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none',
          'transition-colors duration-200 hover:bg-border',
          'cursor-pointer font-semibold',
          item.value.id === selectedItemId ? 'bg-border text-primary' : '',
        ]"
        @select="handleSelect(item.value.id)"
        @toggle="(e) => handleToggle(e)"
      >
        <button
          v-if="item.value.children"
          class="h-4 w-3"
          @click.stop.prevent="toggleExpanded(item.value.id)"
        >
          <Icon
            name="lucide:chevron-right"
            :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
          />
        </button>
        <span
          v-else
          class="size-3"
        />

        <Icon
          :name="`lucide:${item.value.icon}`"
          class="shrink-0"
          :style="{ color: item.value.color }"
        />

        <RenamableTitle
          :name="item.value.name"
          class="w-full truncate text-left"
          @update="handleRename($event, item.value.id)"
          @edit-start="handleEditStart(item.value.id)"
          @cancel="handleEditCancel"
        />

        <div class="ml-auto flex items-center gap-2">
          <AvatarList
            v-if="getUsersForContent(item.value.id).length > 0"
            :users="getUsersForContent(item.value.id)"
            :max="2"
            size="sm"
            class="mr-1"
          />
          <div
            v-if="!item.value.pat"
            class="h-2 w-2 rounded-full bg-text-muted"
            title="Draft"
          />
          <SimpleTooltip
            v-else
            :tooltip="item.value.pat"
          >
            <div class="h-2 w-2 rounded-full bg-success" />
          </SimpleTooltip>
        </div>

        <div
          class="absolute right-6 flex items-center gap-1 overflow-clip bg-border opacity-0 transition-opacity duration-200 group-hover:w-auto group-hover:opacity-100"
        >
          <button
            v-if="item.value.type !== 'single'"
            class="flex transform cursor-pointer items-center hover:text-primary"
            @click="initCreate(item.value.id)"
          >
            <Icon name="lucide:plus" />
          </button>
          <button
            type="button"
            title="Delete item"
            class="flex transform cursor-pointer items-center hover:text-red-500"
            @click.stop="initDelete(item.value)"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </TreeItem>
    </TreeRoot>

    <CreateContentDialog
      v-model:open="showCreateDialog"
      :space-id="props.spaceId"
      :parent-id="createParentId"
    />
  </aside>
</template>
