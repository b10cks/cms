<script setup lang="ts">
import type { BlockTagResource } from '~/api/resources/block-tags'
import IconNameField from '~/components/ui/IconNameField.vue'

const props = withDefaults(
  defineProps<{
    tag: BlockTagResource
    isCreate?: boolean
  }>(),
  {
    isCreate: false,
  }
)

const editableTag = ref<BlockTagResource>({ ...props.tag })
</script>

<template>
  <div class="flex flex-col gap-6">
    <IconNameField
      v-model="editableTag"
      :label="$t('labels.blockTags.fields.name')"
      :placeholder="$t('labels.blockTags.fields.namePlaceholder')"
      name="name"
    />
    <slot :tag="editableTag" />
  </div>
</template>
