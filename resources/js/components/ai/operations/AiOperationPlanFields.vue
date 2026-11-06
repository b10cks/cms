<script setup lang="ts">
import AiOperationAssetWrites from '~/components/ai/operations/AiOperationAssetWrites.vue'
import AiOperationMetadataOutputs from '~/components/ai/operations/AiOperationMetadataOutputs.vue'
import AiOperationStructure from '~/components/ai/operations/AiOperationStructure.vue'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import { TextField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { Switch } from '~/components/ui/switch'
import type {
  AiEditablePlan,
  AiOperationKind,
  AiOperationScope,
  AiPlanOptionValue,
  AiTranslationInclude,
} from '~/lib/aiOperations'
import { AI_ASSET_PERMISSIONS, AI_TRANSLATION_INCLUDE, tagsRequired } from '~/lib/aiOperations'
import { languageName } from '~/lib/languageNames'

/**
 * The plan as product controls: what the AI will do, its rules, and per
 * kind the target locales, kinds of work and linked metadata (translation) or the
 * permissions, the outputs, fields and languages written and the planned
 * structure (assets), or the metadata to generate (general tasks on content).
 * Read only once approved.
 */
const plan = defineModel<AiEditablePlan>('plan', { required: true })

const props = defineProps<{
  spaceId: string
  kind: AiOperationKind
  /** The operation's scope; asset plans offer the fields of its folders */
  scope: AiOperationScope
  /** Block slugs in the frozen scope; metadata plans offer their meta fields */
  groups?: readonly string[]
  readonly?: boolean
  /** Field errors from the last save, keyed like the plan (`locales`, `structure.folders.0`) */
  errors?: Record<string, string>
}>()

const { locale } = useI18n()
const { t: tOp } = useOperationI18n()
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => props.spaceId)

/** Target locales: every space language but the default. */
const locales = computed(() =>
  (space.value?.settings.languages ?? []).map((language) => ({
    code: language.code,
    name: languageName(language.code, space.value?.settings.languages, locale.value),
  }))
)

const toggle = <T extends string>(list: T[], value: T, on: boolean): T[] =>
  on ? [...new Set([...list, value])] : list.filter((entry) => entry !== value)

const setLocale = (code: string, on: boolean) => {
  plan.value = { ...plan.value, locales: toggle(plan.value.locales, code, on) }
}

const setInclude = (kind: AiTranslationInclude, on: boolean) => {
  plan.value = { ...plan.value, include: toggle(plan.value.include, kind, on) }
}

const setRule = (index: number, value: string) => {
  plan.value = { ...plan.value, rules: plan.value.rules.map((rule, i) => (i === index ? value : rule)) }
}

const removeRule = (index: number) => {
  plan.value = { ...plan.value, rules: plan.value.rules.filter((_, i) => i !== index) }
}

const addRule = () => {
  plan.value = { ...plan.value, rules: [...plan.value.rules, ''] }
}

const setPermission = (name: (typeof AI_ASSET_PERMISSIONS)[number], on: boolean) => {
  plan.value = { ...plan.value, permissions: { ...plan.value.permissions, [name]: on } }
}

const setOption = (key: string, value: AiPlanOptionValue) => {
  plan.value = { ...plan.value, options: { ...plan.value.options, [key]: value } }
}

const options = computed(() => Object.entries(plan.value.options))

const optionLabel = (key: string) => key.replaceAll('_', ' ')

const metadataErrors = computed(() => ({
  metadata: props.errors?.metadata,
  keys: props.errors?.['metadata.keys'],
  overwrite: props.errors?.['metadata.overwrite_keys'],
  fields: props.errors?.['metadata.fields'],
  locales: props.errors?.['metadata.locales'],
}))

const errorFor = (prefix: string) =>
  Object.entries(props.errors ?? {}).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1] ?? null
</script>

<template>
  <div class="grid gap-5">
    <TextField
      id="ai-operation-summary"
      :model-value="plan.summary"
      name="ai-operation-summary"
      auto-size
      :readonly="readonly"
      :label="$t('components.aiOperations.plan.summary')"
      :error="errorFor('summary')"
      @update:model-value="plan = { ...plan, summary: String($event) }"
    />

    <section
      v-if="kind === 'translation'"
      class="grid gap-4"
    >
      <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm text-muted">{{ $t('components.aiOperations.plan.locales') }}</legend>
        <p
          v-if="locales.length === 0"
          class="text-sm text-muted"
        >
          {{ $t('components.aiOperations.plan.noLocales') }}
        </p>
        <div class="flex flex-wrap gap-x-4 gap-y-2">
          <label
            v-for="option in locales"
            :key="option.code"
            class="flex items-center gap-2 text-sm"
          >
            <Checkbox
              :model-value="plan.locales.includes(option.code)"
              :disabled="readonly"
              @update:model-value="setLocale(option.code, $event === true)"
            />
            {{ option.name }}
          </label>
        </div>
        <p
          v-if="errorFor('locales')"
          class="text-sm text-destructive"
        >
          {{ errorFor('locales') }}
        </p>
      </fieldset>

      <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm text-muted">{{ $t('components.aiOperations.plan.include') }}</legend>
        <label
          v-for="option in AI_TRANSLATION_INCLUDE"
          :key="option"
          class="flex items-start gap-2.5"
        >
          <Checkbox
            class="mt-0.5"
            :model-value="plan.include.includes(option)"
            :disabled="readonly"
            @update:model-value="setInclude(option, $event === true)"
          />
          <span class="grid">
            <span class="text-sm font-medium text-primary">{{ $t(`components.aiOperations.plan.includeKinds.${option}.title`) }}</span>
            <span class="text-sm text-muted">{{ $t(`components.aiOperations.plan.includeKinds.${option}.description`) }}</span>
          </span>
        </label>
        <p
          v-if="errorFor('include')"
          class="text-sm text-destructive"
        >
          {{ errorFor('include') }}
        </p>
        <p class="text-sm text-muted">{{ $t('components.aiOperations.plan.translationKeeps') }}</p>
      </fieldset>

      <AiOperationMetadataOutputs
        linked
        :target-locales="plan.locales"
        :metadata="plan.metadata"
        :space-id="spaceId"
        :groups="groups ?? []"
        :readonly="readonly"
        :errors="metadataErrors"
        @update:metadata="plan = { ...plan, metadata: $event }"
      />
    </section>

    <template v-if="kind === 'assets'">
      <fieldset class="grid gap-3">
        <legend class="mb-1 text-sm text-muted">{{ $t('components.aiOperations.plan.permissions') }}</legend>
        <p class="text-sm text-muted">{{ $t('components.aiOperations.plan.permissionsDefault') }}</p>
        <label
          v-for="permission in AI_ASSET_PERMISSIONS"
          :key="permission"
          class="flex items-start gap-3"
        >
          <Switch
            class="mt-0.5"
            :model-value="plan.permissions[permission]"
            :disabled="readonly"
            @update:model-value="setPermission(permission, $event)"
          />
          <span class="grid">
            <span class="text-sm font-medium text-primary">{{ $t(`components.aiOperations.plan.permissionKinds.${permission}.title`) }}</span>
            <span class="text-sm text-muted">{{ $t(`components.aiOperations.plan.permissionKinds.${permission}.description`) }}</span>
          </span>
        </label>
        <p
          v-if="errorFor('permissions')"
          class="text-sm text-destructive"
        >
          {{ errorFor('permissions') }}
        </p>
      </fieldset>

      <AiOperationAssetWrites
        :space-id="spaceId"
        :scope="scope"
        :overwrite="plan.permissions.overwrite"
        :tags-required="tagsRequired(plan)"
        :readonly="readonly"
        :errors="{ overwrite_fields: errorFor('overwrite_fields'), languages: errorFor('languages'), outputs: errorFor('outputs') }"
        :fields="plan.overwrite_fields"
        :languages="plan.languages"
        :outputs="plan.outputs"
        @update:fields="plan = { ...plan, overwrite_fields: $event }"
        @update:languages="plan = { ...plan, languages: $event }"
        @update:outputs="plan = { ...plan, outputs: $event }"
      />

      <AiOperationStructure
        :structure="plan.structure"
        :space-id="spaceId"
        :readonly="readonly"
        :move-allowed="plan.permissions.move"
        :errors="errors"
        @update:structure="plan = { ...plan, structure: $event }"
      />
    </template>

    <AiOperationMetadataOutputs
      v-if="kind === 'general' && scope.type === 'content'"
      :metadata="plan.metadata"
      :space-id="spaceId"
      :groups="groups ?? []"
      :readonly="readonly"
      :errors="metadataErrors"
      @update:metadata="plan = { ...plan, metadata: $event }"
    />
    <p
      v-else-if="kind === 'general'"
      class="text-sm text-muted"
      data-plan-unverified
    >
      {{ tOp('plan.metadata.untracked') }}
    </p>

    <fieldset class="grid gap-2">
      <legend class="mb-2 text-sm text-muted">{{ $t('components.aiOperations.plan.rules') }}</legend>
      <p
        v-if="plan.rules.length === 0"
        class="text-sm text-muted"
      >
        {{ $t('components.aiOperations.plan.noRules') }}
      </p>
      <div
        v-for="(rule, index) in plan.rules"
        :key="index"
        class="flex items-center gap-2"
      >
        <Input
          :model-value="rule"
          :readonly="readonly"
          :aria-label="$t('components.aiOperations.plan.rule', { number: index + 1 })"
          @update:model-value="setRule(index, String($event))"
        />
        <Button
          v-if="!readonly"
          size="toolbar"
          variant="ghost"
          :aria-label="$t('components.aiOperations.plan.removeRule')"
          @click="removeRule(index)"
        >
          <Icon name="lucide:x" />
        </Button>
      </div>
      <p
        v-if="errorFor('rules')"
        class="text-sm text-destructive"
      >
        {{ errorFor('rules') }}
      </p>
      <div v-if="!readonly">
        <Button
          size="sm"
          variant="ghost"
          @click="addRule"
        >
          <Icon name="lucide:plus" />
          {{ $t('components.aiOperations.plan.addRule') }}
        </Button>
      </div>
    </fieldset>

    <fieldset
      v-if="options.length > 0"
      class="grid gap-2"
    >
      <legend class="mb-2 text-sm text-muted">{{ $t('components.aiOperations.plan.options') }}</legend>
      <div
        v-for="[key, value] in options"
        :key="key"
        class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] items-center gap-3"
      >
        <span class="truncate text-sm first-letter:uppercase">{{ optionLabel(key) }}</span>
        <Switch
          v-if="typeof value === 'boolean'"
          :model-value="value"
          :disabled="readonly"
          :aria-label="optionLabel(key)"
          @update:model-value="setOption(key, $event)"
        />
        <Input
          v-else-if="typeof value === 'number'"
          type="number"
          :model-value="value"
          :readonly="readonly"
          :aria-label="optionLabel(key)"
          @update:model-value="setOption(key, Number($event))"
        />
        <div
          v-else-if="Array.isArray(value)"
          class="flex flex-wrap gap-1"
        >
          <span
            v-for="entry in value"
            :key="String(entry)"
            class="rounded-md bg-chip px-1.5 py-0.5 text-sm text-chip-foreground"
          >
            {{ entry }}
          </span>
        </div>
        <Input
          v-else
          :model-value="value ?? ''"
          :readonly="readonly"
          :aria-label="optionLabel(key)"
          @update:model-value="setOption(key, String($event))"
        />
      </div>
      <p
        v-if="errorFor('options')"
        class="text-sm text-destructive"
      >
        {{ errorFor('options') }}
      </p>
    </fieldset>
  </div>
</template>
