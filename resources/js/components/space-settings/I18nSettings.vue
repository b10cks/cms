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
import { FormField, SelectField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import SettingsTable, { type ColumnDefinition } from '~/components/ui/settings-table.vue'

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace } = useUpdateSpaceMutation()
const { $t } = useI18n()
const props = defineProps<{ space: SpaceResource }>()

const languages = ref(deepClone(props.space.settings.languages || []))
const defaultLanguage = ref(props.space.settings.default_language)
const slugStrategy = ref(props.space.settings.slug_strategy || 'never')

const columns: ColumnDefinition[] = [
  {
    key: 'code',
    label: $t('labels.settings.i18n.code'),
    type: 'text',
    placeholder: $t('labels.settings.i18n.codePlaceholder'),
    required: true,
    readonly: true,
  },
  {
    key: 'name',
    label: $t('labels.settings.i18n.label'),
    type: 'text',
    placeholder: $t('labels.settings.i18n.labelPlaceholder'),
    required: true,
  },
]

const newItemTemplate = {
  code: '',
  name: '',
}

const removeLanguage = (index: number) => {
  languages.value.splice(index, 1)
}

const addLanguage = (newLanguage: { code: string; name: string }) => {
  if (languages.value.find((lang) => lang.code === newLanguage.code)) {
    return
  }
  languages.value.push(newLanguage)
}

const slugStrategyOptions = [
  {
    value: 'never',
    label: $t('labels.settings.i18n.slugStrategy.values.never'),
  },
  {
    value: 'prepend_translations',
    label: $t('labels.settings.i18n.slugStrategy.values.prependTranslations'),
  },
  {
    value: 'always_prepend',
    label: $t('labels.settings.i18n.slugStrategy.values.alwaysPrepend'),
  },
]

const saveSettings = async () => {
  updateSpace({
    id: props.space.id,
    payload: {
      settings: {
        ...props.space.settings,
        languages: languages.value,
        default_language: defaultLanguage.value,
        slug_strategy: slugStrategy.value,
      },
    },
  })
}
</script>

<template>
  <Card variant="outline">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.i18n.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.i18n.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-4">
      <FormField
        name="defaultLanguage"
        :label="$t('labels.settings.i18n.defaultLanguage')"
        :description="$t('labels.settings.i18n.defaultLanguageDescription')"
      >
        <Input
          v-model="defaultLanguage"
          :placeholder="$t('labels.settings.i18n.defaultLanguagePlaceholder')"
        />
      </FormField>
      <SelectField
        v-model="slugStrategy"
        name="slugStrategy"
        :label="$t('labels.settings.i18n.slugStrategy.label')"
        :placeholder="$t('labels.settings.i18n.slugStrategy.placeholder')"
        :description="$t('labels.settings.i18n.slugStrategy.description')"
        :options="slugStrategyOptions"
      />
      <div>
        <h4 class="mb-2 text-sm font-medium">{{ $t('labels.settings.i18n.languages') }}</h4>
        <p class="mb-4 text-xs text-muted">
          {{ $t('labels.settings.i18n.languagesDescription') }}
        </p>
        <SettingsTable
          v-model:items="languages"
          :columns="columns"
          :new-item-template="newItemTemplate"
          :allow-sort="true"
          :empty-message="$t('labels.settings.i18n.noLanguages')"
          :add-button-label="$t('actions.add')"
          :remove-button-label="$t('actions.remove')"
          @add="addLanguage"
          @remove="removeLanguage"
        />
      </div>
    </CardContent>
    <CardFooter>
      <Button
        variant="primary"
        @click="saveSettings"
        >{{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
