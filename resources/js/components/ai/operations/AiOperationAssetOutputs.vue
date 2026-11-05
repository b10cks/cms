<script setup lang="ts">
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import { Checkbox } from '~/components/ui/checkbox'
import { Switch } from '~/components/ui/switch'
import type { AiAssetOutputs } from '~/lib/aiOperations'

/**
 * What each asset of the plan should end up with: the fields, per language,
 * and tags. Naming them makes the operation account for each one. A plan that
 * names none keeps working and reports its coverage as unverified.
 */
const props = defineProps<{
  options: ReadonlyArray<{ key: string; label: string }>
  /** The plan removes or adds tags, so they are owed */
  tagsRequired: boolean
  readonly?: boolean
  error?: string | null
}>()

const outputs = defineModel<AiAssetOutputs | null>('outputs', { required: true })

const { t } = useOperationI18n()

const hasTags = computed(() => (outputs.value?.tags ?? false) || props.tagsRequired)

const setField = (key: string, on: boolean) => {
  if (!outputs.value) return
  const fields = outputs.value.fields
  outputs.value = { ...outputs.value, fields: on ? [...new Set([...fields, key])] : fields.filter((field) => field !== key) }
}

const fieldsLabel = computed(() =>
  (outputs.value?.fields ?? []).map((key) => props.options.find((option) => option.key === key)?.label ?? key).join(', ')
)
</script>

<template>
  <fieldset
    class="grid gap-2"
    data-asset-outputs
  >
    <template v-if="readonly">
      <legend class="mb-2 text-sm text-muted">{{ t('plan.asset.legend') }}</legend>
      <template v-if="outputs">
        <p
          v-if="outputs.fields.length > 0"
          class="text-sm"
        >
          {{ fieldsLabel }}
        </p>
        <p
          v-if="hasTags"
          class="text-sm"
        >
          {{ t('plan.asset.tags') }}
        </p>
      </template>
      <p
        v-else
        class="text-sm text-muted"
      >
        {{ t('plan.asset.untracked') }}
      </p>
    </template>

    <template v-else>
      <label class="flex items-start gap-3">
        <Switch
          class="mt-0.5"
          :model-value="outputs !== null"
          @update:model-value="outputs = $event ? { fields: [], tags: true } : null"
        />
        <span class="grid">
          <span class="text-sm font-medium text-primary">{{ t('plan.asset.track') }}</span>
          <span class="text-sm text-muted">{{ outputs ? t('plan.asset.trackDescription') : t('plan.asset.untracked') }}</span>
        </span>
      </label>

      <div
        v-if="outputs"
        class="grid gap-2"
      >
        <p class="text-sm text-muted">{{ t('plan.asset.legend') }}</p>
        <div class="flex flex-wrap gap-x-4 gap-y-2">
          <label
            v-for="option in options"
            :key="option.key"
            class="flex items-center gap-2 text-sm"
          >
            <Checkbox
              :model-value="outputs.fields.includes(option.key)"
              @update:model-value="setField(option.key, $event === true)"
            />
            {{ option.label }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <Checkbox
              :model-value="hasTags"
              :disabled="tagsRequired"
              @update:model-value="outputs = { ...outputs!, tags: $event === true }"
            />
            {{ t('plan.asset.tags') }}
          </label>
        </div>
        <p
          v-if="tagsRequired"
          class="text-sm text-muted"
        >
          {{ t('plan.asset.tagsRequired') }}
        </p>
        <p
          v-if="outputs.fields.length === 0"
          class="text-sm text-muted"
        >
          {{ t('plan.asset.noFieldsPicked') }}
        </p>
      </div>
    </template>

    <p
      v-if="error"
      class="text-sm text-destructive"
    >
      {{ error }}
    </p>
  </fieldset>
</template>
