<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import IconNameField from '~/components/ui/IconNameField.vue'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
  tag?: AssetTagResource | null
}>()

const { $t } = useI18n()
const { useCreateAssetTagMutation, useUpdateAssetTagMutation } = useAssetTags(props.spaceId)
const { mutate: createAssetTag } = useCreateAssetTagMutation()
const { mutate: updateAssetTag } = useUpdateAssetTagMutation()

const isEditing = computed(() => !!props.tag?.id)
const dialogTitle = computed(() =>
  $t(isEditing.value ? 'labels.assetTags.editTag' : 'labels.assetTags.createTag')
)
const submitLabel = computed(() =>
  $t(isEditing.value ? 'actions.assetTags.save' : 'actions.create')
)

const editableTag = ref<UpsertAssetTagPayload>({ name: '', icon: null, color: null })

watch(
  () => [props.tag, open.value],
  () => {
    editableTag.value = {
      name: props.tag?.name ?? '',
      icon: props.tag?.icon ?? null,
      color: props.tag?.color ?? null,
    }
  },
  { immediate: true }
)

const handleSubmit = () => {
  if (isEditing.value && props.tag) {
    updateAssetTag({ id: props.tag.id, payload: editableTag.value })
  } else {
    createAssetTag(editableTag.value)
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
      <div class="flex flex-col gap-6">
        <IconNameField
          v-model="editableTag"
          :label="$t('labels.assetTags.fields.name')"
          :placeholder="$t('labels.assetTags.fields.namePlaceholder')"
          name="name"
        />
        <DialogFooter>
          <Button @click="open = false">
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            variant="primary"
            :disabled="!editableTag.name"
            @click="handleSubmit"
          >
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </div>
    </DialogContent>
  </Dialog>
</template>
