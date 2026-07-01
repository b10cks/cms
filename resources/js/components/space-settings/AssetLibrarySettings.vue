<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'

import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import SettingsTable, {
  type ColumnDefinition,
  type TableItem,
} from '~/components/ui/settings-table.vue'

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()
const { $t } = useI18n()
const { useAccessControl } = useAuthorization()

const props = defineProps<{ space: SpaceResource }>()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))
const canUpdateSpace = computed(() => access.hasAbility('space.update'))
const assetFields = ref(deepClone(props.space.settings.asset_fields ?? []))
const newItemTemplate = {
  key: '',
  label: '',
  required: false,
}

const defaultFields = ['alt', 'description']

const rightsLicensingFields = [
  { key: 'copyright_holder', label: $t('labels.settings.assetLibrary.rights.copyrightHolder'), required: false },
  { key: 'license_type', label: $t('labels.settings.assetLibrary.rights.licenseType'), required: false },
  { key: 'license_notes', label: $t('labels.settings.assetLibrary.rights.licenseNotes'), required: false },
  { key: 'usage_restrictions', label: $t('labels.settings.assetLibrary.rights.usageRestrictions'), required: false },
]

const hasAllRightsLicensingFields = computed(() =>
  rightsLicensingFields.every((field) =>
    assetFields.value.some((existing: { key: string }) => existing.key === field.key)
  )
)

const addRightsLicensingGroup = () => {
  rightsLicensingFields.forEach((field) => addField(field))
}

const columns: ColumnDefinition[] = [
  {
    key: 'key',
    label: $t('labels.settings.assetLibrary.key'),
    type: 'text',
    placeholder: $t('labels.settings.assetLibrary.fieldKeyPlaceholder'),
    required: true,
    readonly: true,
  },
  {
    key: 'label',
    label: $t('labels.settings.assetLibrary.label'),
    type: 'text',
    placeholder: $t('labels.settings.assetLibrary.fieldLabelPlaceholder'),
    required: true,
  },
  {
    key: 'required',
    label: $t('labels.settings.assetLibrary.required'),
    width: 'w-16',
    type: 'switch',
  },
]

const removeField = (index: number) => {
  const item = assetFields.value[index] as TableItem
  if (!defaultFields.includes(item.key as string)) {
    assetFields.value.splice(index, 1)
  }
}

const addField = (newField: { key: string; label: string; required: boolean }) => {
  if (assetFields.value.find((field) => field.key === newField.key)) {
    return
  }
  assetFields.value.push(newField)
}

const saveSettings = async () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        asset_fields: assetFields.value,
      },
    },
  })
}
</script>

<template>
  <Card variant="none">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.assetLibrary.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.assetLibrary.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
          <h4 class="text-sm font-medium">{{ $t('labels.settings.assetLibrary.metadataFields') }}</h4>
          <Button
            v-if="canUpdateSpace && !hasAllRightsLicensingFields"
            variant="outline"
            size="sm"
            @click="addRightsLicensingGroup"
            >{{ $t('labels.settings.assetLibrary.rights.addGroup') }}
          </Button>
        </div>
        <p class="text-xs text-muted-foreground">
          {{ $t('labels.settings.assetLibrary.requiredHint') }}
        </p>
        <SettingsTable
          v-model:items="assetFields"
          :columns="columns"
          :new-item-template="newItemTemplate"
          :allow-sort="true"
          :empty-message="$t('labels.settings.assetLibrary.noMetadataFields')"
          :remove-button-label="$t('actions.remove')"
          @add="addField"
          @remove="removeField"
        />
      </div>
    </CardContent>
    <CardFooter>
      <Button
        v-if="canUpdateSpace"
        variant="primary"
        @click="saveSettings"
        >{{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
