<script setup lang="ts">
import type { BlockTagResource } from '~/api/resources/block-tags'
import BlockTagEdit from '~/components/BlockTagEdit.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
  tag?: BlockTagResource | null
}>()

const { $t } = useI18n()
const { useCreateBlockTagMutation, useUpdateBlockTagMutation } = useBlockTags(props.spaceId)
const { mutate: createBlockTag } = useCreateBlockTagMutation()
const { mutate: updateBlockTag } = useUpdateBlockTagMutation()

const isEditing = computed(() => !!props.tag?.name)
const dialogTitle = computed(() =>
  $t(isEditing.value ? 'labels.blockTags.editTag' : 'labels.blockTags.createTag')
)
const submitLabel = computed(() =>
  $t(isEditing.value ? 'actions.blockTags.save' : 'actions.create')
)
const initialTag = computed(() => props.tag ?? undefined)

const toPayload = (tag: BlockTagResource) => ({
  name: tag.name,
  icon: tag.icon ?? null,
  color: tag.color ?? null,
})

const handleSubmit = async (tag: BlockTagResource) => {
  const payload = toPayload(tag)

  if (isEditing.value && props.tag) {
    await updateBlockTag({
      tagName: props.tag.name,
      payload,
    })
  } else {
    await createBlockTag(payload)
  }

  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent>
      <DialogHeaderCombined :title="dialogTitle" />
      <BlockTagEdit
        :key="`${props.tag?.name ?? 'new'}-${open ? 'open' : 'closed'}`"
        v-slot="{ tag }"
        :tag="initialTag"
        :is-create="!isEditing"
      >
        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            @click="handleSubmit(tag)"
          >
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </BlockTagEdit>
    </DialogContent>
  </Dialog>
</template>
