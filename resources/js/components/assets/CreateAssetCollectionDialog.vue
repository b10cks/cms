<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { ComboboxField, Label, SelectField, TextField } from '~/components/ui/form'
import IconName from '~/components/ui/IconName.vue'
import IconNameField from '~/components/ui/IconNameField.vue'
import { Input } from '~/components/ui/input'
import { RadioGroup, RadioGroupItem } from '~/components/ui/radio-group'
import { Spinner } from '~/components/ui/spinner'
import type {
  AssetCollectionCondition,
  AssetCollectionConditionField,
  AssetCollectionMatch,
  AssetCollectionResource,
  AssetCollectionType,
} from '~/types/assets'

const open = defineModel<boolean>('open')

const props = defineProps<{
  spaceId: string
  collection?: AssetCollectionResource | null
}>()

const emit = defineEmits<{
  created: [collection: AssetCollectionResource]
}>()

const { $t } = useI18n()
const { useCreateAssetCollectionMutation, useUpdateAssetCollectionMutation } = useAssetCollections(
  props.spaceId
)
const { mutateAsync: createCollection, isPending: isCreating } =
  useCreateAssetCollectionMutation()
const { mutateAsync: updateCollection, isPending: isUpdating } =
  useUpdateAssetCollectionMutation()

const { useAssetTagsQuery } = useAssetTags(props.spaceId)
const { data: tagsResponse } = useAssetTagsQuery({ per_page: 500 })
const { useAssetFoldersQuery } = useAssetFolders(props.spaceId)
const { data: folders } = useAssetFoldersQuery()

const isEditing = computed(() => Boolean(props.collection?.id))
const isPending = computed(() => isCreating.value || isUpdating.value)

const dialogTitle = computed(() =>
  $t(
    isEditing.value
      ? 'labels.assetCollections.editCollection'
      : 'labels.assetCollections.createCollection'
  )
)

/* ------------------------------------------------------------------ */
/* Form state                                                          */
/* ------------------------------------------------------------------ */

interface EditableCondition {
  field: AssetCollectionConditionField
  operator: string
  value: unknown
}

const name = ref('')
const description = ref('')
const icon = ref<string | null>(null)
const color = ref<string | null>(null)
const type = ref<AssetCollectionType>('manual')
const match = ref<AssetCollectionMatch>('all')
const conditions = ref<EditableCondition[]>([])

const iconNameValue = computed({
  get: () => ({ name: name.value, icon: icon.value, color: color.value }),
  set: (value: { name?: string | null; icon?: string | null; color?: string | null }) => {
    name.value = value.name ?? ''
    icon.value = value.icon ?? null
    color.value = value.color ?? null
  },
})

watch(
  () => [props.collection, open.value],
  () => {
    if (!open.value) return
    name.value = props.collection?.name ?? ''
    description.value = props.collection?.description ?? ''
    icon.value = props.collection?.icon ?? null
    color.value = props.collection?.color ?? null
    type.value = props.collection?.type ?? 'manual'
    match.value = props.collection?.rules?.match ?? 'all'
    conditions.value = (props.collection?.rules?.conditions ?? []).map((condition) => ({
      field: condition.field,
      operator: condition.operator,
      value: Array.isArray(condition.value) ? [...condition.value] : condition.value,
    }))

    if (type.value === 'smart' && !conditions.value.length) {
      addCondition()
    }
  },
  { immediate: true }
)

watch(type, (value) => {
  if (value === 'smart' && !conditions.value.length) {
    addCondition()
  }
})

/* ------------------------------------------------------------------ */
/* Rule builder configuration                                          */
/* ------------------------------------------------------------------ */

type ValueInput = 'text' | 'list' | 'number' | 'date' | 'tags' | 'folder' | 'select' | 'none'

interface FieldDefinition {
  operators: { value: string; label: string }[]
  /** value input per operator; fallback via '*' */
  inputs: Partial<Record<string, ValueInput>> & { '*'?: ValueInput }
  selectOptions?: { value: string; label: string }[]
}

const op = (value: string) => ({
  value,
  label: String($t(`labels.assetCollections.operators.${value}`)),
})

const fieldDefinitions = computed<Record<AssetCollectionConditionField, FieldDefinition>>(() => ({
  filename: { operators: [op('contains'), op('equals')], inputs: { '*': 'text' } },
  extension: { operators: [op('equals'), op('in')], inputs: { equals: 'text', in: 'list' } },
  mime_type: {
    operators: [op('equals'), op('prefix'), op('in')],
    inputs: { equals: 'text', prefix: 'text', in: 'list' },
  },
  size: {
    operators: [op('gt'), op('gte'), op('lt'), op('lte')],
    inputs: { '*': 'number' },
  },
  folder: { operators: [op('equals'), op('null')], inputs: { equals: 'folder', null: 'none' } },
  tags: { operators: [op('any'), op('all')], inputs: { '*': 'tags' } },
  rights_status: {
    operators: [op('equals')],
    inputs: { '*': 'select' },
    selectOptions: (['unrestricted', 'restricted', 'expired'] as const).map((status) => ({
      value: status,
      label: String($t(`labels.assets.rights.status.${status}`)),
    })),
  },
  license_expires_at: { operators: [op('before'), op('after')], inputs: { '*': 'date' } },
  created_at: { operators: [op('before'), op('after')], inputs: { '*': 'date' } },
  updated_at: { operators: [op('before'), op('after')], inputs: { '*': 'date' } },
  orientation: {
    operators: [op('equals')],
    inputs: { '*': 'select' },
    selectOptions: (['landscape', 'portrait', 'square'] as const).map((orientation) => ({
      value: orientation,
      label: String($t(`labels.assetCollections.orientations.${orientation}`)),
    })),
  },
  untagged: { operators: [op('equals')], inputs: { '*': 'none' } },
}))

const fieldOptions = computed(() =>
  (Object.keys(fieldDefinitions.value) as AssetCollectionConditionField[]).map((field) => ({
    value: field,
    label: String($t(`labels.assetCollections.fields.${field}`)),
  }))
)

const tagOptions = computed(() =>
  (tagsResponse.value?.data ?? []).map((tag) => ({
    value: tag.id,
    label: tag.name,
    icon: tag.icon,
    color: tag.color,
  }))
)

const folderOptions = computed(() =>
  (folders.value ?? []).map((folder) => ({ value: folder.id, label: folder.name }))
)

const inputFor = (condition: EditableCondition): ValueInput => {
  const definition = fieldDefinitions.value[condition.field]
  return definition.inputs[condition.operator] ?? definition.inputs['*'] ?? 'text'
}

const defaultValueFor = (field: AssetCollectionConditionField, operator: string): unknown => {
  const input =
    fieldDefinitions.value[field].inputs[operator] ??
    fieldDefinitions.value[field].inputs['*'] ??
    'text'

  switch (input) {
    case 'tags':
    case 'list':
      return []
    case 'number':
      return null
    case 'none':
      return field === 'untagged' ? true : null
    default:
      return ''
  }
}

function addCondition() {
  conditions.value.push({ field: 'filename', operator: 'contains', value: '' })
}

function removeCondition(index: number) {
  conditions.value.splice(index, 1)
}

function handleFieldChange(condition: EditableCondition, field: AssetCollectionConditionField) {
  condition.field = field
  condition.operator = fieldDefinitions.value[field].operators[0].value
  condition.value = defaultValueFor(field, condition.operator)
}

function handleOperatorChange(condition: EditableCondition, operator: string) {
  const previousInput = inputFor(condition)
  condition.operator = operator

  if (inputFor(condition) !== previousInput) {
    condition.value = defaultValueFor(condition.field, operator)
  }
}

const listToString = (value: unknown): string => (Array.isArray(value) ? value.join(', ') : '')

const setListValue = (condition: EditableCondition, raw: string | number) => {
  condition.value = String(raw)
    .split(',')
    .map((entry) => entry.trim())
    .filter(Boolean)
}

const isConditionValid = (condition: EditableCondition): boolean => {
  switch (inputFor(condition)) {
    case 'none':
      return true
    case 'number':
      return condition.value !== null && condition.value !== '' && !Number.isNaN(Number(condition.value))
    case 'tags':
    case 'list':
      return Array.isArray(condition.value) && condition.value.length > 0
    default:
      return Boolean(condition.value)
  }
}

const canSubmit = computed(() => {
  if (!name.value.trim() || isPending.value) return false

  if (type.value === 'smart') {
    return conditions.value.length > 0 && conditions.value.every(isConditionValid)
  }

  return true
})

/* ------------------------------------------------------------------ */
/* Submit                                                              */
/* ------------------------------------------------------------------ */

const serializeConditions = (): AssetCollectionCondition[] => {
  return conditions.value.map((condition) => {
    let value: unknown = condition.value

    switch (inputFor(condition)) {
      case 'number':
        value = Number(condition.value)
        break
      case 'none':
        value = condition.field === 'untagged' ? true : null
        break
    }

    return {
      field: condition.field,
      operator: condition.operator,
      value,
    } as AssetCollectionCondition
  })
}

const handleSubmit = async () => {
  if (!canSubmit.value) return

  const rules =
    type.value === 'smart'
      ? { match: match.value, conditions: serializeConditions() }
      : null

  const shared = {
    name: name.value.trim(),
    description: description.value.trim() || null,
    icon: icon.value,
    color: color.value,
    rules,
  }

  if (isEditing.value && props.collection) {
    await updateCollection({ id: props.collection.id, payload: shared })
  } else {
    const created = await createCollection({ ...shared, type: type.value })
    emit('created', created)
  }

  open.value = false
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="open = $event"
  >
    <DialogContent class="sm:max-w-2xl">
      <DialogHeaderCombined
        :title="dialogTitle"
        :description="$t('labels.assetCollections.dialogDescription')"
      />

      <div class="flex max-h-[70vh] flex-col gap-6 overflow-y-auto px-1">
        <IconNameField
          v-model="iconNameValue"
          :label="$t('labels.assetCollections.fieldLabels.name')"
          :placeholder="$t('labels.assetCollections.fieldLabels.namePlaceholder')"
          name="collection_name"
        />

        <TextField
          v-model="description"
          name="collection_description"
          :label="$t('labels.assetCollections.fieldLabels.description')"
          :placeholder="$t('labels.assetCollections.fieldLabels.descriptionPlaceholder')"
          :rows="2"
        />

        <div class="flex flex-col gap-2">
          <Label :label="String($t('labels.assetCollections.fieldLabels.type'))" />
          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="option in (['manual', 'smart'] as const)"
              :key="option"
              type="button"
              :disabled="isEditing"
              :class="[
                'flex flex-col gap-1 rounded-md border p-3 text-left transition-colors',
                type === option ? 'border-accent bg-accent/10' : 'border-border hover:bg-input',
                isEditing && type !== option ? 'opacity-50' : '',
                isEditing ? 'cursor-not-allowed' : 'cursor-pointer',
              ]"
              @click="type = option"
            >
              <span class="flex items-center gap-2 font-semibold">
                <Icon :name="option === 'smart' ? 'lucide:sparkles' : 'lucide:layers'" />
                {{ $t(`labels.assetCollections.types.${option}`) }}
              </span>
              <span class="text-xs text-muted">
                {{ $t(`labels.assetCollections.types.${option}Description`) }}
              </span>
            </button>
          </div>
        </div>

        <div
          v-if="type === 'smart'"
          class="flex flex-col gap-4"
        >
          <div class="flex items-center gap-4">
            <Label :label="String($t('labels.assetCollections.rules.matchLabel'))" />
            <RadioGroup
              v-model="match"
              class="flex items-center gap-4"
            >
              <label
                v-for="matchOption in (['all', 'any'] as const)"
                :key="matchOption"
                class="flex cursor-pointer items-center gap-2 text-sm"
              >
                <RadioGroupItem :value="matchOption" />
                {{ $t(`labels.assetCollections.rules.match.${matchOption}`) }}
              </label>
            </RadioGroup>
          </div>

          <div class="flex flex-col gap-2">
            <div
              v-for="(condition, index) in conditions"
              :key="index"
              class="flex items-start gap-2 rounded-md bg-surface p-2"
            >
              <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-start">
                <SelectField
                  :model-value="condition.field"
                  :name="`condition_${index}_field`"
                  :options="fieldOptions"
                  class="w-full shrink-0 sm:w-40"
                  @update:model-value="handleFieldChange(condition, $event as AssetCollectionConditionField)"
                />
                <SelectField
                  :model-value="condition.operator"
                  :name="`condition_${index}_operator`"
                  :options="fieldDefinitions[condition.field].operators"
                  class="w-full shrink-0 sm:w-36"
                  @update:model-value="handleOperatorChange(condition, $event as string)"
                />

                <div class="min-w-0 flex-1">
                  <template v-if="inputFor(condition) === 'text'">
                    <Input
                      :model-value="(condition.value as string) ?? ''"
                      :placeholder="String($t('labels.assetCollections.rules.valuePlaceholder'))"
                      @update:model-value="condition.value = $event"
                    />
                  </template>
                  <template v-else-if="inputFor(condition) === 'list'">
                    <Input
                      :model-value="listToString(condition.value)"
                      :placeholder="String($t('labels.assetCollections.rules.listPlaceholder'))"
                      @update:model-value="setListValue(condition, $event)"
                    />
                  </template>
                  <template v-else-if="inputFor(condition) === 'number'">
                    <Input
                      type="number"
                      :model-value="(condition.value as number) ?? ''"
                      :placeholder="String($t('labels.assetCollections.rules.sizePlaceholder'))"
                      @update:model-value="condition.value = $event"
                    />
                  </template>
                  <template v-else-if="inputFor(condition) === 'date'">
                    <Input
                      type="date"
                      :model-value="(condition.value as string) ?? ''"
                      @update:model-value="condition.value = $event"
                    />
                  </template>
                  <template v-else-if="inputFor(condition) === 'tags'">
                    <ComboboxField
                      :model-value="(condition.value as string[]) ?? []"
                      :name="`condition_${index}_tags`"
                      :placeholder="$t('labels.assetTags.fields.namePlaceholder')"
                      :options="tagOptions"
                      multiple
                      searchable
                      :empty-text="$t('labels.assetTags.noTags')"
                      @update:model-value="condition.value = $event"
                    >
                      <template #option="{ option }">
                        <IconName
                          :icon="option.icon"
                          :color="option.color"
                          :name="option.label"
                        />
                      </template>
                    </ComboboxField>
                  </template>
                  <template v-else-if="inputFor(condition) === 'folder'">
                    <SelectField
                      :model-value="(condition.value as string) ?? undefined"
                      :name="`condition_${index}_folder`"
                      :options="folderOptions"
                      :placeholder="String($t('labels.assetCollections.rules.folderPlaceholder'))"
                      @update:model-value="condition.value = $event"
                    />
                  </template>
                  <template v-else>
                    <div class="py-2 text-sm text-muted">
                      {{ $t('labels.assetCollections.rules.noValueNeeded') }}
                    </div>
                  </template>
                </div>
              </div>

              <Button
                size="toolbar"
                variant="ghost"
                class="mt-1 shrink-0 text-destructive hover:text-destructive"
                :aria-label="String($t('labels.assetCollections.rules.removeCondition'))"
                @click="removeCondition(index)"
              >
                <Icon name="lucide:x" />
              </Button>
            </div>

            <Button
              variant="ghost"
              class="self-start"
              @click="addCondition"
            >
              <Icon name="lucide:plus" />
              {{ $t('labels.assetCollections.rules.addCondition') }}
            </Button>
          </div>
        </div>
      </div>

      <DialogFooter>
        <Button @click="open = false">
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          variant="primary"
          :disabled="!canSubmit"
          @click="handleSubmit"
        >
          <Spinner v-if="isPending" />
          {{ $t(isEditing ? 'actions.assetCollections.save' : 'actions.create') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
