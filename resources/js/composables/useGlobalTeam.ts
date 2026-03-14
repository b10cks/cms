import { useStorage } from '@vueuse/core'

import { isClient } from '~/lib/env'
import type { TeamResource } from '~/types/teams'

interface GlobalTeamState {
  selectedTeamId: string | null
  lastSelectedAt: string | null
}

const STORAGE_KEY = 'global-team'

const defaultState: GlobalTeamState = {
  selectedTeamId: null,
  lastSelectedAt: null,
}

export function useGlobalTeam() {
  const { useTeamsQuery, useTeamQuery } = useTeams()

  const state = isClient
    ? useStorage<GlobalTeamState>(STORAGE_KEY, defaultState, localStorage, {
        mergeDefaults: true,
        serializer: {
          read: (value: string) => {
            try {
              const parsed = JSON.parse(value) as Partial<GlobalTeamState>

              return {
                selectedTeamId:
                  typeof parsed?.selectedTeamId === 'string' ? parsed.selectedTeamId : null,
                lastSelectedAt:
                  typeof parsed?.lastSelectedAt === 'string' ? parsed.lastSelectedAt : null,
              }
            } catch {
              return defaultState
            }
          },
          write: (value: GlobalTeamState) => JSON.stringify(value),
        },
      })
    : ref<GlobalTeamState>({ ...defaultState })

  const { data: teams, isLoading: isLoadingTeams, error: teamsError } = useTeamsQuery()

  const availableTeams = computed<TeamResource[]>(() => teams.value?.data ?? [])

  const firstTeam = computed<TeamResource | null>(() => availableTeams.value[0] ?? null)

  const findTeamById = (teamId: string | null | undefined): TeamResource | null => {
    if (!teamId) return null
    return availableTeams.value.find((team) => team.id === teamId) ?? null
  }

  const setSelectedTeamId = (teamId: string | null) => {
    if (state.value.selectedTeamId === teamId) return

    state.value = {
      selectedTeamId: teamId,
      lastSelectedAt: teamId ? new Date().toISOString() : null,
    }
  }

  const ensureValidSelection = () => {
    const currentId = state.value.selectedTeamId
    const currentTeam = findTeamById(currentId)

    if (currentTeam) {
      return currentTeam
    }

    const fallbackTeam = firstTeam.value

    if (fallbackTeam) {
      setSelectedTeamId(fallbackTeam.id)
      return fallbackTeam
    }

    if (currentId !== null) {
      setSelectedTeamId(null)
    }

    return null
  }

  watch(
    [availableTeams, isLoadingTeams],
    ([teams, loading]) => {
      if (loading) return
      if (!teams.length && state.value.selectedTeamId === null) return

      ensureValidSelection()
    },
    { immediate: true }
  )

  const selectedTeamId = computed<string | null>({
    get: () => state.value.selectedTeamId,
    set: (teamId) => {
      if (teamId === null) {
        setSelectedTeamId(null)
        return
      }

      setSelectedTeamId(teamId)
    },
  })

  const selectedTeamQuery = isClient
    ? useTeamQuery(computed(() => selectedTeamId.value ?? ''))
    : { data: ref<TeamResource | null>(null), isLoading: ref(false), error: ref(null) }

  const {
    data: selectedTeamData,
    isLoading: isLoadingSelectedTeam,
    error: selectedTeamError,
  } = selectedTeamQuery

  const selectedTeam = computed<TeamResource | null>(() => {
    const validSelectedTeam = findTeamById(selectedTeamId.value)

    if (!validSelectedTeam) {
      return null
    }

    return selectedTeamData.value?.id === validSelectedTeam.id
      ? selectedTeamData.value
      : validSelectedTeam
  })

  const hasTeams = computed(() => availableTeams.value.length > 0)
  const hasSelectedTeam = computed(() => !!selectedTeam.value)
  const isValidSelection = computed(() => {
    if (!selectedTeamId.value) return !hasTeams.value
    return !!findTeamById(selectedTeamId.value)
  })
  const isLoading = computed(() => isLoadingTeams.value || isLoadingSelectedTeam.value)

  const selectTeam = (team: TeamResource | string | null) => {
    if (team === null) {
      selectedTeamId.value = null
      return
    }

    selectedTeamId.value = typeof team === 'string' ? team : team.id
  }

  const clearSelection = () => {
    setSelectedTeamId(null)
  }

  const autoSelectFirstTeam = () => {
    if (!selectedTeamId.value && firstTeam.value) {
      setSelectedTeamId(firstTeam.value.id)
    }
  }

  const teamOptions = computed(() => {
    return availableTeams.value.map((team) => ({
      label: team.name,
      value: team.id,
      icon: team.icon,
      color: team.color,
      description: team.description,
      type: team.type,
      userCount: team.user_count,
      spacesCount: team.spaces_count,
    }))
  })

  return {
    selectedTeamId,
    selectedTeam: readonly(selectedTeam),
    teams: readonly(teams),
    teamOptions: readonly(teamOptions),

    isLoading: readonly(isLoading),
    isLoadingTeams: readonly(isLoadingTeams),
    isLoadingSelectedTeam: readonly(isLoadingSelectedTeam),

    hasSelectedTeam: readonly(hasSelectedTeam),
    hasTeams: readonly(hasTeams),
    isValidSelection: readonly(isValidSelection),

    teamsError: readonly(teamsError),
    selectedTeamError: readonly(selectedTeamError),

    selectTeam,
    clearSelection,
    autoSelectFirstTeam,
    findTeamById,

    lastSelectedAt: computed(() => state.value.lastSelectedAt),
  }
}
