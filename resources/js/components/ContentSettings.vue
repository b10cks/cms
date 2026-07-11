<script setup lang="ts">
import { computed, inject } from 'vue'

import { CheckboxField, InputField, SelectField } from '~/components/ui/form'
import type { ComboboxOption } from '~/components/ui/form/ComboboxField.vue'
import ComboboxField from '~/components/ui/form/ComboboxField.vue'
import { resolveAllowedChildContentBlocks } from '~/lib/content-children'
import type { ContentResource } from '~/types/contents'

import { Alert } from './ui/alert'

const { t } = useI18n()
const content = defineModel<ContentResource>()
const spaceId = inject<string>('spaceId')
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId || undefined })))
const canManageContent = computed(() => access.hasAbility('content.manage'))
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId || null)
const { useBlocksQuery } = useBlocks(spaceId || '')
const { useBlockTagsQuery } = useBlockTags(spaceId || '')
const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const { data: blockTags } = useBlockTagsQuery({ per_page: 1000 })

const isCanonical = computed(() => !!content.value && content.value.i18n_parent_id === null)
const canManageChildSettings = computed(() => canManageContent.value && isCanonical.value)
const effectiveMode = computed(
  () => content.value?.effective_i18n_mode || space.value?.settings.i18n_mode || 'overlay'
)

const overrideOptions = computed(() => [
  { value: 'inherit', label: t('labels.contents.settings.i18n.override.inherit') },
  { value: 'overlay', label: t('labels.contents.settings.i18n.override.overlay') },
  {
    value: 'independent',
    label: t('labels.contents.settings.i18n.override.independent'),
  },
])

const childBlockOptions = computed((): ComboboxOption<string>[] =>
  resolveAllowedChildContentBlocks(blocks.value?.data)
    .map(({ slug, name }) => ({
      value: slug,
      label: name,
    }))
    .sort((a, b) => a.label.localeCompare(b.label))
)

const childTagOptions = computed((): ComboboxOption<string>[] =>
  (blockTags.value?.data || []).map(({ name }) => ({
    value: name,
    label: name,
  }))
)

const availableDefaultChildBlocks = computed(() =>
  resolveAllowedChildContentBlocks(blocks.value?.data, content.value?.settings)
)

const defaultChildBlockOptions = computed(() =>
  availableDefaultChildBlocks.value.map((block) => ({
    value: block.id,
    label: block.name,
  }))
)

const childSortByOptions = computed(() =>
  (['inherit', 'manual', 'name', 'published_at', 'created_at', 'updated_at'] as const).map(
    (value) => ({
      value,
      label: t(`labels.contents.settings.sorting.options.${value}`),
    })
  )
)

const childSortDirectionOptions = computed(() =>
  (['asc', 'desc'] as const).map((value) => ({
    value,
    label: t(`labels.contents.settings.sorting.directionOptions.${value}`),
  }))
)

const isAttributeChildSort = computed(() => {
  const sortBy = content.value?.settings.child_sort_by
  return !!sortBy && sortBy !== 'inherit' && sortBy !== 'manual'
})

const filterOptions = (
  option: ComboboxOption<string>,
  search: string,
  selectedValues: string[]
): boolean => {
  const searchLower = search.toLowerCase()
  if (selectedValues.includes(option.value)) {
    return false
  }

  return !(
    search &&
    !option.value.toLowerCase().includes(searchLower) &&
    !String(option.label).toLowerCase().includes(searchLower)
  )
}

watch(
  availableDefaultChildBlocks,
  (nextBlocks) => {
    if (!content.value?.settings.default_child_block) {
      return
    }

    const isCurrentDefaultAllowed = nextBlocks.some(
      (block) => block.id === content.value?.settings.default_child_block
    )

    if (!isCurrentDefaultAllowed) {
      content.value.settings.default_child_block = null
    }
  },
  { immediate: true }
)
</script>

<template>
  <div :class="['flex flex-col gap-6', !canManageContent ? 'pointer-events-none opacity-70' : '']">
    <InputField
      v-if="content"
      v-model="content.name"
      :label="$t('labels.contents.fields.name')"
      :readonly="!canManageContent"
      name="name"
    />
    <InputField
      v-if="content"
      v-model="content.slug"
      :label="$t('labels.contents.fields.slug')"
      :readonly="!canManageContent"
      name="slug"
    />
    <InputField
      v-if="content"
      v-model="content.external_id"
      :tooltip="$t('labels.contents.fields.externalIdInfo')"
      :label="$t('labels.contents.fields.externalId')"
      :readonly="!canManageContent"
      name="slug"
    />
    <CheckboxField
      v-if="content"
      v-model="content.settings.disablePreview"
      :label="$t('labels.contents.settings.disablePreview')"
      :disabled="!canManageContent"
      name="disablePreview"
      :description="$t('labels.contents.settings.disablePreviewDescription')"
    />
    <SelectField
      v-if="content && isCanonical"
      v-model="content.settings.i18n_mode_override"
      name="i18nModeOverride"
      :label="$t('labels.contents.settings.i18n.override.label')"
      :readonly="!canManageContent"
      :description="
        isCanonical
          ? $t('labels.contents.settings.i18n.override.description')
          : $t('labels.contents.settings.i18n.override.canonicalOnly')
      "
      :options="overrideOptions"
    />
    <Alert
      v-if="content"
      variant="modern"
      color="info"
    >
      {{ $t('labels.contents.settings.i18n.effectiveMode', { mode: effectiveMode }) }}
    </Alert>
    <div
      v-if="content"
      class="space-y-4 rounded-lg bg-surface px-4 py-4"
    >
      <div class="space-y-1">
        <h3 class="text-sm font-semibold text-primary">
          {{ $t('labels.contents.settings.children.title') }}
        </h3>
        <p class="text-sm text-muted">
          {{ $t('labels.contents.settings.children.description') }}
        </p>
      </div>

      <template v-if="isCanonical">
        <CheckboxField
          v-model="content.settings.restrict_child_blocks"
          :label="$t('labels.contents.settings.children.restrict')"
          :disabled="!canManageChildSettings"
          name="restrictChildBlocks"
          :description="$t('labels.contents.settings.children.restrictDescription')"
        />

        <ComboboxField
          v-if="content.settings.restrict_child_blocks"
          v-model="content.settings.child_block_whitelist"
          name="childBlockWhitelist"
          :label="$t('labels.contents.settings.children.blockWhitelist')"
          :description="$t('labels.contents.settings.children.blockWhitelistDescription')"
          placeholder="labels.contents.settings.children.blockWhitelistPlaceholder"
          :disabled="!canManageChildSettings"
          :options="childBlockOptions"
          :filter-fn="filterOptions"
          multiple
          searchable
          :empty-text="$t('labels.contents.settings.children.blockWhitelistEmpty')"
        />

        <ComboboxField
          v-if="content.settings.restrict_child_blocks"
          v-model="content.settings.child_tag_whitelist"
          name="childTagWhitelist"
          :label="$t('labels.contents.settings.children.tagWhitelist')"
          :description="$t('labels.contents.settings.children.tagWhitelistDescription')"
          placeholder="labels.contents.settings.children.tagWhitelistPlaceholder"
          :disabled="!canManageChildSettings"
          :options="childTagOptions"
          :filter-fn="filterOptions"
          multiple
          searchable
          :empty-text="$t('labels.contents.settings.children.tagWhitelistEmpty')"
        />

        <SelectField
          v-model="content.settings.default_child_block"
          name="defaultChildBlock"
          :label="$t('labels.contents.settings.children.defaultChildBlock')"
          :description="$t('labels.contents.settings.children.defaultChildBlockDescription')"
          :readonly="!canManageChildSettings"
          :options="defaultChildBlockOptions"
          placeholder="labels.contents.settings.children.defaultChildBlockPlaceholder"
          clearable
        />
      </template>

      <div
        v-else
        class="rounded-lg border border-dashed border-border bg-background px-4 py-3 text-sm text-muted"
      >
        {{ $t('labels.contents.settings.children.canonicalOnly') }}
      </div>
    </div>
    <div
      v-if="content && isCanonical"
      class="space-y-4 rounded-lg bg-surface px-4 py-4"
    >
      <div class="space-y-1">
        <h3 class="text-sm font-semibold text-primary">
          {{ $t('labels.contents.settings.sorting.title') }}
        </h3>
        <p class="text-sm text-muted">
          {{ $t('labels.contents.settings.sorting.description') }}
        </p>
      </div>

      <SelectField
        v-model="content.settings.child_sort_by"
        name="childSortBy"
        :label="$t('labels.contents.settings.sorting.sortBy')"
        :description="$t('labels.contents.settings.sorting.sortByDescription')"
        :readonly="!canManageChildSettings"
        :options="childSortByOptions"
      />

      <SelectField
        v-if="isAttributeChildSort"
        v-model="content.settings.child_sort_direction"
        name="childSortDirection"
        :label="$t('labels.contents.settings.sorting.direction')"
        :readonly="!canManageChildSettings"
        :options="childSortDirectionOptions"
      />
    </div>
  </div>
</template>
