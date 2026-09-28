<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'

import AssetAiClassifyDialog from '~/components/assets/AssetAiClassifyDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import { ComboboxField, Label } from '~/components/ui/form'
import SettingsTable, {
  type ColumnDefinition,
  type TableItem,
} from '~/components/ui/settings-table.vue'
import { Switch } from '~/components/ui/switch'

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()
const { $t } = useI18n()
const { useAccessControl } = useAuthorization()

const props = defineProps<{ space: SpaceResource }>()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))
const canUpdateSpace = computed(() => access.hasAbility('space.update'))
const canManageAssets = computed(() => access.hasAbility('assets.manage'))
const assetFields = ref(deepClone(props.space.settings.asset_fields ?? []))

const aiEnabled = computed(() => props.space.settings.ai?.enabled !== false)
const classification = props.space.settings.ai?.asset_classification
const autoOnUpload = ref(classification?.auto_on_upload ?? false)
const suggestTags = ref(classification?.suggest_tags ?? false)
const allowedFields = ref<string[]>(
  (classification?.allowed_fields ?? ['title', 'alt', 'description']).filter((key) =>
    assetFields.value.some((field) => field.key === key)
  )
)
const classifyAllOpen = ref(false)

const allowedFieldOptions = computed(() =>
  assetFields.value.map((field) => ({ value: field.key, label: field.label || field.key }))
)
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
        ai: {
          ...props.space.settings.ai,
          asset_classification: {
            auto_on_upload: autoOnUpload.value,
            suggest_tags: suggestTags.value,
            allowed_fields: allowedFields.value,
          },
        },
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

      <div
        v-if="aiEnabled"
        class="space-y-4 border-t border-border pt-6"
      >
        <div>
          <h4 class="text-sm font-medium">{{ $t('labels.settings.assetLibrary.ai.title') }}</h4>
          <p class="text-xs text-muted">{{ $t('labels.settings.assetLibrary.ai.description') }}</p>
        </div>

        <div class="space-y-2">
          <div class="flex items-center space-x-2">
            <Switch
              id="ai-auto-on-upload"
              v-model="autoOnUpload"
              :disabled="!canUpdateSpace"
            />
            <Label
              for="ai-auto-on-upload"
              class="text-sm font-medium"
              :label="$t('labels.settings.assetLibrary.ai.autoOnUpload')"
            />
          </div>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.assetLibrary.ai.autoOnUploadHint') }}
          </p>
        </div>

        <div class="space-y-2">
          <div class="flex items-center space-x-2">
            <Switch
              id="ai-suggest-tags"
              v-model="suggestTags"
              :disabled="!canUpdateSpace"
            />
            <Label
              for="ai-suggest-tags"
              class="text-sm font-medium"
              :label="$t('labels.settings.assetLibrary.ai.suggestTags')"
            />
          </div>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.assetLibrary.ai.suggestTagsHint') }}
          </p>
        </div>

        <ComboboxField
          v-model="allowedFields"
          name="ai_allowed_fields"
          :label="$t('labels.settings.assetLibrary.ai.allowedFields')"
          :description="$t('labels.settings.assetLibrary.ai.allowedFieldsHint')"
          :options="allowedFieldOptions"
          :disabled="!canUpdateSpace"
          multiple
        />

        <div
          v-if="canManageAssets"
          class="space-y-2"
        >
          <Button
            variant="outline"
            size="sm"
            @click="classifyAllOpen = true"
          >
            <Icon name="lucide:sparkles" />
            {{ $t('labels.settings.assetLibrary.ai.classifyExisting') }}
          </Button>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.assetLibrary.ai.requiresVision') }}
          </p>
        </div>

        <AssetAiClassifyDialog
          v-model:open="classifyAllOpen"
          :space-id="space.id"
          scope="all"
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
