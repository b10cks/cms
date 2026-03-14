export interface AssetRequirementIssue {
  fieldKey: string
  fieldLabel: string
  languageCode: string
  languageLabel: string
}

type AssetFieldsContainer = Record<string, Record<string, unknown>>
type AssetLikeRecord = {
  data?: unknown
}

const DEFAULT_LANGUAGE_CODE = '_default'

function normalizeFieldValue(value: unknown): string {
  if (typeof value === 'string') {
    return value.trim()
  }

  if (Array.isArray(value)) {
    return value.join(' ').trim()
  }

  if (value === null || value === undefined) {
    return ''
  }

  return String(value).trim()
}

function isPlainRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function useAssetRequirements(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const { useSpaceQuery } = useSpaces()
  const { data: space } = useSpaceQuery(toValue(spaceId))

  const assetFields = computed(() => space.value?.settings?.asset_fields || [])
  const spaceLanguages = computed(() => space.value?.settings?.languages || [])
  const languageTabs = computed(() => {
    return [
      {
        code: DEFAULT_LANGUAGE_CODE,
        name: String(t('labels.assets.defaultLanguage')),
      },
      ...spaceLanguages.value,
    ]
  })
  const requiredAssetFields = computed(() => assetFields.value.filter((field) => field.required))

  const ensureAssetFieldData = (target: AssetLikeRecord) => {
    if (!isPlainRecord(target.data)) {
      target.data = {}
    }

    const data = target.data as Record<string, unknown>
    const existingFields = isPlainRecord(data.fields) ? data.fields : {}
    data.fields = existingFields
    const fields = existingFields as AssetFieldsContainer

    for (const language of languageTabs.value) {
      const languageCode =
        typeof language.code === 'string' && language.code.length
          ? language.code
          : DEFAULT_LANGUAGE_CODE

      if (!isPlainRecord(fields[languageCode])) {
        fields[languageCode] = {}
      }
    }

    return fields
  }

  const getFieldValue = (
    target: AssetLikeRecord,
    fieldKey: string,
    languageCode: string
  ): string => {
    const fields = ensureAssetFieldData(target)
    return normalizeFieldValue(fields[languageCode]?.[fieldKey])
  }

  const setFieldValue = (
    target: AssetLikeRecord,
    fieldKey: string,
    languageCode: string,
    value: string
  ) => {
    const fields = ensureAssetFieldData(target)
    fields[languageCode][fieldKey] = value
  }

  const getVisibleFields = (selectedFieldKeys?: string[] | null): SpaceAssetField[] => {
    if (!selectedFieldKeys?.length) {
      return assetFields.value
    }

    return assetFields.value.filter((field) => selectedFieldKeys.includes(field.key))
  }

  const getVisibleLanguages = (
    selectedLanguageCodes?: string[] | null
  ): Array<{ code: string; name: string }> => {
    if (!selectedLanguageCodes?.length) {
      return languageTabs.value
    }

    return languageTabs.value.filter((language) => selectedLanguageCodes.includes(language.code))
  }

  const getMissingRequiredFields = (target: AssetLikeRecord): AssetRequirementIssue[] => {
    if (!requiredAssetFields.value.length) {
      return []
    }

    return languageTabs.value.flatMap((language) => {
      return requiredAssetFields.value.flatMap((field) => {
        const value = getFieldValue(target, field.key, language.code)

        if (value) {
          return []
        }

        return [
          {
            fieldKey: field.key,
            fieldLabel: field.label,
            languageCode: language.code,
            languageLabel: language.name,
          },
        ]
      })
    })
  }

  const getRequirementSummary = (target: AssetLikeRecord): string => {
    return getMissingRequiredFields(target)
      .map((issue) => `${issue.fieldLabel} (${issue.languageLabel})`)
      .join(', ')
  }

  const isCompliant = (target: AssetLikeRecord): boolean => {
    return getMissingRequiredFields(target).length === 0
  }

  return {
    space,
    assetFields,
    requiredAssetFields,
    spaceLanguages,
    languageTabs,
    ensureAssetFieldData,
    getFieldValue,
    setFieldValue,
    getVisibleFields,
    getVisibleLanguages,
    getMissingRequiredFields,
    getRequirementSummary,
    isCompliant,
  }
}
