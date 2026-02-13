import { useStorage } from '@vueuse/core'

import type { TeamResource } from '~/types/teams'

import { isClient } from '~/lib/env'

interface GlobalTeamState {
  selectedTeamId: string | null
  lastSelectedAt: string | null
}

const STORAGE_KEY = 'global-team'

export function useGlobalTeam() {
  const { useTeamsQuery, useTeamQuery } = useTeams()

  // Default state
  const defaultState: GlobalTeamState = {
    selectedTeamId: null,
    lastSelectedAt: null,
  }

  // Persistent state - only use localStorage on client-side
  const state = isClient
    ? useStorage<GlobalTeamState>(STORAGE_KEY, defaultState, localStorage, {
        mergeDefaults: true,
        serializer: {
          read: (value: string) => {
            try {
              return JSON.parse(value)
            } catch {
              return defaultState
            }
          },
          write: (value: GlobalTeamState) => JSON.stringify(value),
        },
      })
    : ref<GlobalTeamState>(defaultState)

  // Load all teams for the selector
  const { data: teams, isLoading: isLoadingTeams, error: teamsError } = useTeamsQuery()

  // Load currently selected team details - only on client side
  const selectedTeamQuery = isClient
    ? useTeamQuery(computed(() => state.value.selectedTeamId || ''))
    : { data: ref(null), isLoading: ref(false), error: ref(null) }

  const {
    data: selectedTeam,
    isLoading: isLoadingSelectedTeam,
    error: selectedTeamError,
  } = selectedTeamQuery

  // Computed properties
  const selectedTeamId = computed({
    get: () => state.value.selectedTeamId,
    set: (teamId: string | null) => {
      state.value = {
        selectedTeamId: teamId,
        lastSelectedAt: teamId ? new Date().toISOString() : null,
      }
    },
  })

  const isLoading = computed(() => isLoadingTeams.value || isLoadingSelectedTeam.value)
  const hasSelectedTeam = computed(() => !!selectedTeamId.value && !!selectedTeam.value)
  const hasTeams = computed(() => teams.value && teams.value.length > 0)

  // Actions
  const selectTeam = (team: TeamResource | string | null) => {
    if (team === null) {
      selectedTeamId.value = null
      return
    }

    const teamId = typeof team === 'string' ? team : team.id
    selectedTeamId.value = teamId
  }

  const clearSelection = () => {
    selectedTeamId.value = null
  }

  // Auto-select first team if none selected and teams are available
  const autoSelectFirstTeam = () => {
    if (!selectedTeamId.value && hasTeams.value && teams.value) {
      selectTeam(teams.value[0])
    }
  }

  // Find team by ID in the teams list
  const findTeamById = (teamId: string): TeamResource | undefined => {
    return teams.value?.find((team) => team.id === teamId)
  }

  // Get team options for select component
  const teamOptions = computed(() => {
    if (!teams.value) return []

    return teams.value.map((team) => ({
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

  // Validation
  const isValidSelection = computed(() => {
    if (!selectedTeamId.value) return true // null is valid (no selection)
    return !!findTeamById(selectedTeamId.value)
  })

  // Clean up invalid selection
  watch(
    [teams, selectedTeamId],
    ([newTeams, newSelectedId]) => {
      if (newSelectedId && newTeams && !findTeamById(newSelectedId)) {
        clearSelection()
      }
    },
    { immediate: true }
  )

  // Auto-select logic when teams load
  watch(
    teams,
    (newTeams) => {
      if (newTeams && newTeams.length > 0 && !selectedTeamId.value) {
        // Optionally auto-select first team
        // autoSelectFirstTeam()
      }
    },
    { immediate: true }
  )

  return {
    // State
    selectedTeamId: readonly(selectedTeamId),
    selectedTeam: readonly(selectedTeam),
    teams: readonly(teams),
    teamOptions: readonly(teamOptions),

    // Loading states
    isLoading: readonly(isLoading),
    isLoadingTeams: readonly(isLoadingTeams),
    isLoadingSelectedTeam: readonly(isLoadingSelectedTeam),

    // Computed states
    hasSelectedTeam: readonly(hasSelectedTeam),
    hasTeams: readonly(hasTeams),
    isValidSelection: readonly(isValidSelection),

    // Errors
    teamsError: readonly(teamsError),
    selectedTeamError: readonly(selectedTeamError),

    // Actions
    selectTeam,
    clearSelection,
    autoSelectFirstTeam,
    findTeamById,

    // Utils
    lastSelectedAt: computed(() => state.value.lastSelectedAt),
  }
}
