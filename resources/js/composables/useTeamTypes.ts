import { computed } from 'vue'

import { useI18n } from '~/plugins/i18n'

/**
 * Single source of truth for the team `type` enum. Mirrors the backend
 * validation in StoreTeamRequest/UpdateTeamRequest (`in:partner,reseller,affiliate`).
 */
export const TEAM_TYPE_KEYS = ['partner', 'reseller', 'affiliate'] as const

export type TeamType = (typeof TEAM_TYPE_KEYS)[number]

export function useTeamTypes() {
  const { t } = useI18n()

  const teamTypeOptions = computed(() =>
    TEAM_TYPE_KEYS.map((value) => ({
      value,
      label: t(`labels.teams.types.${value}`),
    }))
  )

  const getTeamTypeLabel = (type?: string | null): string =>
    type ? t(`labels.teams.types.${type}`) : ''

  return { teamTypeOptions, getTeamTypeLabel }
}
