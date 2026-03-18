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
import { FormField, SelectField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'

const { t } = useI18n()
const props = defineProps<{ space: SpaceResource }>()

const NO_FALLBACK_VALUE = '__none__'
type EditableSpaceLanguage = Omit<SpaceLanguage, 'fallback_language'> & {
  fallback_language: string
}
type LanguageOption = {
  value: string
  label: string
  disabled?: boolean
}

const { useUpdateSpaceMutation } = useSpaces()
const { mutateAsync: updateSpace, isPending } = useUpdateSpaceMutation()

const languages = ref<EditableSpaceLanguage[]>(
  deepClone(props.space.settings.languages || []).map((language: SpaceLanguage) => ({
    ...language,
    fallback_language: language.fallback_language || NO_FALLBACK_VALUE,
  }))
)
const defaultLanguage = ref(props.space.settings.default_language || 'en')
const slugStrategy = ref(props.space.settings.slug_strategy || 'never')
const i18nMode = ref(props.space.settings.i18n_mode || 'overlay')
const errors = ref<Record<string, string[]>>({})

const slugStrategyOptions = [
  {
    value: 'never',
    label: t('labels.settings.i18n.slugStrategy.values.never'),
  },
  {
    value: 'prepend_translations',
    label: t('labels.settings.i18n.slugStrategy.values.prependTranslations'),
  },
  {
    value: 'always_prepend',
    label: t('labels.settings.i18n.slugStrategy.values.alwaysPrepend'),
  },
]

const modeOptions = [
  {
    value: 'overlay',
    label: t('labels.settings.i18n.mode.options.overlay'),
  },
  {
    value: 'independent',
    label: t('labels.settings.i18n.mode.options.independent'),
  },
]

const getError = (path: string) => errors.value[path]?.[0]

const fallbackOptions = computed<LanguageOption[]>(() => {
  return languages.value
    .filter((language) => language.code !== '')
    .map((language) => ({
      value: language.code,
      label: language.name || language.code.toUpperCase(),
    }))
})

const columns = computed<ColumnDefinition[]>(() => [
  {
    key: 'code',
    label: t('labels.settings.i18n.code'),
    type: 'text',
    placeholder: t('labels.settings.i18n.codePlaceholder'),
    required: true,
  },
  {
    key: 'name',
    label: t('labels.settings.i18n.label'),
    type: 'text',
    placeholder: t('labels.settings.i18n.labelPlaceholder'),
    required: true,
  },
  {
    key: 'fallback_language',
    label: t('labels.settings.i18n.fallback.label'),
    type: 'select',
    placeholder: t('labels.settings.i18n.fallback.label'),
    options: (item) => [
      {
        value: NO_FALLBACK_VALUE,
        label: t('labels.settings.i18n.fallback.none'),
      },
      {
        value: defaultLanguage.value,
        label: defaultLanguage.value.toUpperCase(),
      },
      ...fallbackOptions.value.filter((option) => option.value !== item.code),
    ],
  },
])

const newItemTemplate: EditableSpaceLanguage = {
  code: '',
  name: '',
  fallback_language: NO_FALLBACK_VALUE,
}

const removeLanguage = (index: number) => {
  languages.value.splice(index, 1)
}

const addLanguage = (item: TableItem) => {
  languages.value.push(item as EditableSpaceLanguage)
}

const languageErrorEntries = computed(() =>
  Object.entries(errors.value).filter(([path]) => path.startsWith('settings.languages.'))
)

const saveSettings = async () => {
  errors.value = {}

  try {
    await updateSpace({
      id: props.space.id,
      payload: {
        settings: {
          ...props.space.settings,
          languages: languages.value.map((language) => ({
            code: language.code,
            name: language.name,
            fallback_language:
              language.fallback_language === NO_FALLBACK_VALUE
                ? null
                : language.fallback_language,
          })),
          default_language: defaultLanguage.value,
          i18n_mode: i18nMode.value,
          slug_strategy: slugStrategy.value,
        },
      },
    })
  } catch (error: any) {
    errors.value = error?.data?.errors || {}
  }
}
</script>

<template>
  <Card variant="none">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.i18n.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.i18n.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <FormField
        name="defaultLanguage"
        :label="$t('labels.settings.i18n.defaultLanguage')"
        :description="$t('labels.settings.i18n.defaultLanguageDescription')"
      >
        <Input
          v-model="defaultLanguage"
          :placeholder="$t('labels.settings.i18n.defaultLanguagePlaceholder')"
        />
        <p
          v-if="getError('settings.default_language')"
          class="mt-2 text-sm text-danger"
        >
          {{ getError('settings.default_language') }}
        </p>
      </FormField>
      <SelectField
        v-model="i18nMode"
        name="i18nMode"
        :label="$t('labels.settings.i18n.mode.label')"
        :description="$t('labels.settings.i18n.mode.description')"
        :options="modeOptions"
      />
      <SelectField
        v-model="slugStrategy"
        name="slugStrategy"
        :label="$t('labels.settings.i18n.slugStrategy.label')"
        :placeholder="$t('labels.settings.i18n.slugStrategy.placeholder')"
        :description="$t('labels.settings.i18n.slugStrategy.description')"
        :options="slugStrategyOptions"
      />
      <div class="space-y-4">
        <div>
          <h4 class="text-sm font-medium">{{ $t('labels.settings.i18n.languages') }}</h4>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.i18n.languagesDescription') }}
          </p>
        </div>
        <SettingsTable
          v-model:items="languages"
          :columns="columns"
          :new-item-template="newItemTemplate"
          @add="addLanguage"
          @remove="removeLanguage"
          @update:items="(items) => (languages = items as EditableSpaceLanguage[])"
        />
        <div
          v-if="languageErrorEntries.length"
          class="space-y-1"
        >
          <p
            v-for="[path, messages] in languageErrorEntries"
            :key="path"
            class="text-sm text-danger"
          >
            {{ messages[0] }}
          </p>
        </div>
        <p
          v-if="getError('settings.languages')"
          class="text-sm text-danger"
        >
          {{ getError('settings.languages') }}
        </p>
      </div>
    </CardContent>
    <CardFooter>
      <Button
        variant="primary"
        :disabled="isPending"
        @click="saveSettings"
        >{{ $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
