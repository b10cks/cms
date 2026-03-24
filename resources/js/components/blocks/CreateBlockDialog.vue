<script setup lang="ts">
import BlockEdit from '~/components/BlockEdit.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
  folderId?: string | null
  initialBlock?: Partial<BlockResource> | null
  title?: string
}>()

const { useCreateBlockMutation } = useBlocks(props.spaceId)
const { mutateAsync: createBlock } = useCreateBlockMutation()

const emit = defineEmits<{
  (e: 'created', block: BlockResource): void
}>()

const editableBlock = computed<BlockResource>(() => ({
  id: props.initialBlock?.id ?? '',
  slug: '',
  icon: props.initialBlock?.icon ?? 'block',
  color: props.initialBlock?.color ?? null,
  name: '',
  description: props.initialBlock?.description ?? '',
  type: props.initialBlock?.type ?? 'nestable',
  preview_template: props.initialBlock?.preview_template ?? '',
  schema: props.initialBlock?.schema ?? {},
  editor: props.initialBlock?.editor ?? [],
  tags: props.initialBlock?.tags ?? [],
  folder_id: props.folderId ?? props.initialBlock?.folder_id ?? null,
  created_at: props.initialBlock?.created_at ?? '',
  updated_at: props.initialBlock?.updated_at ?? '',
}))

const handleCreate = async (editBlock: BlockResource) => {
  const block = await createBlock(editBlock)
  emit('created', block)
  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent>
      <DialogHeaderCombined :title="title || $t('labels.blocks.createBlock')" />
      <BlockEdit
        v-slot="{ editBlock }"
        :block="editableBlock"
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
