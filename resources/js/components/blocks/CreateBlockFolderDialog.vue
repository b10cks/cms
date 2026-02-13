<script setup lang="ts">
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { Button } from '~/components/ui/button'
import type { BlockFolderResource } from '~/api/resources/block-folders'
import BlockFolderEdit from '~/components/BlockFolderEdit.vue'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
}>()

const { useCreateBlockFolderMutation } = useBlockFolders(props.spaceId)
const { mutate: createBlockFolder } = useCreateBlockFolderMutation()

const handleCreate = async (folder: BlockFolderResource) => {
  await createBlockFolder(folder)
  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent>
      <DialogHeaderCombined :title="$t('labels.blockFolders.createFolder')" />
      <BlockFolderEdit
        v-slot="{ folder }"
        :folder="{}"
        is-create
      >
        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            @click="handleCreate(folder)"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </BlockFolderEdit>
    </DialogContent>
  </Dialog>
</template>
