<script setup lang="ts">
import { computed, inject } from 'vue'

import { CheckboxField, FormField, InputField, SelectField } from '~/components/ui/form'
import type { ComboboxOption } from '~/components/ui/form/ComboboxField.vue'
import ComboboxField from '~/components/ui/form/ComboboxField.vue'
import {
  TagsInput,
  TagsInputInput,
  TagsInputItem,
  TagsInputItemDelete,
  TagsInputItemText,
} from '~/components/ui/tags-input'
import { resolveAllowedChildContentBlocks } from '~/lib/content-children'
import { languageName } from '~/lib/languageNames'
import type { ContentResource } from '~/types/contents'

import ConfigRow from './content/ConfigRow.vue'

const { t, locale } = useI18n()
const content = defineModel<ContentResource>()
const props = defineProps<{
  /** A language switch saves right away, so it waits until local edits are saved. */
  isDirty?: boolean
}>()
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

const defaultLanguage = computed(() => space.value?.settings.default_language ?? null)
const nameOfLanguage = (code: string) =>
  languageName(code, space.value?.settings.languages, locale.value)
const isOtherLanguage = computed(
  () => !!content.value && content.value.language_iso !== defaultLanguage.value
)
/** Versions in other languages hang off a default-language original, so it keeps its language. */
const hasTranslations = computed(
  () => content.value?.language_versions?.some((version) => version.exists && !version.is_canonical) ?? false
)
const languageOptions = computed(() => {
  const settings = space.value?.settings
  if (!settings?.default_language || !content.value) return []

  const codes = settings.language_only_entries
    ? [settings.default_language, ...(settings.languages ?? []).map(({ code }) => code)]
    : [...new Set([settings.default_language, content.value.language_iso])]

  return codes.map((code) => ({
    value: code,
    label:
      code === settings.default_language
        ? nameOfLanguage(code)
        : t('labels.contents.settings.language.only', { language: nameOfLanguage(code) }),
  }))
})
const showLanguageRow = computed(() => isCanonical.value && languageOptions.value.length > 1)
const languageSummary = computed(() =>
  content.value
    ? isOtherLanguage.value
      ? t('labels.contents.settings.language.only', {
          language: nameOfLanguage(content.value.language_iso),
        })
      : nameOfLanguage(content.value.language_iso)
    : ''
)
const languageLockReason = computed(() => {
  if (hasTranslations.value) return t('labels.contents.settings.language.hasTranslations')
  if (props.isDirty) return t('labels.contents.settings.language.saveFirst')
  return null
})

const { useUpdateContentMutation } = useContent(spaceId || '')
const { mutateAsync: updateContent, isPending: isSwitchingLanguage } = useUpdateContentMutation()
const { alert } = useAlertDialog()

const switchLanguage = async (languageIso: string) => {
  const entry = content.value
  if (!entry || languageIso === entry.language_iso) return

  const language = nameOfLanguage(languageIso)
  const toDefault = languageIso === defaultLanguage.value
  const confirmed = await alert.confirm(
    t(`labels.contents.settings.language.${toDefault ? 'confirmDefault' : 'confirmOnly'}`, {
      language,
    }),
    {
      title: t(
        `labels.contents.settings.language.${toDefault ? 'confirmDefaultTitle' : 'confirmOnlyTitle'}`,
        { language }
      ),
      confirmLabel: t('labels.contents.settings.language.confirmLabel'),
    }
  )
  if (!confirmed) return

  // Metadata only: no `content` key, so no new version.
  await updateContent({ id: entry.id, payload: { language_iso: languageIso } })
}

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

// First-level fields of the allowed child content types whose type sorts
// meaningfully, offered as `content.{field}` options next to the built-ins.
const SORTABLE_CONTENT_FIELD_TYPES = new Set(['text', 'number', 'date', 'option'])

const contentFieldSortOptions = computed(() => {
  const fields = new Set<string>()

  for (const block of availableDefaultChildBlocks.value) {
    for (const [key, schema] of Object.entries(block.schema || {})) {
      if (SORTABLE_CONTENT_FIELD_TYPES.has(schema?.type)) {
        fields.add(key)
      }
    }
  }

  // Keep a configured field selectable even when no allowed child type
  // declares it anymore.
  const current = content.value?.settings.child_sort_by
  if (current?.startsWith('content.')) {
    fields.add(current.slice('content.'.length))
  }

  return [...fields].sort().map((field) => ({
    value: `content.${field}` as const,
    label: t('labels.contents.settings.sorting.options.contentField', { field }),
  }))
})

const childSortByOptions = computed(() => [
  ...(['inherit', 'manual', 'name', 'published_at', 'created_at', 'updated_at'] as const).map(
    (value) => ({
      value,
      label: t(`labels.contents.settings.sorting.options.${value}`),
    })
  ),
  ...contentFieldSortOptions.value,
])

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

const cacheTtl = computed({
  get: () => content.value?.settings.cache_ttl ?? undefined,
  set: (value: number | string | null) => {
    if (!content.value) {
      return
    }

    const parsed = value === null || value === '' ? null : Number(value)
    content.value.settings.cache_ttl = parsed !== null && Number.isFinite(parsed) ? parsed : null
  },
})

const cacheTags = computed({
  get: () => content.value?.settings.cache_tags || [],
  set: (value: string[]) => {
    if (content.value) {
      content.value.settings.cache_tags = value
    }
  },
})

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

const openRows = ref<Partial<Record<'language' | 'preview' | 'translations' | 'children' | 'cache' | 'externalId', boolean>>>({})

const optionLabel = (options: { value: string; label: string }[], value?: string | null) =>
  options.find((option) => option.value === value)?.label

/** A cache lifetime in the largest whole unit: "1 hour", "30 minutes", "45 seconds". */
const lifetimeLabel = (seconds: number) => {
  if (seconds > 0 && seconds % 3600 === 0) {
    return t('labels.contents.settings.rows.hours', seconds / 3600)
  }
  if (seconds > 0 && seconds % 60 === 0) {
    return t('labels.contents.settings.rows.minutes', seconds / 60)
  }
  return t('labels.contents.settings.rows.seconds', seconds)
}

const previewSummary = computed(() =>
  content.value?.settings.disablePreview
    ? t('labels.contents.settings.rows.previewHidden')
    : t('labels.contents.settings.rows.previewShown')
)

const translationsSummary = computed(() => {
  const mode = t(`labels.contents.settings.rows.modes.${effectiveMode.value}`)
  if (!isCanonical.value) return mode

  const override =
    optionLabel(overrideOptions.value, content.value?.settings.i18n_mode_override || 'inherit') ??
    ''
  return `${override}, ${mode}`
})

const childrenSummary = computed(() => {
  const settings = content.value?.settings
  if (!settings || !isCanonical.value) return t('labels.contents.settings.rows.onDefaultLanguage')

  const types = settings.restrict_child_blocks
    ? t('labels.contents.settings.rows.typesAllowed', settings.child_block_whitelist?.length ?? 0)
    : t('labels.contents.settings.rows.anyType')

  const sortBy = settings.child_sort_by || 'inherit'
  const order =
    sortBy === 'inherit'
      ? t('labels.contents.settings.rows.spaceOrder')
      : sortBy === 'manual'
        ? t('labels.contents.settings.rows.manualOrder')
        : t('labels.contents.settings.rows.sortedBy', {
            field: optionLabel(childSortByOptions.value, sortBy) ?? sortBy,
          })

  return `${types}, ${order}`
})

const cacheSummary = computed(() => {
  const ttl = content.value?.settings.cache_ttl
  const tags = cacheTags.value.length
  const parts = [
    typeof ttl === 'number' ? lifetimeLabel(ttl) : null,
    tags ? t('labels.contents.settings.rows.tags', tags) : null,
  ].filter(Boolean)

  return parts.length ? parts.join(', ') : t('labels.contents.settings.rows.notSet')
})
</script>

<template>
  <div
    v-if="content"
    class="flex flex-col gap-6"
  >
    <InputField
      v-model="content.name"
      :label="$t('labels.contents.fields.name')"
      :readonly="!canManageContent"
      name="name"
    />
    <InputField
      v-model="content.slug"
      :label="$t('labels.contents.fields.slug')"
      :readonly="!canManageContent"
      name="slug"
    />

    <div class="overflow-hidden rounded-lg border border-border bg-card shadow-soft-sm">
      <ConfigRow
        v-if="showLanguageRow"
        v-model:open="openRows.language"
        data-config-row="language"
        icon="lucide:globe"
        :label="$t('labels.contents.settings.rows.language')"
        :summary="languageSummary"
      >
        <SelectField
          :model-value="content.language_iso"
          name="languageIso"
          :label="$t('labels.contents.settings.language.label')"
          hide-label
          :description="
            languageLockReason ??
            $t(
              isOtherLanguage
                ? 'labels.contents.settings.language.descriptionOnly'
                : 'labels.contents.settings.language.descriptionDefault'
            )
          "
          :disabled="!canManageContent || !!languageLockReason || isSwitchingLanguage"
          :options="languageOptions"
          @update:model-value="switchLanguage(String($event))"
        />
      </ConfigRow>

      <ConfigRow
        v-model:open="openRows.preview"
        data-config-row="preview"
        icon="lucide:eye"
        :label="$t('labels.contents.settings.rows.preview')"
        :summary="previewSummary"
      >
        <CheckboxField
          v-model="content.settings.disablePreview"
          :label="$t('labels.contents.settings.disablePreview')"
          :disabled="!canManageContent"
          name="disablePreview"
          :description="$t('labels.contents.settings.disablePreviewDescription')"
        />
      </ConfigRow>

      <ConfigRow
        v-model:open="openRows.translations"
        data-config-row="translations"
        icon="lucide:languages"
        :label="$t('labels.contents.settings.rows.translations')"
        :summary="translationsSummary"
      >
        <SelectField
          v-if="isCanonical"
          v-model="content.settings.i18n_mode_override"
          name="i18nModeOverride"
          :label="$t('labels.contents.settings.i18n.override.label')"
          :readonly="!canManageContent"
          :description="$t('labels.contents.settings.i18n.override.description')"
          :options="overrideOptions"
        />
        <p
          v-else
          class="text-sm text-muted"
        >
          {{ $t('labels.contents.settings.i18n.override.canonicalOnly') }}
        </p>
        <p class="text-sm text-muted">
          {{ $t('labels.contents.settings.i18n.effectiveMode', { mode: effectiveMode }) }}
        </p>
      </ConfigRow>

      <ConfigRow
        v-model:open="openRows.children"
        data-config-row="children"
        icon="lucide:list-tree"
        :label="$t('labels.contents.settings.rows.children')"
        :summary="childrenSummary"
      >
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
        </template>
        <p
          v-else
          class="text-sm text-muted"
        >
          {{ $t('labels.contents.settings.children.canonicalOnly') }}
        </p>
      </ConfigRow>

      <ConfigRow
        v-model:open="openRows.cache"
        data-config-row="cache"
        icon="lucide:timer"
        :label="$t('labels.contents.settings.rows.cache')"
        :summary="cacheSummary"
      >
        <InputField
          v-model="cacheTtl"
          type="number"
          :min="0"
          name="cacheTtl"
          :label="$t('labels.contents.settings.cache.ttl')"
          :description="$t('labels.contents.settings.cache.ttlDescription')"
          :placeholder="$t('labels.contents.settings.cache.ttlPlaceholder')"
          :readonly="!canManageContent"
        />

        <FormField
          name="cacheTags"
          :label="$t('labels.contents.settings.cache.tags')"
          :description="$t('labels.contents.settings.cache.tagsDescription')"
        >
          <TagsInput
            v-model="cacheTags"
            :disabled="!canManageContent"
          >
            <TagsInputItem
              v-for="tag in cacheTags"
              :key="tag"
              :value="tag"
            >
              <TagsInputItemText>{{ tag }}</TagsInputItemText>
              <TagsInputItemDelete />
            </TagsInputItem>
            <TagsInputInput :placeholder="$t('labels.contents.settings.cache.tagsPlaceholder')" />
          </TagsInput>
        </FormField>
      </ConfigRow>

      <ConfigRow
        v-model:open="openRows.externalId"
        data-config-row="externalId"
        icon="lucide:fingerprint"
        :label="$t('labels.contents.fields.externalId')"
        :summary="content.external_id || $t('labels.contents.settings.rows.notSet')"
      >
        <InputField
          v-model="content.external_id"
          :tooltip="$t('labels.contents.fields.externalIdInfo')"
          :label="$t('labels.contents.fields.externalId')"
          :readonly="!canManageContent"
          name="externalId"
        />
      </ConfigRow>
    </div>
  </div>
</template>
