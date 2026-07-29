<script setup lang="ts">
import { Label } from 'reka-ui'
import { computed, ref, watch } from 'vue'

import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { CheckboxField, InputField, TextField } from '~/components/ui/form'
import ArrayInputField from '~/components/ui/form/ArrayInputField.vue'
import type { ColumnDefinition } from '~/components/ui/form/ArrayInputField.vue'
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'
import { useDataSources } from '~/composables/useDataSources'
import type {
  CreateDataSourcePayload,
  DataSourceResource,
  DataSourceShapeField,
  DataSourceShapeFieldType,
  UpdateDataSourcePayload,
} from '~/types/data-sources'

interface DimensionItem {
  label: string
  key: string
}

type ShapeRow = {
  name: string
  key: string
  type: DataSourceShapeFieldType
  required: boolean
  options: string
  // Original field for lossless round-trips of properties the grid
  // doesn't edit (description, default) or lossily serializes (options).
  source?: DataSourceShapeField
}

const props = defineProps<{
  open: boolean
  dataSource: DataSourceResource | null
}>()

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'close' | 'refresh'): void
}>()

const route = useRoute()
const { $t } = useI18n()
const spaceId = computed(() => route.params.space as string)

const { useCreateDataSourceMutation, useUpdateDataSourceMutation } = useDataSources(spaceId)
const { mutate: createDataSource, isPending: isCreating } = useCreateDataSourceMutation()
const { mutate: updateDataSource, isPending: isUpdating } = useUpdateDataSourceMutation()

const formData = ref<
  (CreateDataSourcePayload | UpdateDataSourcePayload) & {
    dimensions: DimensionItem[]
    settings: NonNullable<CreateDataSourcePayload['settings']>
  }
>({
  name: '',
  slug: '',
  description: '',
  dimensions: [],
  settings: {
    cache_ttl: 3600,
    dimensions_translatable: false,
  },
  is_active: true,
})

const dimensionColumns: ColumnDefinition[] = [
  {
    key: 'label',
    label: 'Display Name',
    type: 'text' as const,
    placeholder: 'e.g., Category Name',
    required: true,
    editable: true,
    creatable: true,
    validate: (value: unknown) => String(value ?? '').trim().length > 0,
  },
  {
    key: 'key',
    label: 'Key',
    type: 'text' as const,
    placeholder: 'e.g., category_name',
    required: true,
    editable: false,
    creatable: true,
    validate: (value: unknown) => /^[a-zA-Z0-9_-]+$/.test(String(value ?? '')),
    transform: (value: unknown) =>
      String(value ?? '')
        .toLowerCase()
        .replace(/[^a-zA-Z0-9_-]/g, ''),
  },
  {
    key: 'required',
    label: 'Required',
    type: 'checkbox' as const,
  },
]

const shapeRows = ref<ShapeRow[]>([])

type ValueMode = 'simple' | 'shaped'

const valueMode = ref<ValueMode>('simple')

const valueModes: Array<{ value: ValueMode; icon: string }> = [
  { value: 'simple', icon: 'lucide:type' },
  { value: 'shaped', icon: 'lucide:table-properties' },
]

// Switching back to "simple" on a source that already has a shape drops
// that shape on save — warn instead of silently discarding it.
const hadShape = computed(() => !!props.dataSource?.shape?.length)
const showDowngradeWarning = computed(
  () => isEditing.value && hadShape.value && valueMode.value === 'simple'
)

const shapeFieldTypes: DataSourceShapeFieldType[] = [
  'text',
  'textarea',
  'number',
  'boolean',
  'date',
  'option',
  'options',
]

const optionTypes: DataSourceShapeFieldType[] = ['option', 'options']

// Options are edited as a comma-separated list of "value:Label" pairs.
// Only the first colon separates value from label, so labels keep colons.
const parseOptionsString = (options: string) =>
  options
    .split(',')
    .map((option) => option.trim())
    .filter(Boolean)
    .map((option) => {
      const separator = option.indexOf(':')
      const value = (separator === -1 ? option : option.slice(0, separator)).trim()
      const name = separator === -1 ? '' : option.slice(separator + 1).trim()
      return { value, name: name || value }
    })

const serializeOptions = (options: DataSourceShapeField['options']) =>
  (options || []).map((o) => (o.name === o.value ? o.value : `${o.value}:${o.name}`)).join(', ')

const toShapeRows = (shape: DataSourceShapeField[] | null): ShapeRow[] =>
  (shape || []).map((field) => ({
    name: field.name || '',
    key: field.key,
    type: field.type,
    required: field.required || false,
    options: serializeOptions(field.options),
    source: field,
  }))

// Untouched option strings reuse the original options array, so names
// containing "," or ":" survive an unrelated save.
const rowOptions = (row: ShapeRow): DataSourceShapeField['options'] =>
  row.source?.options && serializeOptions(row.source.options) === row.options
    ? row.source.options
    : parseOptionsString(row.options)

const toShapePayload = (rows: ShapeRow[]): DataSourceShapeField[] | null =>
  rows.length
    ? rows.map((row) => ({
        key: row.key,
        type: row.type,
        name: row.name || undefined,
        description: row.source?.description,
        default: row.source?.type === row.type ? row.source.default : undefined,
        required: row.required || undefined,
        options: optionTypes.includes(row.type) ? rowOptions(row) : undefined,
      }))
    : null

const shapeColumns = computed(() => [
  {
    key: 'name',
    label: String($t('labels.datasets.shape.fieldName')),
    type: 'text' as const,
    required: true,
    validate: (value: unknown) => String(value).trim().length > 0,
  },
  {
    key: 'key',
    label: String($t('labels.datasets.shape.fieldKey')),
    type: 'text' as const,
    required: true,
    editable: false,
    validate: (value: unknown) => /^[a-zA-Z0-9_-]+$/.test(String(value)),
    transform: (value: unknown) => String(value).replace(/[^a-zA-Z0-9_-]/g, ''),
  },
  {
    key: 'type',
    label: String($t('labels.datasets.shape.fieldType')),
    type: 'select' as const,
    required: true,
    defaultValue: 'text',
    options: shapeFieldTypes.map((type) => ({
      value: type,
      label: String($t(`labels.datasets.shape.types.${type}`)),
    })),
  },
  {
    key: 'required',
    label: String($t('labels.datasets.shape.fieldRequired')),
    type: 'checkbox' as const,
    width: '60px',
  },
  {
    key: 'options',
    label: String($t('labels.datasets.shape.fieldOptions')),
    type: 'text' as const,
    placeholder: String($t('labels.datasets.shape.fieldOptionsPlaceholder')),
  },
])

const validateShapeRow = (row: Record<string, unknown>): boolean =>
  !optionTypes.includes(row.type as DataSourceShapeFieldType) ||
  parseOptionsString(String(row.options || '')).length > 0

const handleShapeFieldAdd = (item: Record<string, unknown>) => {
  if (!item.key && item.name) {
    item.key = (item.name as string)
      .toLowerCase()
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '_')
  }
}

const validateShape = (rows: ShapeRow[]): boolean => {
  if (valueMode.value !== 'shaped') return true
  if (!rows.length) return false
  const keys = rows.map((row) => row.key)
  return new Set(keys).size === keys.length && rows.every((row) => validateShapeRow(row))
}

const isEditing = computed(() => !!props.dataSource)
const isProcessing = computed(() => isCreating.value || isUpdating.value)

watch(
  () => props.dataSource,
  (newDataSource) => {
    if (newDataSource) {
      formData.value = {
        name: newDataSource.name,
        slug: newDataSource.slug,
        description: newDataSource.description,
        dimensions: newDataSource.dimensions,
        settings: {
          cache_ttl: newDataSource.settings?.cache_ttl || 3600,
          dimensions_translatable:
            (newDataSource.settings as any)?.dimensions_translatable || false,
          default_dimension_locale: (newDataSource.settings as any)?.default_dimension_locale,
        },
        is_active: newDataSource.is_active,
      }
      shapeRows.value = toShapeRows(newDataSource.shape)
      valueMode.value = newDataSource.shape?.length ? 'shaped' : 'simple'
    } else {
      formData.value = {
        name: '',
        slug: '',
        description: '',
        dimensions: [],
        settings: {
          cache_ttl: 3600,
          dimensions_translatable: false,
        },
        is_active: true,
      }
      shapeRows.value = []
      valueMode.value = 'simple'
    }
  },
  { immediate: true }
)

const handleOpenChange = (value: boolean) => {
  emit('update:open', value)
  if (!value) {
    emit('close')
  }
}

const validateDimensions = (dimensions: DimensionItem[]): boolean => {
  if (!dimensions || dimensions.length === 0) return true

  const keys = dimensions.map((d) => d.key)
  const uniqueKeys = new Set(keys)

  return uniqueKeys.size === keys.length
}

const handleSubmit = async () => {
  try {
    const payload = {
      ...formData.value,
      shape: valueMode.value === 'shaped' ? toShapePayload(shapeRows.value) : null,
    }

    if (isEditing.value && props.dataSource) {
      await updateDataSource({
        id: props.dataSource.id,
        payload: payload as UpdateDataSourcePayload,
      })
    } else {
      await createDataSource(payload as CreateDataSourcePayload)
    }

    handleOpenChange(false)
    emit('refresh')
  } catch (error) {
    console.error('Failed to save data source:', error)
  }
}

const handleNameChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  const name = target.value
  formData.value.name = name
  formData.value.slug = name
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
}

const handleDimensionAdd = (item: Record<string, unknown>) => {
  if (!item.key && item.label) {
    item.key = (item.label as string)
      .toLowerCase()
      .replace(/[^\w\s-]/g, '')
      .replace(/\s+/g, '_')
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="sm:max-w-[600px]">
      <DialogHeaderCombined
        :title="
          $t(isEditing ? 'labels.datasets.editDataSources' : 'labels.datasets.createDataSource')
        "
        :description="
          $t(
            isEditing
              ? 'labels.datasets.editDataSourceDescription'
              : 'labels.datasets.createDataSourceDescription'
          )
        "
      />

      <form @submit.prevent="handleSubmit">
        <div class="grid gap-4 py-4">
          <InputField
            v-model="formData.name"
            name="name"
            :label="$t('labels.datasets.fields.name')"
            :autofocus="true"
            required
            :disabled="isProcessing"
            @input="handleNameChange"
          />

          <InputField
            v-model="formData.slug"
            name="slug"
            :label="$t('labels.datasets.fields.slug')"
            required
            :description="$t('labels.datasets.slugDescription', { slug: formData.slug })"
            pattern="^[a-z0-9]+(-[a-z0-9]+)*$"
            :disabled="isProcessing"
          />

          <TextField
            v-model="formData.description"
            :label="$t('labels.datasets.fields.description')"
            :rows="3"
            :disabled="isProcessing"
            name="description"
          />

          <ArrayInputField
            v-model="formData.dimensions"
            name="dimensions"
            :label="$t('labels.datasets.fields.dimensions')"
            :columns="dimensionColumns"
            :disabled="isProcessing"
            :empty-message="$t('labels.datasets.noDimensions')"
            :add-button-text="$t('actions.add')"
            @add="handleDimensionAdd"
          />

          <div class="grid gap-2">
            <div>
              <div class="text-sm font-medium text-primary">
                {{ $t('labels.datasets.valueMode.label') }}
              </div>
              <div class="text-sm text-muted">
                {{ $t('labels.datasets.valueMode.description') }}
              </div>
            </div>
            <RadioGroup
              v-model="valueMode"
              :disabled="isProcessing"
              class="grid gap-2 sm:grid-cols-2"
            >
              <Label
                v-for="mode in valueModes"
                :key="mode.value"
                :for="`value-mode-${mode.value}`"
                :class="[
                  'bg-surface rounded-xl flex cursor-pointer items-start gap-2.5 p-3 transition-colors',
                  valueMode === mode.value ? 'ring ring-ring' : '',
                ]"
              >
                <RadioGroupItem
                  :id="`value-mode-${mode.value}`"
                  :value="mode.value"
                  class="mt-0.5"
                />
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-1.5 text-sm font-semibold text-primary">
                    <Icon :name="mode.icon" />
                    {{ $t(`labels.datasets.valueMode.${mode.value}.title`) }}
                  </div>
                  <div class="text-sm text-muted">
                    {{ $t(`labels.datasets.valueMode.${mode.value}.description`) }}
                  </div>
                </div>
              </Label>
            </RadioGroup>
          </div>

          <ArrayInputField
            v-if="valueMode === 'shaped'"
            v-model="shapeRows"
            name="shape"
            :label="$t('labels.datasets.shape.label')"
            :description="$t('labels.datasets.shape.description')"
            :columns="shapeColumns"
            :disabled="isProcessing"
            :empty-message="$t('labels.datasets.shape.empty')"
            :add-button-text="$t('actions.add')"
            :validate-row="validateShapeRow"
            @add="handleShapeFieldAdd"
          />

          <Alert
            v-if="showDowngradeWarning"
            color="warning"
            icon="lucide:alert-triangle"
          >
            <p class="text-sm">{{ $t('labels.datasets.valueMode.downgradeWarning') }}</p>
          </Alert>

          <CheckboxField
            v-model="formData.settings.dimensions_translatable"
            name="dimensions_translatable"
            :label="$t('labels.datasets.fields.dimensionsTranslatable')"
            :description="$t('labels.datasets.fields.dimensionsTranslatableDescription')"
            :disabled="isProcessing"
          />

          <InputField
            v-if="formData.settings.dimensions_translatable"
            v-model="formData.settings.default_dimension_locale"
            name="slug"
            required
            :label="$t('labels.datasets.fields.defaultDimensionLocale')"
            :description="
              $t('labels.datasets.defaultDimensionLocaleDescription', { slug: formData.slug })
            "
            pattern="^[a-z0-9]+(-[a-z0-9]+)*$"
            :disabled="isProcessing"
          />

          <CheckboxField
            v-model="formData.is_active"
            name="is_active"
            :label="$t('labels.datasets.fields.apiAvailability')"
            :description="$t('labels.datasets.fields.apiAvailabilityDescription')"
            :disabled="isProcessing"
          />

          <InputField
            v-model="formData.settings.cache_ttl"
            :label="$t('labels.datasets.fields.cacheTtl')"
            :description="$t('labels.datasets.fields.cacheTtlDescription')"
            :disabled="isProcessing"
            min="0"
            name="cache_ttl"
            type="number"
          />
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            :disabled="isProcessing"
            @click="handleOpenChange(false)"
          >
            {{ $t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :loading="isProcessing"
            :disabled="!validateDimensions(formData.dimensions) || !validateShape(shapeRows)"
          >
            {{
              isEditing ? $t('labels.datasets.saveChanges') : $t('labels.datasets.createDataSource')
            }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
