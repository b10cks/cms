<script setup lang="ts">
import SchemaEditor from '~/components/blocks/SchemaEditor.vue'
import { FormField, InputField, TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'
import ComboboxField from './ui/form/ComboboxField.vue'

const props = withDefaults(
  defineProps<{
    block: BlockResource
    spaceId: string
    showSchema?: boolean
    slugEditable?: boolean
    isCreate?: boolean
    readonly?: boolean
  }>(),
  {
    slugEditable: false,
    showSchema: false,
    isCreate: false,
    readonly: false,
  }
)

const editableBlock = ref<BlockResource>({
  ...props.block,
  type: props.block.type || 'nestable',
  icon: props.block.icon || 'block',
  color: props.block.color,
})

const { useBlockTagsQuery } = useBlockTags(props.spaceId)
const { data: blockTags } = useBlockTagsQuery({ per_page: 500 })

const possibleTags = computed(() => {
  return blockTags.value?.data.map((tag) => ({
    value: tag.name,
    label: tag.name,
  }))
})

const createSlug = (value) => {
  if (!props.slugEditable) return
  editableBlock.value.slug = value
    .replace(/[^a-zA-Z0-9]+/g, ' ')
    .trim()
    .split(' ')
    .map((word, i) => {
      if (i === 0) {
        return word.charAt(0).toLowerCase() + word.slice(1)
      } else {
        return word.charAt(0).toUpperCase() + word.slice(1)
      }
    })
    .join('')
}

const enforceSlugFormat = () => {
  if (!editableBlock.value.slug) return
  editableBlock.value.slug = editableBlock.value.slug.replace(/[^a-zA-Z0-9_]/g, '')
}

const handleTypeChange = (type) => {
  if (!props.readonly) {
    editableBlock.value.type = type as 'root' | 'nestable' | 'single' | 'universal'
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <IconNameField
      :model-value="editableBlock as { icon: string; color: string; name: string }"
      :label="$t('labels.blocks.fields.name')"
      name="name"
      :disabled="readonly"
      @update:name="createSlug"
      @update:model-value="Object.assign(editableBlock, $event)"
    />
    <InputField
      v-model="editableBlock.slug"
      :label="$t('labels.blocks.fields.slug')"
      name="slug"
      :disabled="!slugEditable"
      :readonly="readonly"
      @blur="enforceSlugFormat"
    />
    <InputField
      v-model="editableBlock.description"
      :label="$t('labels.blocks.fields.description')"
      name="description"
      :disabled="readonly"
    />
    <FormField
      name="type"
      :label="$t('labels.blocks.fields.type')"
    >
      <template #default="{ id }">
        <RadioGroup
          :id="id"
          v-model="editableBlock.type"
          :disabled="readonly"
          name="type"
        >
          <div
            v-for="type in ['nestable', 'root', 'universal', 'single']"
            :key="type"
            :class="[
              'flex cursor-pointer gap-3 rounded-lg border px-3 py-2 text-left',
              editableBlock.type === type ? 'border-border bg-input' : 'border-border',
            ]"
            tabindex="-1"
            @click="handleTypeChange(type)"
          >
            <RadioGroupItem
              :value="type"
              :disabled="readonly"
              class="mt-0.5"
            />
            <div>
              <h4 class="font-semibold text-primary">
                {{ $t(`labels.blocks.types.${type}.label`) }}
              </h4>
              <p class="text-sm">{{ $t(`labels.blocks.types.${type}.description`) }}</p>
            </div>
          </div>
        </RadioGroup>
      </template>
    </FormField>

    <ComboboxField
      v-if="possibleTags"
      v-model="editableBlock.tags"
      name="block_whitelist"
      :label="$t('labels.blocks.fields.tags')"
      :placeholder="$t('labels.blocks.fields.tagsPlaceholder')"
      :options="possibleTags"
      multiple
      searchable
      :readonly="readonly"
      :empty-text="$t('labels.blocks.fields.tagsEmpty')"
    />

    <TextField
      v-if="!isCreate && ['universal', 'nestable'].includes(editableBlock.type)"
      v-model="editableBlock.preview_template"
      :disabled="readonly"
      :label="$t('labels.blocks.fields.previewTemplate')"
      :description="$t('labels.blocks.fields.previewTemplateDescription')"
      name="preview_template"
    />

    <SchemaEditor
      v-if="showSchema"
      :readonly="readonly"
      v-model:schema="editableBlock.schema"
      v-model:editor="editableBlock.editor"
    />
    <slot :edit-block="editableBlock" />
  </div>
</template>
