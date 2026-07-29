<script setup lang="ts">
import { TreeRoot } from 'reka-ui'

import AssetTagTreeItem from '~/components/assets/AssetTagTreeItem.vue'
import CreateAssetTagDialog from '~/components/assets/CreateAssetTagDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageTags = computed(() => access.hasAbility('asset_tags.manage'))

const {
  useAssetTagsQuery,
  useUpdateAssetTagMutation,
  useDeleteAssetTagMutation,
  useAssignTagToAssetsMutation,
} = useAssetTags(props.spaceId)
const { data: tags } = useAssetTagsQuery({ per_page: 500, sort: '+name' })
const { mutate: updateTag } = useUpdateAssetTagMutation()
const { mutate: deleteTag } = useDeleteAssetTagMutation()
const { mutate: assignTagToAssets } = useAssignTagToAssetsMutation()

const selectedTagId = defineModel<string | null>()

const tagDialogOpen = ref(false)
const editingTag = ref<AssetTagResource | null>(null)

function openCreateDialog() {
  editingTag.value = null
  tagDialogOpen.value = true
}

function openEditDialog(tag: AssetTagResource) {
  editingTag.value = tag
  tagDialogOpen.value = true
}

function handleRename(newName: string, tag: AssetTagResource) {
  if (!newName || newName === tag.name) return
  updateTag({ id: tag.id, payload: { name: newName, icon: tag.icon, color: tag.color } })
}

function handleAssignDrop(tagId: string, assetIds: string[]) {
  assignTagToAssets({ tagId, assetIds })
}

async function handleDelete(tag: AssetTagResource) {
  const confirmed = await alert.confirm(
    $t('labels.assetTags.deleteConfirmation', { name: tag.name }),
    {
      title: $t('labels.assetTags.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    deleteTag(tag.id)
    if (selectedTagId.value === tag.id) {
      selectedTagId.value = null
    }
  }
}
</script>

<template>
  <div>
    <TreeRoot
      v-slot="{ flattenItems }"
      class="w-full list-none select-none"
      :items="tags?.data ?? []"
      :get-key="(item) => item?.id"
      :get-children="() => undefined"
    >
      <div class="group my-2 flex items-center gap-2 px-2">
        <Icon
          name="lucide:tag"
          class="text-muted-foreground"
          aria-hidden="true"
        />
        <h2 class="text-sm font-semibold text-primary">
          {{ $t('labels.assetTags.title') }}
        </h2>
        <Button
          v-if="canManageTags"
          class="ml-auto opacity-0 transition-opacity duration-200 group-hover:opacity-100"
          size="toolbar"
          :aria-label="$t('labels.assetTags.createTag')"
          @click="openCreateDialog"
        >
          <Icon name="lucide:plus" />
        </Button>
      </div>

      <AssetTagTreeItem
        v-for="item in flattenItems"
        :key="item._id"
        :item="item"
        :selected-tag-id="selectedTagId ?? null"
        :can-manage-tags="canManageTags"
        class="my-0.5"
        @select="selectedTagId = $event"
        @rename="(name, tag) => handleRename(name, tag)"
        @edit="openEditDialog"
        @delete="handleDelete"
        @assign-drop="handleAssignDrop"
      />
    </TreeRoot>

    <CreateAssetTagDialog
      v-if="canManageTags"
      v-model:open="tagDialogOpen"
      :space-id="spaceId"
      :tag="editingTag"
    />
  </div>
</template>
