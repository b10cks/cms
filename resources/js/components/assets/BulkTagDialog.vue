<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { ComboboxField } from '~/components/ui/form'
import IconName from '~/components/ui/IconName.vue'

const props = defineProps<{
  spaceId: string
  assets: AssetResource[]
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  applied: []
}>()

const { $t } = useI18n()
const { useAssetTagsQuery } = useAssetTags(props.spaceId)
const { bulkUpdateAssets } = useAssetBulkOperations(props.spaceId)
const { data: allTagsResponse } = useAssetTagsQuery({ per_page: 500 })

const tagsToAdd = ref<string[]>([])
const tagsToRemove = ref<string[]>([])
const isApplying = ref(false)

const tagOptions = computed(() =>
  (allTagsResponse.value?.data ?? []).map((tag) => ({
    value: tag.id,
    label: tag.name,
    icon: tag.icon,
    color: tag.color,
  }))
)

const canApply = computed(() => {
  return props.assets.length > 0 && (tagsToAdd.value.length > 0 || tagsToRemove.value.length > 0)
})

const handleApply = async () => {
  if (!canApply.value) {
    return
  }

  const removeSet = new Set(tagsToRemove.value)
  const updates = props.assets.flatMap((asset) => {
    const currentTags = asset.tags ?? []
    const nextTags = Array.from(
      new Set([...currentTags.filter((tag) => !removeSet.has(tag)), ...tagsToAdd.value])
    )

    const changed =
      nextTags.length !== currentTags.length || nextTags.some((tag) => !currentTags.includes(tag))

    return changed ? [{ id: asset.id, payload: { tags: nextTags } }] : []
  })

  if (!updates.length) {
    open.value = false
    return
  }

  isApplying.value = true

  try {
    const { succeeded, failed } = await bulkUpdateAssets(updates)

    if (failed) {
      toast.error(String($t('messages.assets.bulkTagPartial', { succeeded, failed })))
    } else {
      toast.success(String($t('messages.assets.bulkTagSuccess', { count: succeeded })))
    }

    emit('applied')
    open.value = false
  } finally {
    isApplying.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    tagsToAdd.value = []
    tagsToRemove.value = []
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.assets.bulkTag.title', { count: assets.length })"
        :description="$t('labels.assets.bulkTag.description')"
      />

      <div class="grid gap-4 py-2">
        <ComboboxField
          v-model="tagsToAdd"
          name="bulk_tags_add"
          :label="$t('labels.assets.bulkTag.addLabel')"
          :placeholder="$t('labels.assetTags.fields.namePlaceholder')"
          :options="tagOptions"
          multiple
          searchable
          :empty-text="$t('labels.assetTags.noTags')"
        >
          <template #option="{ option }">
            <IconName
              :icon="option.icon"
              :color="option.color"
              :name="option.label"
            />
          </template>
        </ComboboxField>

        <ComboboxField
          v-model="tagsToRemove"
          name="bulk_tags_remove"
          :label="$t('labels.assets.bulkTag.removeLabel')"
          :placeholder="$t('labels.assetTags.fields.namePlaceholder')"
          :options="tagOptions"
          multiple
          searchable
          :empty-text="$t('labels.assetTags.noTags')"
        >
          <template #option="{ option }">
            <IconName
              :icon="option.icon"
              :color="option.color"
              :name="option.label"
            />
          </template>
        </ComboboxField>
      </div>

      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          @click="open = false"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button
          type="button"
          variant="primary"
          :loading="isApplying"
          :disabled="!canApply"
          @click="handleApply"
        >
          <Icon
            v-if="!isApplying"
            name="lucide:tags"
          />
          {{ $t('actions.assets.applyTags') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
