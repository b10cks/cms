<script setup lang="ts">
import type { BlockFolderResource } from '~/api/resources/block-folders'
import IconNameField from '~/components/ui/IconNameField.vue'

const props = withDefaults(
  defineProps<{
    folder: BlockFolderResource
    isCreate?: boolean
  }>(),
  {
    isCreate: false,
  }
)

const editableFolder = ref<BlockFolderResource>({
  ...props.folder,
  icon: props.folder.icon || 'folder',
  color: props.folder.color,
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <IconNameField
      :model-value="editableFolder as { icon: string; color: string; name: string }"
      :label="$t('labels.blocks.fields.name')"
      name="name"
      @update:model-value="Object.assign(editableFolder, $event)"
    />
    <slot :folder="editableFolder" />
  </div>
</template>
