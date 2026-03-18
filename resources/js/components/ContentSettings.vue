<script setup lang="ts">
import { computed, inject } from 'vue'

import { CheckboxField, InputField, SelectField } from '~/components/ui/form'
import type { ContentResource } from '~/types/contents'

const { t } = useI18n()
const content = defineModel<ContentResource>()
const spaceId = inject<string>('spaceId')
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId || null)


const isCanonical = computed(() => !!content.value && content.value.i18n_parent_id === null)
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
</script>

<template>
  <div class="flex flex-col gap-6">
    <InputField
      v-if="content"
      v-model="content.name"
      :label="$t('labels.contents.fields.name')"
      name="name"
    />
    <InputField
      v-if="content"
      v-model="content.slug"
      :label="$t('labels.contents.fields.slug')"
      name="slug"
    />
    <CheckboxField
      v-if="content"
      v-model="content.settings.disablePreview"
      :label="$t('labels.contents.settings.disablePreview')"
      name="disablePreview"
      :description="$t('labels.contents.settings.disablePreviewDescription')"
    />
    <SelectField
      v-if="content && isCanonical"
      v-model="content.settings.i18n_mode_override"
      name="i18nModeOverride"
      :label="$t('labels.contents.settings.i18n.override.label')"
      :description="
        isCanonical
          ? $t('labels.contents.settings.i18n.override.description')
          : $t('labels.contents.settings.i18n.override.canonicalOnly')
      "
      :options="overrideOptions"
    />
    <div
      v-if="content"
      class="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted"
    >
      {{ $t('labels.contents.settings.i18n.effectiveMode', { mode: effectiveMode }) }}
    </div>
  </div>
</template>
