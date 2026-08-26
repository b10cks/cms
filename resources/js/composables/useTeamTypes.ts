import { computed } from 'vue'

import { useI18n } from '~/plugins/i18n'

/**
 * Every value the team `type` column may hold. Mirrors Team::TYPES.
 */
export const TEAM_TYPE_KEYS = ['personal', 'partner', 'reseller', 'affiliate'] as const

/**
 * The types a root user may pick. `personal` is stamped on the team a new user
 * gets, so it is labelled and filterable but never offered in the picker.
 */
export const ASSIGNABLE_TEAM_TYPE_KEYS = ['partner', 'reseller', 'affiliate'] as const

export type TeamType = (typeof TEAM_TYPE_KEYS)[number]

export function useTeamTypes() {
  const { t } = useI18n()

  const optionsFor = (keys: readonly string[]) =>
    keys.map((value) => ({
      value,
      label: t(`labels.teams.types.${value}`),
    }))

  const teamTypeOptions = computed(() => optionsFor(TEAM_TYPE_KEYS))

  const assignableTeamTypeOptions = computed(() => optionsFor(ASSIGNABLE_TEAM_TYPE_KEYS))

  // An unknown type has no message, and vue-i18n echoes the key back — showing
  // "labels.teams.types.ghost" in the UI. Fall back to the raw value.
  const getTeamTypeLabel = (type?: string | null): string => {
    if (!type) return ''
    return (TEAM_TYPE_KEYS as readonly string[]).includes(type)
      ? t(`labels.teams.types.${type}`)
      : type
  }

  return { teamTypeOptions, assignableTeamTypeOptions, getTeamTypeLabel }
}
