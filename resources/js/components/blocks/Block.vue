<script setup lang="ts">
import { AccordionContent, AccordionHeader, AccordionItem, AccordionTrigger } from 'reka-ui'
import type { Component } from 'vue'

import AssetBlock from '~/components/blocks/AssetBlock.vue'
import BlocksBlock from '~/components/blocks/BlocksBlock.vue'
import BooleanBlock from '~/components/blocks/BooleanBlock.vue'
import DateBlock from '~/components/blocks/DateBlock.vue'
import GeoBlock from '~/components/blocks/GeoBlock.vue'
import IconBlock from '~/components/blocks/IconBlock.vue'
import LinkBlock from '~/components/blocks/LinkBlock.vue'
import MarkdownBlock from '~/components/blocks/MarkdownBlock.vue'
import MetaBlock from '~/components/blocks/MetaBlock.vue'
import MultiAssetBlock from '~/components/blocks/MultiAssetBlock.vue'
import NumberBlock from '~/components/blocks/NumberBlock.vue'
import OptionBlock from '~/components/blocks/OptionBlock.vue'
import OptionsBlock from '~/components/blocks/OptionsBlock.vue'
import PluginBlock from '~/components/blocks/PluginBlock.vue'
import PriceBlock from '~/components/blocks/PriceBlock.vue'
import ReferencesBlock from '~/components/blocks/ReferencesBlock.vue'
import RichTextBlock from '~/components/blocks/RichTextBlock.vue'
import SerialBlock from '~/components/blocks/SerialBlock.vue'
import TableBlock from '~/components/blocks/TableBlock.vue'
import TextareaBlock from '~/components/blocks/TextareaBlock.vue'
import TextBlock from '~/components/blocks/TextBlock.vue'
import Icon from '~/components/Icon.vue'
import BlockType from '~/components/ui/BlockType.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { CheckboxField, InputField, SelectField, TextField } from '~/components/ui/form'
import { Switch } from '~/components/ui/switch'
import { SimpleTooltip } from '~/components/ui/tooltip'

const emit = defineEmits([
  'delete',
  'to-page',
  'update:name',
  'update:item',
  'duplicate',
  'copy',
  'rename',
])
const { $t } = useI18n()

const props = defineProps<{
  item: SchemaType
  schema: Record<string, SchemaType>
  pages: EditorPage[]
  currentPage: number
  isOpen: boolean
  readonly: boolean
  name: string
}>()

const localItem = ref<SchemaType>({ ...props.item })

const isRenaming = ref(false)
const renameValue = ref('')

const startRename = () => {
  renameValue.value = props.name
  isRenaming.value = true
}

const cancelRename = () => {
  isRenaming.value = false
  renameValue.value = ''
}

const confirmRename = async () => {
  const key = renameValue.value.trim()
  if (!key || key === props.name) {
    cancelRename()
    return
  }

  const success = await new Promise<boolean>((resolve) => emit('rename', { key, resolve }))
  if (success) {
    cancelRename()
  }
}

const handleRenameKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Enter') {
    event.preventDefault()
    confirmRename()
  } else if (event.key === 'Escape') {
    event.preventDefault()
    cancelRename()
  }
}
const translatable = [
  'text',
  'textarea',
  'markdown',
  'richtext',
  'number',
  'link',
  'meta',
  'date',
  'table',
]
const textLike = ['text', 'textarea', 'markdown', 'richtext']
const countLike = [
  'blocks',
  'references',
  'reference',
  'multi_assets',
  'multiAsset',
  'options',
  'table',
]
const operatorOptions: Array<{ label: string; value: ConditionOperator }> = [
  { label: 'Equals', value: 'equals' },
  { label: 'Does not equal', value: 'not_equals' },
  { label: 'Is one of', value: 'in' },
  { label: 'Is not one of', value: 'not_in' },
  { label: 'Is empty', value: 'is_empty' },
  { label: 'Is not empty', value: 'is_not_empty' },
  { label: 'Greater than', value: 'gt' },
  { label: 'Greater than or equal', value: 'gte' },
  { label: 'Less than', value: 'lt' },
  { label: 'Less than or equal', value: 'lte' },
  { label: 'Contains', value: 'contains' },
]
const emptyValueOperators: ConditionOperator[] = ['is_empty', 'is_not_empty']
const listOperators: ConditionOperator[] = ['in', 'not_in']
const numericOperators: ConditionOperator[] = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte']
const defaultOperators: ConditionOperator[] = [
  'equals',
  'not_equals',
  'in',
  'not_in',
  'is_empty',
  'is_not_empty',
]
const textOperators: ConditionOperator[] = [
  'equals',
  'not_equals',
  'contains',
  'in',
  'not_in',
  'is_empty',
  'is_not_empty',
]
const booleanOperators: ConditionOperator[] = ['equals', 'not_equals', 'is_empty', 'is_not_empty']

const schemas = {
  asset: AssetBlock,
  multiAsset: MultiAssetBlock,
  multi_assets: MultiAssetBlock,
  icon: IconBlock,
  blocks: BlocksBlock,
  boolean: BooleanBlock,
  link: LinkBlock,
  markdown: MarkdownBlock,
  number: NumberBlock,
  option: OptionBlock,
  options: OptionsBlock,
  reference: ReferencesBlock,
  references: ReferencesBlock,
  text: TextBlock,
  textarea: TextareaBlock,
  meta: MetaBlock,
  date: DateBlock,
  richtext: RichTextBlock,
  table: TableBlock,
  geo: GeoBlock,
  price: PriceBlock,
  plugin: PluginBlock,
  serial: SerialBlock,
} satisfies Partial<Record<CanonicalSchemaTypeName | LegacySchemaTypeName, Component>>

// The parent replaces `item` with a fresh object identity on every change, so a
// shallow (reference) watch is enough - no need to deep-traverse the schema.
watch(
  () => props.item,
  (newItem) => {
    localItem.value = { ...newItem } as SchemaType
  }
)

const updateValue = (key: string, value: unknown) => {
  const nextItem = {
    ...localItem.value,
    [key]: value,
  } as SchemaType

  localItem.value = nextItem
  emit('update:item', nextItem)
}

const updateBooleanValue = (key: string, value: unknown) => {
  updateValue(key, Boolean(value))
}

const createDefaultConditionRule = (): FieldCondition => ({
  field: '',
  operator: 'equals',
  value: '',
})

const validation = computed(() => localItem.value.validation || {})
const conditions = computed<FieldConditions | null>(() => localItem.value.conditions || null)
const isTranslatableField = computed(() => translatable.includes(localItem.value.type))
// Generated values are always present and always the same in every language, so
// "required" and "translatable" have nothing to say about them.
const isGeneratedField = computed(() => localItem.value.type === 'serial')
const translatableValue = computed(() =>
  'translatable' in localItem.value ? Boolean(localItem.value.translatable) : false
)
const conditionMode = computed<'all' | 'any'>(() =>
  conditions.value?.mode === 'any' ? 'any' : 'all'
)
const normalizedComponentType = computed<
  CanonicalSchemaTypeName | Exclude<LegacySchemaTypeName, 'block'>
>(() => {
  const type = String(localItem.value.type || '')

  if (type === 'reference') return 'references'
  if (type === 'multiAsset') return 'multi_assets'

  return type as CanonicalSchemaTypeName | Exclude<LegacySchemaTypeName, 'block'>
})
const schemaComponent = computed<Component | null>(
  () => schemas[normalizedComponentType.value] ?? null
)
const controllerFieldOptions = computed(() =>
  Object.entries(props.schema)
    .filter(([key]) => key !== props.name)
    .map(([key, field]) => ({
      label: field.name || key,
      value: key,
    }))
)

const getControllerField = (fieldKey?: string) => {
  if (!fieldKey) return null
  return props.schema[fieldKey] || null
}

const getOperatorOptions = (fieldKey?: string) => {
  const field = getControllerField(fieldKey)
  if (!field) return operatorOptions

  switch (field.type) {
    case 'boolean':
      return operatorOptions.filter((option) =>
        booleanOperators.includes(option.value as ConditionOperator)
      )
    case 'number':
    case 'date':
      return operatorOptions.filter((option) =>
        numericOperators.includes(option.value as ConditionOperator)
      )
    case 'text':
    case 'textarea':
    case 'markdown':
    case 'richtext':
    case 'link':
    case 'meta':
      return operatorOptions.filter((option) =>
        textOperators.includes(option.value as ConditionOperator)
      )
    default:
      return operatorOptions.filter((option) =>
        defaultOperators.includes(option.value as ConditionOperator)
      )
  }
}

const getConditionValueKind = (fieldKey?: string) => {
  const field = getControllerField(fieldKey)
  if (!field) return 'text'

  if (field.type === 'boolean') return 'boolean'
  if (field.type === 'number') return 'number'
  if (field.type === 'option' || field.type === 'options') {
    return Array.isArray((field as OptionSchema | OptionsSchema).options) &&
      (field as OptionSchema | OptionsSchema).options.length > 0
      ? 'option'
      : 'text'
  }
  if (field.type === 'date') return 'date'
  return 'text'
}

const setValidationValue = (key: keyof FieldValidation, value: unknown) => {
  const nextValidation = { ...localItem.value.validation }
  const isClearing = value === '' || value === null || value === undefined

  if (isClearing) {
    delete nextValidation[key]
  } else {
    nextValidation[key] = value as never
  }

  updateValue('validation', Object.keys(nextValidation).length > 0 ? nextValidation : null)

  if (['min', 'max'].includes(key)) {
    updateValue(key, isClearing ? null : value)
  }

  if (['min_items', 'max_items'].includes(key)) {
    updateValue(key === 'min_items' ? 'min' : 'max', isClearing ? null : value)
  }
}

const toggleConditions = (enabled: unknown) => {
  updateValue(
    'conditions',
    enabled
      ? {
          mode: 'all',
          rules: [createDefaultConditionRule()],
        }
      : null
  )
}

const updateCondition = (index: number, patch: Partial<FieldCondition>) => {
  const existingRule = conditions.value?.rules?.[index]
  const nextField = patch.field ?? existingRule?.field ?? ''
  const allowedOperators = getOperatorOptions(nextField)
  const nextOperator = patch.operator ?? existingRule?.operator ?? 'equals'

  const nextConditions = {
    mode: conditions.value?.mode || 'all',
    rules: [...(conditions.value?.rules || [])],
  }

  nextConditions.rules[index] = {
    field: nextField,
    operator: allowedOperators.some((option) => option.value === nextOperator)
      ? nextOperator
      : (allowedOperators[0]?.value as ConditionOperator) || 'equals',
    value: nextConditions.rules[index]?.value as FieldCondition['value'],
    ...patch,
  }

  updateValue('conditions', nextConditions)
}

const updateConditionValue = (index: number, rawValue: unknown) => {
  const operator = conditions.value?.rules?.[index]?.operator || 'equals'
  const fieldKey = conditions.value?.rules?.[index]?.field || ''
  const valueKind = getConditionValueKind(fieldKey)

  if (emptyValueOperators.includes(operator)) {
    updateCondition(index, { value: undefined })
    return
  }

  if (listOperators.includes(operator)) {
    const values = String(rawValue || '')
      .split(',')
      .map((value) => value.trim())
      .filter(Boolean)
    updateCondition(index, { value: values })
    return
  }

  if (valueKind === 'boolean') {
    updateCondition(index, { value: rawValue === true || rawValue === 'true' })
    return
  }

  if (valueKind === 'number') {
    updateCondition(index, {
      value: rawValue === '' || rawValue === null ? undefined : Number(rawValue),
    })
    return
  }

  updateCondition(index, { value: String(rawValue ?? '') })
}

const addCondition = () => {
  const nextConditions: FieldConditions = {
    mode: conditions.value?.mode || 'all',
    rules: [...(conditions.value?.rules || []), createDefaultConditionRule()],
  }

  updateValue('conditions', nextConditions)
}

const removeCondition = (index: number) => {
  const nextRules = [...(conditions.value?.rules || [])]
  nextRules.splice(index, 1)

  updateValue(
    'conditions',
    nextRules.length
      ? {
          mode: conditions.value?.mode || 'all',
          rules: nextRules,
        }
      : null
  )
}

const fieldSummaryItems = computed(() => {
  const items: Array<{ icon: string; tooltip: string }> = []

  if (localItem.value.required) {
    items.push({
      icon: 'lucide:asterisk',
      tooltip: String($t('labels.blocks.summary.required')),
    })
  }

  if (localItem.value.translatable) {
    items.push({
      icon: 'lucide:languages',
      tooltip: String($t('labels.blocks.summary.translatable')),
    })
  }

  if (localItem.value.indexable) {
    items.push({
      icon: 'lucide:database-search',
      tooltip: String($t('labels.blocks.summary.indexable')),
    })
  }

  if (conditions.value?.rules?.length) {
    items.push({
      icon: 'lucide:circle-dot-dashed',
      tooltip: String(
        $t(
          conditions.value.mode === 'any'
            ? 'labels.blocks.summary.conditionsAny'
            : 'labels.blocks.summary.conditionsAll'
        )
      ),
    })
  }

  return items
})

const getConditionValueDisplay = (condition: FieldCondition) =>
  Array.isArray(condition.value)
    ? condition.value.join(', ')
    : typeof condition.value === 'boolean'
      ? String(condition.value)
      : (condition.value as string | number | undefined)

const updateConditionMode = (value: unknown) => {
  updateValue('conditions', {
    ...(conditions.value || { rules: [] }),
    mode: value === 'any' ? 'any' : 'all',
  })
}

const updateConditionField = (index: number, value: unknown) => {
  updateCondition(index, { field: String(value || '') })
}

const updateConditionOperator = (index: number, value: unknown) => {
  updateCondition(index, {
    operator: operatorOptions.some((option) => option.value === value)
      ? (value as ConditionOperator)
      : 'equals',
  })
}
</script>

<template>
  <AccordionItem :value="name">
    <AccordionHeader class="group flex items-center gap-3">
      <div
        class="flex h-full cursor-ns-resize items-center"
        draggable
      >
        <Icon name="lucide:grip-vertical" />
      </div>
      <BlockType :type="item?.type" />
      <AccordionTrigger class="flex grow cursor-pointer text-left items-center gap-4">
        <div class="flex flex-col">
          <h4 class="font-bold">{{ item?.name || name }}</h4>
          <div class="text-xs text-muted">
            {{ $t(`labels.blocks.fieldTypes.${item?.type}.label`) }}
          </div>
        </div>
        <div
          v-if="fieldSummaryItems.length"
          class="flex items-center gap-2 text-xs text-muted-foreground"
        >
          <SimpleTooltip
            v-for="item in fieldSummaryItems"
            :key="item.icon + item.tooltip"
            :tooltip="item.tooltip"
            side="top"
            class="inline-flex"
          >
            <Icon :name="item.icon" />
          </SimpleTooltip>
        </div>
      </AccordionTrigger>
      <div
        class="ml-auto flex items-center gap-3 opacity-0 transition-opacity duration-200 ease-in-out group-hover:opacity-100"
      >
        <DropdownMenu>
          <DropdownMenuTrigger
            class="cursor-pointer hover:text-primary focus:text-primary"
            v-if="!readonly"
            :disabled="pages.length <= 1"
          >
            <Icon name="lucide:folder-input" />
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuRadioGroup :model-value="`${currentPage}`">
              <DropdownMenuRadioItem
                v-for="(page, i) in pages"
                :key="i"
                :value="`${i}`"
                @click="$emit('to-page', i)"
              >
                {{ page.header }}
              </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>
        <button
          v-if="!readonly"
          class="cursor-pointer hover:text-primary focus:text-primary"
          type="button"
          :title="$t('actions.blocks.duplicateField')"
          @click="$emit('duplicate', name)"
        >
          <Icon name="lucide:copy-plus" />
          <span class="sr-only">{{ $t('actions.blocks.duplicateField') }}</span>
        </button>
        <button
          class="cursor-pointer hover:text-primary focus:text-primary"
          type="button"
          :title="$t('actions.blocks.copyField')"
          @click="$emit('copy', name)"
        >
          <Icon name="lucide:clipboard-copy" />
          <span class="sr-only">{{ $t('actions.blocks.copyField') }}</span>
        </button>
        <button
          v-if="!readonly"
          class="cursor-pointer hover:text-destructive focus:text-destructive"
          type="button"
          :title="$t('actions.blocks.deleteField')"
          @click="$emit('delete', name)"
        >
          <Icon name="lucide:trash-2" />
        </button>
        <AccordionTrigger class="cursor-pointer">
          <Icon
            name="lucide:chevron-down"
            :class="['transition-transform duration-200 ease-in-out', isOpen && 'rotate-180']"
          />
        </AccordionTrigger>
      </div>
    </AccordionHeader>
    <AccordionContent class="flex flex-col gap-6 p-2 pt-4">
      <div class="flex items-end gap-2">
        <InputField
          class="grow"
          :model-value="isRenaming ? renameValue : name"
          :label="$t('labels.blocks.fields.slug')"
          name="key"
          :disabled="!isRenaming"
          @update:model-value="(v) => (renameValue = String(v ?? ''))"
          @keydown="handleRenameKeydown"
        >
          <template #append>
            <Button
              v-if="!readonly && !isRenaming"
              type="button"
              variant="ghost"
              size="toolbar"
              @click="startRename"
              :title="$t('actions.blocks.renameField')"
            >
              <Icon name="lucide:pencil" />
            </Button>
            <template v-else-if="isRenaming">
              <Button
                type="button"
                variant="primary"
                size="toolbar"
                :title="$t('actions.blocks.renameFieldConfirm')"
                @click="confirmRename"
              >
                <Icon name="lucide:check" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="toolbar"
                :title="$t('actions.cancel')"
                @click="cancelRename"
              >
                <Icon name="lucide:x" />
              </Button>
            </template>
          </template>
        </InputField>
      </div>
      <InputField
        :model-value="localItem.name"
        :label="$t('labels.blocks.fields.fieldName')"
        name="name"
        :disabled="readonly"
        @update:model-value="(v) => updateValue('name', v)"
      />
      <TextField
        :model-value="localItem.description"
        :label="$t('labels.blocks.fields.fieldDescription')"
        name="description"
        :disabled="readonly"
        @update:model-value="(v) => updateValue('description', v)"
      />
      <CheckboxField
        v-if="!isGeneratedField"
        :model-value="localItem.required"
        name="required"
        :label="$t('labels.blocks.fields.required')"
        :tooltip="$t('labels.blocks.fields.requiredTooltip')"
        :disabled="readonly"
        @update:model-value="(value) => updateBooleanValue('required', value)"
      />
      <CheckboxField
        v-if="isTranslatableField"
        :model-value="translatableValue"
        name="translatable"
        :label="$t('labels.blocks.fields.translatable')"
        :tooltip="$t('labels.blocks.fields.translatableTooltip')"
        :disabled="readonly"
        @update:model-value="(value) => updateBooleanValue('translatable', value)"
      />
      <CheckboxField
        :model-value="localItem.indexable"
        name="indexable"
        :label="$t('labels.blocks.fields.indexable')"
        :tooltip="$t('labels.blocks.fields.indexableDescription')"
        :disabled="readonly"
        @update:model-value="(value) => updateBooleanValue('indexable', value)"
      />
      <component
        v-if="schemaComponent"
        :is="schemaComponent"
        :name="name"
        :value="localItem as SchemaType"
        :readonly="readonly"
        @update:item-value="(key: string, value: unknown) => updateValue(key, value)"
      />
      <div class="rounded-xl bg-background p-3">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h5 class="font-semibold text-primary">
              {{ $t('labels.blocks.fields.conditions') }}
            </h5>
            <p class="text-sm text-muted-foreground">
              {{ $t('labels.blocks.fields.conditionsDescription') }}
            </p>
          </div>
          <Switch
            :model-value="!!conditions"
            name="has_conditions"
            :disabled="readonly"
            @update:model-value="toggleConditions"
          />
        </div>
        <div
          v-if="conditions"
          class="pt-6 flex flex-col gap-3"
        >
          <SelectField
            name="condition_mode"
            :model-value="conditionMode"
            :disabled="readonly"
            :label="$t('labels.blocks.fields.conditionMode')"
            :options="[
              {
                label: $t('labels.blocks.fields.conditionModeAll'),
                value: 'all',
              },
              { label: $t('labels.blocks.fields.conditionModeAny'), value: 'any' },
            ]"
            @update:model-value="updateConditionMode"
          />
          <div
            v-for="(condition, index) in conditions.rules"
            :key="`${name}-condition-${index}`"
            class="grid gap-3 rounded-lg border border-border p-3 md:grid-cols-[1fr_1fr_1fr_auto]"
          >
            <SelectField
              :name="`condition-field-${index}`"
              :model-value="condition.field"
              :disabled="readonly"
              :label="$t('labels.blocks.fields.conditionField')"
              :options="controllerFieldOptions"
              @update:model-value="(value) => updateConditionField(index, value)"
            />
            <SelectField
              :name="`condition-operator-${index}`"
              :model-value="condition.operator"
              :disabled="readonly"
              :label="$t('labels.blocks.fields.conditionOperator')"
              :options="getOperatorOptions(condition.field)"
              @update:model-value="(value) => updateConditionOperator(index, value)"
            />
            <SelectField
              v-if="getConditionValueKind(condition.field) === 'boolean'"
              :name="`condition-value-${index}`"
              :model-value="String(condition.value ?? '')"
              :disabled="
                readonly || ['is_empty', 'is_not_empty'].includes(String(condition.operator || ''))
              "
              :label="$t('labels.blocks.fields.conditionValue')"
              :options="[
                { label: $t('labels.blocks.fields.conditionTrue'), value: 'true' },
                { label: $t('labels.blocks.fields.conditionFalse'), value: 'false' },
              ]"
              @update:model-value="(value) => updateConditionValue(index, value)"
            />
            <SelectField
              v-else-if="getConditionValueKind(condition.field) === 'option'"
              :name="`condition-value-${index}`"
              :model-value="String(condition.value ?? '')"
              :disabled="
                readonly || ['is_empty', 'is_not_empty'].includes(String(condition.operator || ''))
              "
              :label="$t('labels.blocks.fields.conditionValue')"
              :options="
                (
                  ((getControllerField(condition.field) as OptionSchema | OptionsSchema | null)
                    ?.options || []) as OptionItem[]
                ).map((option) => ({
                  label: option.name,
                  value: option.value,
                }))
              "
              @update:model-value="(value) => updateConditionValue(index, value)"
            />
            <InputField
              v-else
              :name="`condition-value-${index}`"
              :model-value="getConditionValueDisplay(condition)"
              :disabled="
                readonly || ['is_empty', 'is_not_empty'].includes(String(condition.operator || ''))
              "
              :label="$t('labels.blocks.fields.conditionValue')"
              :type="getConditionValueKind(condition.field) === 'number' ? 'number' : 'text'"
              :placeholder="
                ['in', 'not_in'].includes(String(condition.operator || ''))
                  ? String($t('labels.blocks.fields.conditionListPlaceholder'))
                  : undefined
              "
              @update:model-value="(value) => updateConditionValue(index, value)"
            />
            <Button
              type="button"
              variant="ghost"
              class="self-end"
              :disabled="readonly"
              @click="removeCondition(index)"
            >
              <Icon name="lucide:trash-2" />
            </Button>
          </div>
          <Button
            type="button"
            variant="outline"
            :disabled="readonly"
            @click="addCondition"
          >
            <Icon name="lucide:plus" />
            {{ $t('actions.blocks.addCondition') }}
          </Button>
        </div>
      </div>
      <div class="rounded-xl bg-background p-3">
        <h5 class="mb-3 font-semibold text-primary">
          {{ $t('labels.blocks.fields.validation') }}
        </h5>
        <div class="grid gap-4">
          <template v-if="textLike.includes(localItem.type)">
            <InputField
              :name="`${name}-min-length`"
              :model-value="validation.min_length"
              :label="$t('labels.blocks.fields.minLength')"
              type="number"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('min_length', value)"
            />
            <InputField
              :name="`${name}-max-length`"
              :model-value="validation.max_length"
              :label="$t('labels.blocks.fields.maxLength')"
              type="number"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('max_length', value)"
            />
            <InputField
              :name="`${name}-pattern`"
              :model-value="validation.pattern"
              :label="$t('labels.blocks.fields.pattern')"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('pattern', value)"
            />
          </template>
          <template v-else-if="localItem.type === 'number' || localItem.type === 'date'">
            <InputField
              :name="`${name}-min`"
              :model-value="validation.min === null ? undefined : validation.min"
              :label="$t('labels.blocks.fields.min')"
              :type="localItem.type === 'date' ? 'text' : 'number'"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('min', value)"
            />
            <InputField
              :name="`${name}-max`"
              :model-value="validation.max === null ? undefined : validation.max"
              :label="$t('labels.blocks.fields.max')"
              :type="localItem.type === 'date' ? 'text' : 'number'"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('max', value)"
            />
          </template>
          <template v-else-if="countLike.includes(localItem.type)">
            <InputField
              :name="`${name}-min-items`"
              :model-value="validation.min_items"
              :label="$t('labels.blocks.fields.minItems')"
              type="number"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('min_items', value)"
            />
            <InputField
              :name="`${name}-max-items`"
              :model-value="validation.max_items"
              :label="$t('labels.blocks.fields.maxItems')"
              type="number"
              :disabled="readonly"
              @update:model-value="(value) => setValidationValue('max_items', value)"
            />
          </template>
        </div>
      </div>
    </AccordionContent>
  </AccordionItem>
</template>
