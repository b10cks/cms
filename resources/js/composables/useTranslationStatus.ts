import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { refDebounced } from '@vueuse/core'

import { api } from '~/api'

/** Prefix of every status query in a space. */
export const translationStatusKey = (spaceId: string) =>
  ['spaces', spaceId, 'translation-status'] as const

/**
 * Per-unit translation status of an entry in one locale, read from saved data.
 * Disabled until both the entry and the locale are known. Invalidate
 * `translationStatusKey` after a save.
 */
export function useTranslationStatus(
  spaceId: MaybeRefOrGetter<string>,
  contentId: MaybeRefOrGetter<string | null | undefined>,
  locale: MaybeRefOrGetter<string | null | undefined>
) {
  return useQuery({
    queryKey: computed(() => [
      ...translationStatusKey(toValue(spaceId)),
      toValue(contentId),
      toValue(locale),
    ]),
    queryFn: async () => {
      const id = toValue(contentId)
      const target = toValue(locale)
      if (!id || !target) throw new Error('An entry and a locale are required.')

      const response = await api.forSpace(toValue(spaceId)).contents.translationStatus(id, target)
      return response.data
    },
    enabled: computed(() => !!toValue(contentId) && !!toValue(locale)),
    // A refetch resets which fields count as edited since, so it follows saves only.
    refetchOnWindowFocus: false,
  })
}

/** Memory and other locales for the unit being translated, debounced so moving through fields stays cheap. */
export function useTranslationAssist(
  spaceId: MaybeRefOrGetter<string>,
  contentId: MaybeRefOrGetter<string | null | undefined>,
  locale: MaybeRefOrGetter<string | null | undefined>,
  unit: MaybeRefOrGetter<string | null>
) {
  const debounced = refDebounced(
    computed(() => toValue(unit)),
    400
  )

  return useQuery({
    queryKey: computed(() => [
      ...translationStatusKey(toValue(spaceId)),
      'assist',
      toValue(contentId),
      toValue(locale),
      debounced.value,
    ]),
    queryFn: async () => {
      const id = toValue(contentId)
      const targetLocale = toValue(locale)
      const unitId = debounced.value
      if (!id || !targetLocale || !unitId) throw new Error('An entry, a locale and a unit are required.')

      const response = await api
        .forSpace(toValue(spaceId))
        .contents.translationAssist(id, { locale: targetLocale, unit: unitId })
      return response.data
    },
    enabled: computed(() => !!toValue(contentId) && !!toValue(locale) && !!debounced.value),
    placeholderData: keepPreviousData,
    refetchOnWindowFocus: false,
    retry: false,
  })
}
