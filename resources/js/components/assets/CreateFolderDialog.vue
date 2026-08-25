<script setup lang="ts">
import {
  AccordionContent,
  AccordionHeader,
  AccordionItem,
  AccordionRoot,
  AccordionTrigger,
} from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { TextField } from '~/components/ui/form'
import IconNameField from '~/components/ui/IconNameField.vue'
import SettingsTable, {
  type ColumnDefinition,
  type TableItem,
} from '~/components/ui/settings-table.vue'

const props = withDefaults(
  defineProps<{
    spaceId: string
    parentFolderId?: string | null
    folder?: AssetFolderResource | null
    open: boolean
  }>(),
  {
    parentFolderId: null,
    folder: null,
  }
)

const emit = defineEmits(['update:open'])
const { t } = useI18n()

const { useCreateAssetFolderMutation, useUpdateAssetFolderMutation } = useAssetFolders(
  props.spaceId
)
const { getEffectiveFields, getFieldStates } = useAssetRequirements(props.spaceId)
const { mutateAsync: createFolder } = useCreateAssetFolderMutation()
const { mutateAsync: updateFolder } = useUpdateAssetFolderMutation()

const isEditMode = computed(() => !!props.folder)
const title = computed(() => {
  return isEditMode.value ? t('labels.assetFolders.edit') : t('labels.assetFolders.create')
})
const description = computed(() => {
  return isEditMode.value
    ? t('labels.assetFolders.editDescription')
    : t('labels.assetFolders.createDescription')
})
const submitLabel = computed(() => {
  return isEditMode.value ? t('actions.saveChanges') : t('actions.create')
})

const fieldColumns: ColumnDefinition[] = [
  {
    key: 'key',
    label: String(t('labels.settings.assetLibrary.key')),
    type: 'text',
    readonly: true,
  },
  {
    key: 'label',
    label: String(t('labels.settings.assetLibrary.label')),
    type: 'text',
    readonly: true,
  },
  {
    key: 'enabled',
    label: String(t('labels.assetFolders.metadata.enabled')),
    type: 'switch',
  },
  {
    key: 'required',
    label: String(t('labels.settings.assetLibrary.required')),
    type: 'switch',
  },
]

const additionalFieldColumns: ColumnDefinition[] = [
  {
    key: 'key',
    label: String(t('labels.settings.assetLibrary.key')),
    type: 'text',
    placeholder: String(t('labels.settings.assetLibrary.fieldKeyPlaceholder')),
    required: true,
  },
  {
    key: 'label',
    label: String(t('labels.settings.assetLibrary.label')),
    type: 'text',
    placeholder: String(t('labels.settings.assetLibrary.fieldLabelPlaceholder')),
    required: true,
  },
  {
    key: 'required',
    label: String(t('labels.settings.assetLibrary.required')),
    type: 'switch',
  },
]

const SETTINGS_SECTION_VALUE = 'metadata-settings'

const folder = ref<UpsertAssetFolderPayload>({
  name: '',
  description: null,
  color: null,
  icon: 'folder',
  parent_id: props.parentFolderId || null,
  settings: {
    field_overrides: [],
    additional_fields: [],
  },
})
const fieldRows = ref<AssetFolderFieldState[]>([])
const additionalFields = ref<SpaceAssetField[]>([])
const isLoading = ref(false)
const errorMessage = ref<string>('')
const settingsAccordionValue = ref<string>()

const resolveParentFolderId = (): string | null => {
  return props.folder?.parent_id ?? props.parentFolderId ?? null
}

const cloneFieldOverrides = (overrides?: AssetFieldOverride[] | null): AssetFieldOverride[] => {
  return (overrides || []).map((override) => ({
    key: String(override.key),
    ...(typeof override.enabled === 'boolean' ? { enabled: override.enabled } : {}),
    ...(typeof override.required === 'boolean' ? { required: override.required } : {}),
  }))
}

const cloneAdditionalFields = (fields?: SpaceAssetField[] | null): SpaceAssetField[] => {
  return (fields || []).map((field) => ({
    key: String(field.key),
    label: String(field.label),
    required: !!field.required,
  }))
}

const normalizeFolderSettings = (settings?: AssetFolderSettings | null): AssetFolderSettings => {
  return {
    field_overrides: cloneFieldOverrides(settings?.field_overrides),
    additional_fields: cloneAdditionalFields(settings?.additional_fields),
  }
}

const hasCustomSettings = (settings?: AssetFolderSettings | null): boolean => {
  return !!settings?.field_overrides?.length || !!settings?.additional_fields?.length
}

const getBaseFieldMap = (): Map<string, AssetFolderFieldState> => {
  return new Map(
    getFieldStates({
      parentFolderId: resolveParentFolderId(),
    }).map((field) => [field.key, { ...field }])
  )
}

const buildFieldOverridePayload = (rows: AssetFolderFieldState[]): AssetFieldOverride[] => {
  const baseFieldMap = getBaseFieldMap()

  return rows.flatMap((field) => {
    const inherited = baseFieldMap.get(field.key)

    if (!inherited) {
      return []
    }

    const override: AssetFieldOverride = { key: field.key }
    let changed = false

    if (field.enabled !== inherited.enabled) {
      override.enabled = field.enabled
      changed = true
    }

    if (field.required !== inherited.required) {
      override.required = field.required
      changed = true
    }

    return changed ? [override] : []
  })
}

const buildCurrentSettings = (): AssetFolderSettings => {
  return {
    field_overrides: buildFieldOverridePayload(fieldRows.value),
    additional_fields: additionalFields.value.map((field) => ({
      key: String(field.key),
      label: String(field.label),
      required: !!field.required,
    })),
  }
}

const buildFieldRows = (settings: AssetFolderSettings): AssetFolderFieldState[] => {
  const ownAdditionalFieldKeys = new Set(
    (settings.additional_fields || []).map((field) => field.key)
  )

  return getFieldStates({
    folderId: props.folder?.id ?? null,
    parentFolderId: resolveParentFolderId(),
    settings,
  })
    .filter((field) => !(field.custom && ownAdditionalFieldKeys.has(field.key) && !field.inherited))
    .map((field) => ({ ...field }))
}

const rebuildFieldRows = () => {
  fieldRows.value = buildFieldRows(buildCurrentSettings())
}

const effectiveFieldCount = computed(() => {
  return getEffectiveFields({
    folderId: props.folder?.id ?? null,
    parentFolderId: resolveParentFolderId(),
    settings: buildCurrentSettings(),
  }).length
})

const hasCustomSettingsConfigured = computed(() => hasCustomSettings(buildCurrentSettings()))

const updateOpenState = (value: boolean) => {
  emit('update:open', value)
  if (!value) {
    resetForm()
  }
}

const resetForm = () => {
  const settings = normalizeFolderSettings(props.folder?.settings)

  folder.value = props.folder
    ? {
        name: props.folder.name,
        description: props.folder.description,
        color: props.folder.color,
        icon: props.folder.icon || 'folder',
        parent_id: props.folder.parent_id,
        settings,
      }
    : {
        name: '',
        description: null,
        color: null,
        icon: 'folder',
        parent_id: props.parentFolderId || null,
        settings,
      }

  additionalFields.value = cloneAdditionalFields(settings.additional_fields)
  fieldRows.value = buildFieldRows(settings)
  settingsAccordionValue.value =
    isEditMode.value && hasCustomSettings(settings) ? SETTINGS_SECTION_VALUE : undefined
  errorMessage.value = ''
}

const hasDuplicateFieldKey = (key: string): boolean => {
  const normalizedKey = key.trim()
  const existingKeys = new Set([
    ...getBaseFieldMap().keys(),
    ...additionalFields.value.map((field) => field.key),
  ])

  return existingKeys.has(normalizedKey)
}

const addAdditionalField = (item: TableItem) => {
  const normalizedKey = String(item.key || '').trim()
  const label = String(item.label || '').trim()

  if (!normalizedKey) {
    return
  }

  if (hasDuplicateFieldKey(normalizedKey)) {
    errorMessage.value = String(t('labels.assetFolders.metadata.duplicateKey'))
    return
  }

  errorMessage.value = ''
  additionalFields.value.push({
    key: normalizedKey,
    label,
    required: !!item.required,
  })
  rebuildFieldRows()
}

const removeAdditionalField = (index: number) => {
  additionalFields.value.splice(index, 1)
  rebuildFieldRows()
}

const handleSubmit = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const payload: UpsertAssetFolderPayload = {
      ...folder.value,
      settings: buildCurrentSettings(),
    }

    if (isEditMode.value && props.folder) {
      await updateFolder({
        id: props.folder.id,
        payload,
      })
    } else {
      await createFolder(payload)
    }

    updateOpenState(false)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : t('errors.assetFolders.create')
  } finally {
    isLoading.value = false
  }
}

watch(
  [() => props.parentFolderId, () => props.folder],
  () => {
    resetForm()
  },
  { immediate: true }
)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="updateOpenState"
  >
    <DialogContent
      class="sm:max-w-4xl"
      submit-shortcut
      @submit="!isLoading && handleSubmit()"
    >
      <DialogHeaderCombined
        :title="title"
        :description="description"
      />

      <form
        class="space-y-6"
        @submit.prevent="handleSubmit"
      >
        <div class="grid gap-4 py-2">
          <IconNameField
            v-model="folder"
            :label="$t('labels.assetFolders.fields.name')"
            name="name"
          />
          <TextField
            v-model="folder.description"
            name="description"
            :label="$t('labels.assetFolders.fields.description')"
            :placeholder="$t('labels.assetFolders.fields.descriptionPlaceholder')"
          />
        </div>

        <AccordionRoot
          v-slot="{ modelValue }"
          v-model="settingsAccordionValue"
          type="single"
          collapsible
          class="rounded-lg border border-input"
        >
          <AccordionItem :value="SETTINGS_SECTION_VALUE">
            <AccordionHeader>
              <AccordionTrigger class="flex w-full items-center gap-4 p-4 text-left">
                <div class="grid">
                  <h3 class="font-semibold text-primary">
                    {{ $t('labels.assetFolders.metadata.title') }}
                  </h3>
                  <p class="text-sm text-muted">
                    {{ $t('labels.assetFolders.metadata.description') }}
                  </p>
                </div>
                <Icon
                  name="lucide:chevron-down"
                  :class="[
                    'ml-auto shrink-0 transition-transform duration-200',
                    modelValue === SETTINGS_SECTION_VALUE && 'rotate-180',
                  ]"
                />
              </AccordionTrigger>
            </AccordionHeader>
            <AccordionContent class="space-y-5 border-t border-input p-4 pt-4">
              <div class="space-y-2">
                <h4 class="text-sm font-medium">
                  {{ $t('labels.assetFolders.metadata.fieldBehaviour') }}
                </h4>
                <p class="text-xs text-muted-foreground">
                  {{ $t('labels.assetFolders.metadata.requiredHint') }}
                </p>
                <SettingsTable
                  v-model:items="fieldRows"
                  :columns="fieldColumns"
                  :show-add-row="false"
                  :empty-message="$t('labels.assetFolders.metadata.noInheritedFields')"
                />
              </div>

              <div class="space-y-2">
                <h4 class="text-sm font-medium">
                  {{ $t('labels.assetFolders.metadata.additionalFields') }}
                </h4>
                <p class="text-xs text-muted-foreground">
                  {{ $t('labels.assetFolders.metadata.additionalFieldsDescription') }}
                </p>
                <SettingsTable
                  v-model:items="additionalFields"
                  :columns="additionalFieldColumns"
                  :empty-message="$t('labels.assetFolders.metadata.noAdditionalFields')"
                  @add="addAdditionalField"
                  @remove="removeAdditionalField"
                />
              </div>
            </AccordionContent>
          </AccordionItem>
        </AccordionRoot>

        <div v-if="errorMessage">
          <div class="mt-1 text-sm text-red-500">
            {{ errorMessage }}
          </div>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="updateOpenState(false)"
          >
            {{ $t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :loading="isLoading"
          >
            {{ submitLabel }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
