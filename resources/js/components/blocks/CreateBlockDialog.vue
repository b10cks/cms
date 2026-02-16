<script setup lang="ts">
import BlockEdit from '~/components/BlockEdit.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
  folderId?: string | null
}>()

const { useCreateBlockMutation } = useBlocks(props.spaceId)
const { mutate: createBlock } = useCreateBlockMutation()

const handleCreate = async (editBlock: BlockResource) => {
  await createBlock(editBlock)
  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent>
      <DialogHeaderCombined :title="$t('labels.blocks.createBlock')" />
      <BlockEdit
        v-slot="{ editBlock }"
        :block="{ folder_id: folderId ?? null }"
        :space-id="spaceId"
        slug-editable
        is-create
      >
        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            @click="handleCreate(editBlock)"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </BlockEdit>
    </DialogContent>
  </Dialog>
</template>
