import type { ComputedRef, MaybeRef } from 'vue'

type OptionChoiceField = Pick<OptionSchema, 'options' | 'source' | 'data_source_id'> | OptionsSchema

export interface ResolvedOptionChoice {
  label: string
  value: string
}

const normalizeChoiceLabel = (label: string | null | undefined, fallback: string) => {
  const normalized = String(label || '').trim()
  return normalized.length > 0 ? normalized : fallback
}

export const normalizeFieldOptionChoices = (
  field: OptionChoiceField | null | undefined
): ResolvedOptionChoice[] => {
  return (field?.options || [])
    .map((option) => ({
      label: normalizeChoiceLabel(option?.name, option?.value || ''),
      value: String(option?.value || '').trim(),
    }))
    .filter((option) => option.value.length > 0)
}

export const useFieldOptionChoices = (
  spaceId: MaybeRef<string>,
  field: MaybeRef<OptionChoiceField | null | undefined>
): {
  choices: ComputedRef<ResolvedOptionChoice[]>
  isLoading: ComputedRef<boolean>
  isEmpty: ComputedRef<boolean>
} => {
  const source = computed(() => (toValue(field)?.source === 'datasource' ? 'datasource' : 'self'))
  const dataSourceId = computed(() =>
    source.value === 'datasource' ? (toValue(field)?.data_source_id ?? null) : null
  )

  const { useDataEntriesQuery } = useDataEntries(
    computed(() => toValue(spaceId)),
    computed(() => dataSourceId.value || '')
  )
  const { data: dataEntriesResponse, isLoading } = useDataEntriesQuery(
    computed(() => ({
      per_page: 1000,
      sort: '+value',
    })),
    computed(() => source.value === 'datasource' && Boolean(dataSourceId.value))
  )

  const choices = computed(() => {
    if (source.value === 'self') {
      return normalizeFieldOptionChoices(toValue(field))
    }

    return (dataEntriesResponse.value?.data || [])
      .filter((entry) => entry.is_active)
      .map((entry) => ({
        label: normalizeChoiceLabel(entry.value, entry.key),
        value: entry.key,
      }))
  })

  const resolvedLoading = computed(
    () => source.value === 'datasource' && Boolean(dataSourceId.value) && isLoading.value
  )
  const isEmpty = computed(() => !resolvedLoading.value && choices.value.length === 0)

  return {
    choices,
    isLoading: resolvedLoading,
    isEmpty,
  }
}
