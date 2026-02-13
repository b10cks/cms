<script setup lang="ts">
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { Button } from '~/components/ui/button'
import type { BlockTagResource } from '~/api/resources/block-tags'
import BlockTagEdit from '~/components/BlockTagEdit.vue'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
}>()

const { useCreateBlockTagMutation } = useBlockTags(props.spaceId)
const { mutate: createBlockTag } = useCreateBlockTagMutation()

const handleCreate = async (tag: BlockTagResource) => {
  await createBlockTag(tag)
  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent>
      <DialogHeaderCombined :title="$t('labels.blockTags.createTag')" />
      <BlockTagEdit
        v-slot="{ tag }"
        :tag="{}"
        is-create
      >
        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            @click="handleCreate(tag)"
          >
            {{ $t('actions.create') }}
          </Button>
        </DialogFooter>
      </BlockTagEdit>
    </DialogContent>
  </Dialog>
</template>
