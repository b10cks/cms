export interface AssetRequirementIssue {
  fieldKey: string
  fieldLabel: string
  languageCode: string
  languageLabel: string
}

type FolderFieldOverride = {
  key: string
  enabled?: boolean | null
  required?: boolean | null
}

type FolderSettings = {
  field_overrides?: FolderFieldOverride[]
  additional_fields?: SpaceAssetField[]
}

type FolderFieldState = SpaceAssetField & {
  enabled: boolean
  custom?: boolean
  inherited?: boolean
  source?: 'space' | 'folder'
}

type FolderResource = {
  id: string
  parent_id: string | null
  settings?: FolderSettings
}

type AssetFieldsContainer = Record<string, Record<string, unknown>>
type AssetLikeRecord = {
  data?: unknown
  folder_id?: string | null
  effective_asset_fields?: SpaceAssetField[] | null
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
  const { useAssetFoldersQuery } = useAssetFolders(spaceId)
  const { data: space } = useSpaceQuery(toValue(spaceId))
  const { data: folders } = useAssetFoldersQuery()

  const assetFields = computed(() => space.value?.settings?.asset_fields || [])
  const spaceLanguages = computed(() => space.value?.settings?.languages || [])
  const folderMap = computed(() => {
    return new Map((folders.value || []).map((folder) => [folder.id, folder]))
  })
  const languageTabs = computed(() => {
    return [
      {
        code: DEFAULT_LANGUAGE_CODE,
        name: String(t('labels.assets.defaultLanguage')),
      },
      ...spaceLanguages.value,
    ]
  })

  const getFolderLineage = (folderId?: string | null): FolderResource[] => {
    if (!folderId) {
      return []
    }

    const lineage: FolderResource[] = []
    let currentFolder = folderMap.value.get(folderId) || null

    while (currentFolder) {
      lineage.unshift(currentFolder)

      if (!currentFolder.parent_id) {
        break
      }

      currentFolder = folderMap.value.get(currentFolder.parent_id) || null
    }

    return lineage
  }

  const normalizeField = (field: SpaceAssetField): SpaceAssetField => ({
    key: field.key,
    label: field.label,
    required: !!field.required,
  })

  const applyFolderSettings = (
    map: Map<string, FolderFieldState>,
    settings?: FolderSettings | null,
    markInherited = true
  ) => {
    for (const field of settings?.additional_fields || []) {
      map.set(field.key, {
        ...normalizeField(field),
        enabled: true,
        custom: true,
        inherited: markInherited,
        source: 'folder',
      })
    }

    for (const override of settings?.field_overrides || []) {
      const current = map.get(override.key)
      if (!current) {
        continue
      }

      map.set(override.key, {
        ...current,
        enabled: typeof override.enabled === 'boolean' ? override.enabled : current.enabled,
        required: typeof override.required === 'boolean' ? override.required : current.required,
        inherited: markInherited ? current.inherited : false,
        source: 'folder',
      })
    }
  }

  const getFieldStates = ({
    folderId = null,
    parentFolderId = null,
    settings = null,
  }: {
    folderId?: string | null
    parentFolderId?: string | null
    settings?: FolderSettings | null
  } = {}): FolderFieldState[] => {
    const stateMap = new Map<string, FolderFieldState>()

    for (const field of assetFields.value) {
      stateMap.set(field.key, {
        ...normalizeField(field),
        enabled: true,
        custom: false,
        inherited: false,
        source: 'space',
      })
    }

    const baseFolderId = settings
      ? (parentFolderId ?? (folderId ? folderMap.value.get(folderId)?.parent_id || null : null))
      : (folderId ?? parentFolderId ?? null)

    for (const folder of getFolderLineage(baseFolderId)) {
      applyFolderSettings(stateMap, folder.settings, true)
    }

    if (settings) {
      applyFolderSettings(stateMap, settings, false)
    }

    return Array.from(stateMap.values())
  }

  const getEffectiveFields = ({
    folderId = null,
    parentFolderId = null,
    settings = null,
  }: {
    folderId?: string | null
    parentFolderId?: string | null
    settings?: FolderSettings | null
  } = {}): SpaceAssetField[] => {
    return getFieldStates({ folderId, parentFolderId, settings })
      .filter((field) => field.enabled)
      .map((field) => normalizeField(field))
  }

  const getEffectiveFieldsForTarget = (
    target?: AssetLikeRecord | null,
    folderId?: string | null
  ): SpaceAssetField[] => {
    if (target?.effective_asset_fields?.length) {
      return target.effective_asset_fields.map((field) => normalizeField(field))
    }

    return getEffectiveFields({
      folderId: folderId ?? target?.folder_id ?? null,
    })
  }

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

  const getVisibleFields = (
    selectedFieldKeys?: string[] | null,
    folderId?: string | null
  ): SpaceAssetField[] => {
    const effectiveFields = getEffectiveFields({ folderId: folderId ?? null })

    if (!selectedFieldKeys?.length) {
      return effectiveFields
    }

    return effectiveFields.filter((field) => selectedFieldKeys.includes(field.key))
  }

  const getVisibleLanguages = (
    selectedLanguageCodes?: string[] | null
  ): Array<{ code: string; name: string }> => {
    if (!selectedLanguageCodes?.length) {
      return languageTabs.value
    }

    return languageTabs.value.filter((language) => selectedLanguageCodes.includes(language.code))
  }

  const isFieldRequiredForLanguage = (field: SpaceAssetField, languageCode: string): boolean => {
    return field.required && languageCode === DEFAULT_LANGUAGE_CODE
  }

  const getMissingRequiredFields = (
    target: AssetLikeRecord,
    folderId?: string | null
  ): AssetRequirementIssue[] => {
    const applicableFields = getEffectiveFieldsForTarget(target, folderId).filter((field) =>
      isFieldRequiredForLanguage(field, DEFAULT_LANGUAGE_CODE)
    )

    if (!applicableFields.length) {
      return []
    }

    const defaultLanguage = languageTabs.value.find(
      (language) => language.code === DEFAULT_LANGUAGE_CODE
    )

    return applicableFields.flatMap((field) => {
      const value = getFieldValue(target, field.key, DEFAULT_LANGUAGE_CODE)

      if (value) {
        return []
      }

      return [
        {
          fieldKey: field.key,
          fieldLabel: field.label,
          languageCode: DEFAULT_LANGUAGE_CODE,
          languageLabel: defaultLanguage?.name || DEFAULT_LANGUAGE_CODE,
        },
      ]
    })
  }

  const getRequirementSummary = (target: AssetLikeRecord, folderId?: string | null): string => {
    return getMissingRequiredFields(target, folderId)
      .map((issue) => `${issue.fieldLabel} (${issue.languageLabel})`)
      .join(', ')
  }

  const isCompliant = (target: AssetLikeRecord, folderId?: string | null): boolean => {
    return getMissingRequiredFields(target, folderId).length === 0
  }

  return {
    space,
    assetFields,
    spaceLanguages,
    folders,
    folderMap,
    languageTabs,
    ensureAssetFieldData,
    getFieldValue,
    getFieldStates,
    getEffectiveFields,
    getEffectiveFieldsForTarget,
    setFieldValue,
    getVisibleFields,
    getVisibleLanguages,
    isFieldRequiredForLanguage,
    getMissingRequiredFields,
    getRequirementSummary,
    isCompliant,
  }
}
