<script setup lang="ts">
import { useMetaFieldOptions, useOperationLanguages } from '~/components/ai/operations/useOperationFields'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import { Checkbox } from '~/components/ui/checkbox'
import { Switch } from '~/components/ui/switch'
import type { AiMetadataKey, AiMetadataOutputs } from '~/lib/aiOperations'
import { AI_METADATA_KEYS, newMetadataOutputs } from '~/lib/aiOperations'

/**
 * Metadata generation as plan controls: the values to write, those it may
 * replace, and which meta fields and entry languages it covers. Picking none
 * of the last two means all of them. A plan with none of this stays a general
 * task and reports its coverage as unverified.
 *
 * `linked` is the same plan on a translation: each target language also gets
 * these values, written from its proposed translation. What happens to them when
 * a reviewer edits or rejects that translation is an open decision, and the copy
 * says so instead of offering a setting.
 */
const props = defineProps<{
  spaceId: string
  /** Block slugs in the frozen scope; they decide which meta fields exist */
  groups: readonly string[]
  readonly?: boolean
  /** Generated from the translation of the same plan */
  linked?: boolean
  /** With `linked`: the plan's target locales, the only languages it can write */
  targetLocales?: readonly string[]
  errors?: { metadata?: string | null; keys?: string | null; overwrite?: string | null; fields?: string | null; locales?: string | null }
}>()

const metadata = defineModel<AiMetadataOutputs | null>('metadata', { required: true })

const { t } = useOperationI18n()
const metaFields = useMetaFieldOptions(props.spaceId, () => props.groups, () => metadata.value !== null)
const { contentLanguages } = useOperationLanguages(props.spaceId, (name) => t('plan.metadata.defaultLanguage', { name }))

const copy = (key: string) => t(`plan.${props.linked ? 'linked' : 'metadata'}.${key}`)

const localeOptions = computed(() =>
  props.linked ? contentLanguages.value.filter((option) => props.targetLocales?.includes(option.key)) : contentLanguages.value
)

const keyLabel = (key: string) => (AI_METADATA_KEYS.includes(key as AiMetadataKey) ? t(`metaKeys.${key}`) : key)

const toggled = (list: readonly string[], key: string, on: boolean) => (on ? [...new Set([...list, key])] : list.filter((entry) => entry !== key))

const setKey = (key: string, on: boolean) => {
  if (!metadata.value) return
  const keys = toggled(metadata.value.keys, key, on)
  metadata.value = { ...metadata.value, keys, overwrite_keys: metadata.value.overwrite_keys.filter((entry) => keys.includes(entry)) }
}

const labelsOf = (keys: readonly string[], options: ReadonlyArray<{ key: string; label: string }>) =>
  keys.map((key) => options.find((option) => option.key === key)?.label ?? key).join(', ')

const summary = computed(() => {
  const plan = metadata.value
  if (!plan) return null
  return {
    keys: plan.keys.map(keyLabel).join(', '),
    overwrite: plan.overwrite_keys.length > 0 ? plan.overwrite_keys.map(keyLabel).join(', ') : t('plan.metadata.overwriteNone'),
    fields: plan.fields.length > 0 ? labelsOf(plan.fields, metaFields.value) : t('plan.metadata.fieldsAll'),
    locales: plan.locales.length > 0 ? labelsOf(plan.locales, contentLanguages.value) : copy('localesAll'),
  }
})
</script>

<template>
  <section
    class="grid gap-4"
    data-metadata-outputs
  >
    <dl
      v-if="readonly && summary"
      class="grid gap-3 text-sm"
    >
      <div>
        <dt class="text-muted">{{ t('plan.metadata.keys') }}</dt>
        <dd>{{ summary.keys }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('plan.metadata.overwrite') }}</dt>
        <dd>{{ summary.overwrite }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('plan.metadata.fields') }}</dt>
        <dd>{{ summary.fields }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ copy('locales') }}</dt>
        <dd>{{ summary.locales }}</dd>
      </div>
    </dl>
    <p
      v-else-if="readonly && !linked"
      class="text-sm text-muted"
    >
      {{ t('plan.metadata.untracked') }}
    </p>

    <template v-else-if="!readonly">
      <label class="flex items-start gap-3">
        <Switch
          class="mt-0.5"
          :model-value="metadata !== null"
          @update:model-value="metadata = $event ? newMetadataOutputs() : null"
        />
        <span class="grid">
          <span class="text-sm font-medium text-primary">{{ copy('track') }}</span>
          <span class="text-sm text-muted">{{ metadata ? copy('trackDescription') : copy('untracked') }}</span>
        </span>
      </label>

      <template v-if="metadata">
        <fieldset class="grid gap-2">
          <legend class="mb-2 text-sm text-muted">{{ t('plan.metadata.keys') }}</legend>
          <div class="flex flex-wrap gap-x-4 gap-y-2">
            <label
              v-for="key in AI_METADATA_KEYS"
              :key="key"
              class="flex items-center gap-2 text-sm"
            >
              <Checkbox
                :model-value="metadata.keys.includes(key)"
                @update:model-value="setKey(key, $event === true)"
              />
              {{ keyLabel(key) }}
            </label>
          </div>
          <p
            v-if="metadata.keys.length === 0 || errors?.keys"
            class="text-sm text-destructive"
          >
            {{ errors?.keys ?? t('plan.metadata.keysRequired') }}
          </p>
        </fieldset>

        <fieldset
          v-if="metadata.keys.length > 0"
          class="grid gap-2"
          data-metadata-overwrite
        >
          <legend class="mb-2 text-sm text-muted">{{ t('plan.metadata.overwrite') }}</legend>
          <div class="flex flex-wrap gap-x-4 gap-y-2">
            <label
              v-for="key in metadata.keys"
              :key="key"
              class="flex items-center gap-2 text-sm"
            >
              <Checkbox
                :model-value="metadata.overwrite_keys.includes(key)"
                @update:model-value="metadata = { ...metadata!, overwrite_keys: toggled(metadata!.overwrite_keys, key, $event === true) }"
              />
              {{ keyLabel(key) }}
            </label>
          </div>
          <p class="text-sm text-muted">{{ t('plan.metadata.overwriteHint') }}</p>
          <p
            v-if="errors?.overwrite"
            class="text-sm text-destructive"
          >
            {{ errors.overwrite }}
          </p>
        </fieldset>

        <fieldset
          v-if="metaFields.length > 1 || metadata.fields.length > 0"
          class="grid gap-2"
          data-metadata-fields
        >
          <legend class="mb-2 text-sm text-muted">{{ t('plan.metadata.fields') }}</legend>
          <div class="flex flex-wrap gap-x-4 gap-y-2">
            <label
              v-for="option in metaFields"
              :key="option.key"
              class="flex items-center gap-2 text-sm"
            >
              <Checkbox
                :model-value="metadata.fields.includes(option.key)"
                @update:model-value="metadata = { ...metadata!, fields: toggled(metadata!.fields, option.key, $event === true) }"
              />
              {{ option.label }}
            </label>
          </div>
          <p
            v-if="metadata.fields.length === 0"
            class="text-sm text-muted"
          >
            {{ t('plan.metadata.fieldsHint') }}
          </p>
          <p
            v-if="errors?.fields"
            class="text-sm text-destructive"
          >
            {{ errors.fields }}
          </p>
        </fieldset>

        <fieldset
          class="grid gap-2"
          data-metadata-locales
        >
          <legend class="mb-2 text-sm text-muted">{{ copy('locales') }}</legend>
          <div class="flex flex-wrap gap-x-4 gap-y-2">
            <label
              v-for="option in localeOptions"
              :key="option.key"
              class="flex items-center gap-2 text-sm"
            >
              <Checkbox
                :model-value="metadata.locales.includes(option.key)"
                @update:model-value="metadata = { ...metadata!, locales: toggled(metadata!.locales, option.key, $event === true) }"
              />
              {{ option.label }}
            </label>
          </div>
          <p
            v-if="metadata.locales.length === 0"
            class="text-sm text-muted"
          >
            {{ copy('localesHint') }}
          </p>
          <p
            v-if="errors?.locales"
            class="text-sm text-destructive"
          >
            {{ errors.locales }}
          </p>
        </fieldset>
      </template>
    </template>

    <p
      v-if="errors?.metadata"
      class="text-sm text-destructive"
    >
      {{ errors.metadata }}
    </p>
  </section>
</template>
