<script setup lang="ts">
import ImagePickerField from '~/components/assets/ImagePickerField.vue'
import SchemaEditor from '~/components/blocks/SchemaEditor.vue'
import { FormField, InputField, TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import { RadioCard, RadioCardGroup } from '~/components/ui/radio-group'

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

const blockTypeIcons: Record<BlockResource['type'], string> = {
  nestable: 'lucide:layers',
  root: 'lucide:file',
  universal: 'lucide:shapes',
  single: 'lucide:settings-2',
}

const seedBlock = (source: BlockResource): BlockResource => ({
  ...source,
  type: source.type || 'nestable',
  icon: source.icon || 'block',
  color: source.color,
})

const editableBlock = ref<BlockResource>(seedBlock(props.block))

const { useBlockTagsQuery } = useBlockTags(props.spaceId)
const { data: blockTags } = useBlockTagsQuery({ per_page: 500 })

const possibleTags = computed(() => {
  return blockTags.value?.data.map((tag) => ({
    value: tag.name,
    label: tag.name,
  }))
})

// Block slugs are camelCase by rule (`^[a-z][a-z0-9A-Z]+$`), not kebab — the
// shared normalizer only supplies the transliteration, so "Übersicht" becomes
// "uebersicht" rather than the "bersicht" the old [^a-zA-Z0-9] strip produced.
const slugLanguage = useSlugLanguage(() => props.spaceId)
const { slugify } = useSlug()

const createSlug = (value: string) => {
  if (!props.slugEditable) return
  editableBlock.value.slug = slugify(value, {
    language: slugLanguage.value,
    case: 'camel',
    maxLength: 50,
    allowUnderscore: false,
  })
}

const enforceSlugFormat = () => {
  if (!editableBlock.value.slug) return
  editableBlock.value.slug = editableBlock.value.slug.replace(/[^a-zA-Z0-9_]/g, '')
}

/**
 * Empty means "no pattern", which is the historic behaviour: the slug follows
 * the entry name. Stored as null rather than '' so the backend default applies.
 */
const slugPattern = computed({
  get: () => editableBlock.value.settings?.slug_pattern ?? '',
  set: (value: string) => {
    editableBlock.value.settings = {
      ...editableBlock.value.settings,
      slug_pattern: value.trim() === '' ? null : value,
    }
  },
})

/**
 * Re-seeds the editor from a server response. The backend rewrites `schema`
 * and `settings` on write, so a saved block never serialises identically to
 * what was sent — without this the page stays dirty for the rest of the session.
 */
const reset = (source: BlockResource) => {
  editableBlock.value = seedBlock(source)
}

// Lets the hosting page track the edited model (dirty state, save shortcut)
// without threading it back out through the default slot.
defineExpose({ editableBlock, reset })
</script>

<template>
  <div class="flex flex-col gap-6">
    <IconNameField
      :model-value="editableBlock as { icon: string; color: string; name: string }"
      :label="$t('labels.blocks.fields.name')"
      name="name"
      :disabled="readonly"
      @update:name="createSlug($event as string)"
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
        <RadioCardGroup
          :id="id"
          v-model="editableBlock.type"
          :disabled="readonly"
          name="type"
        >
          <RadioCard
            v-for="(icon, type) in blockTypeIcons"
            :key="type"
            :value="type"
            :icon="icon"
            :title="$t(`labels.blocks.types.${type}.label`)"
            :description="$t(`labels.blocks.types.${type}.description`)"
          />
        </RadioCardGroup>
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

    <InputField
      v-if="!isCreate && ['root', 'universal'].includes(editableBlock.type)"
      v-model="slugPattern"
      :disabled="readonly"
      :label="$t('labels.blocks.fields.slugPattern')"
      :description="$t('labels.blocks.fields.slugPatternDescription')"
      :placeholder="'{field:name}'"
      input-class="font-mono"
      name="slug_pattern"
    />

    <ImagePickerField
      v-if="!isCreate"
      v-model="editableBlock.preview_file"
      :space-id="spaceId"
      :read-only="readonly"
      :label="$t('labels.blocks.fields.previewFile')"
      :description="$t('labels.blocks.fields.previewFileDescription')"
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
