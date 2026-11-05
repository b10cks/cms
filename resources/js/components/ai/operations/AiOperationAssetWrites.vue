<script setup lang="ts">
import AiOperationAssetOutputs from '~/components/ai/operations/AiOperationAssetOutputs.vue'
import { useAssetFieldOptions } from '~/components/ai/operations/useOperationFields'
import { Checkbox } from '~/components/ui/checkbox'
import type { AiAssetOutputs, AiOperationScope } from '~/lib/aiOperations'
import { languageName } from '~/lib/languageNames'

/**
 * Where an asset plan writes: what each asset should end up with, the
 * metadata fields an overwrite may replace and the metadata languages. Fields are the ones the space and the scope's
 * folders enable, as the server validates them; languages are the asset
 * language keys, the default one stored as `_default`.
 */
const props = defineProps<{
  spaceId: string
  scope: AiOperationScope
  /** Whether the plan allows replacing existing values */
  overwrite: boolean
  /** The plan removes or adds tags, so requested outputs must include them */
  tagsRequired: boolean
  readonly?: boolean
  errors?: { overwrite_fields?: string | null; languages?: string | null; outputs?: string | null }
}>()

const fields = defineModel<string[]>('fields', { required: true })
const languages = defineModel<string[]>('languages', { required: true })
const outputs = defineModel<AiAssetOutputs | null>('outputs', { required: true })

const { t, locale } = useI18n()
const { space } = useAssetRequirements(props.spaceId)
const fieldOptions = useAssetFieldOptions(props.spaceId, () => props.scope)

const languageOptions = computed(() => {
  const settings = space.value?.settings
  const named = (code: string) => languageName(code, settings?.languages, locale.value)
  return [
    { key: '_default', label: t('components.aiOperations.plan.defaultLanguage', { name: named(settings?.default_language ?? 'en') }) },
    ...(settings?.languages ?? []).map((language) => ({ key: language.code, label: named(language.code) })),
  ]
})

/** Labels of the chosen keys; a key the settings no longer know shows as it is. */
const labelsOf = (keys: readonly string[], options: ReadonlyArray<{ key: string; label: string }>) =>
  keys.map((key) => options.find((option) => option.key === key)?.label ?? key).join(', ')

const toggle = (list: string[], key: string, on: boolean) =>
  on ? [...new Set([...list, key])] : list.filter((entry) => entry !== key)
</script>

<template>
  <div class="grid gap-4">
    <AiOperationAssetOutputs
      v-model:outputs="outputs"
      :options="fieldOptions"
      :tags-required="tagsRequired"
      :readonly="readonly"
      :error="errors?.outputs"
    />

    <fieldset
      v-if="overwrite"
      class="grid gap-2"
      data-overwrite-fields
    >
      <legend class="mb-2 text-sm text-muted">{{ $t('components.aiOperations.plan.overwriteFields') }}</legend>
      <p
        v-if="readonly"
        class="text-sm"
      >
        {{ labelsOf(fields, fieldOptions) }}
      </p>
      <template v-else>
        <p
          v-if="fieldOptions.length === 0"
          class="text-sm text-muted"
        >
          {{ $t('components.aiOperations.plan.noFields') }}
        </p>
        <div class="flex flex-wrap gap-x-4 gap-y-2">
          <label
            v-for="option in fieldOptions"
            :key="option.key"
            class="flex items-center gap-2 text-sm"
          >
            <Checkbox
              :model-value="fields.includes(option.key)"
              @update:model-value="fields = toggle(fields, option.key, $event === true)"
            />
            {{ option.label }}
          </label>
        </div>
        <p
          v-if="fields.length === 0"
          :class="['text-sm', errors?.overwrite_fields ? 'text-destructive' : 'text-muted']"
        >
          {{ $t('components.aiOperations.plan.overwriteFieldsHint') }}
        </p>
        <p
          v-else-if="errors?.overwrite_fields"
          class="text-sm text-destructive"
        >
          {{ errors.overwrite_fields }}
        </p>
      </template>
    </fieldset>

    <fieldset
      class="grid gap-2"
      data-languages
    >
      <legend class="mb-2 text-sm text-muted">{{ $t('components.aiOperations.plan.languages') }}</legend>
      <p
        v-if="readonly"
        class="text-sm"
      >
        {{ languages.length === 0 ? $t('components.aiOperations.plan.allLanguages') : labelsOf(languages, languageOptions) }}
      </p>
      <template v-else>
        <div class="flex flex-wrap gap-x-4 gap-y-2">
          <label
            v-for="option in languageOptions"
            :key="option.key"
            class="flex items-center gap-2 text-sm"
          >
            <Checkbox
              :model-value="languages.includes(option.key)"
              @update:model-value="languages = toggle(languages, option.key, $event === true)"
            />
            {{ option.label }}
          </label>
        </div>
        <p
          v-if="languages.length === 0"
          class="text-sm text-muted"
        >
          {{ $t('components.aiOperations.plan.languagesHint') }}
        </p>
      </template>
      <p
        v-if="errors?.languages"
        class="text-sm text-destructive"
      >
        {{ errors.languages }}
      </p>
    </fieldset>
  </div>
</template>
