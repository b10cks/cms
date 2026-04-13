<script setup lang="ts">
import { ref, watch } from 'vue'

import type { BlockTagResource } from '~/api/resources/block-tags'
import IconNameField from '~/components/ui/IconNameField.vue'

const props = withDefaults(
  defineProps<{
    tag?: Partial<BlockTagResource>
    isCreate?: boolean
  }>(),
  {
    tag: () => ({}),
    isCreate: false,
  }
)

const createEmptyTag = (): BlockTagResource => ({
  name: '',
  icon: null,
  color: null,
})

const editableTag = ref<BlockTagResource>(createEmptyTag())

watch(
  () => props.tag,
  (tag) => {
    editableTag.value = {
      ...createEmptyTag(),
      ...tag,
    }
  },
  { immediate: true }
)
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
