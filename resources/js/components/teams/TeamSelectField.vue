<script setup lang="ts">
import { computed } from 'vue'

import TeamOptionRow from '~/components/teams/TeamOptionRow.vue'
import { SelectField } from '~/components/ui/form'
import type { SelectOption } from '~/components/ui/form/SelectField.vue'
import { flattenTeamHierarchy, type HierarchicalTeam } from '~/lib/team-hierarchy'

export interface TeamSelectOption extends HierarchicalTeam {
  icon?: string | null
  color?: string | null
  type?: string | null
  /** Shown in the tree for context, but not selectable. */
  disabled?: boolean
}

/** A leading option standing for "no team", modelled as `null`. */
export interface NoTeamOption {
  label: string
  icon?: string
}

const props = defineProps<{
  name: string
  teams: TeamSelectOption[]
  label?: string
  description?: string
  placeholder?: string
  noTeamOption?: NoTeamOption | null
  disabled?: boolean
  error?: string | null
}>()

const model = defineModel<string | null>()

// `null` is a real choice here ("no parent", "system-wide"), but SelectField
// reads null as "cleared". Carrying it as a sentinel keeps the choice apart
// from an empty selection, so picking it emits null instead of undefined.
const NO_TEAM_VALUE = '__none__'

const entries = computed(() => flattenTeamHierarchy(props.teams))

const visuals = computed(() => {
  const byValue = new Map<string, { icon?: string | null; color?: string | null; type?: string | null; depth: number }>()

  if (props.noTeamOption) {
    byValue.set(NO_TEAM_VALUE, { icon: props.noTeamOption.icon ?? 'circle-slash', depth: 0 })
  }

  for (const { team, depth } of entries.value) {
    byValue.set(team.id, { icon: team.icon, color: team.color, type: team.type, depth })
  }

  return byValue
})

const options = computed<SelectOption<string>[]>(() => [
  ...(props.noTeamOption ? [{ value: NO_TEAM_VALUE, label: props.noTeamOption.label }] : []),
  ...entries.value.map(({ team }) => ({
    value: team.id,
    label: team.name,
    disabled: team.disabled,
  })),
])

const selected = computed<string | undefined>({
  get: () => model.value ?? (props.noTeamOption ? NO_TEAM_VALUE : undefined),
  set: (value) => {
    model.value = !value || value === NO_TEAM_VALUE ? null : value
  },
})

const visualFor = (value: string) => visuals.value.get(value)
</script>

<template>
  <SelectField
    v-model="selected"
    :name="name"
    :label="label"
    :description="description"
    :placeholder="placeholder"
    :options="options"
    :disabled="disabled"
    :error="error"
  >
    <template #value="{ option }">
      <TeamOptionRow
        v-if="option"
        compact
        :name="option.label"
        :icon="visualFor(option.value)?.icon"
        :color="visualFor(option.value)?.color"
        :type="visualFor(option.value)?.type"
      />
    </template>

    <template #item="{ option }">
      <TeamOptionRow
        :name="option.label"
        :icon="visualFor(option.value)?.icon"
        :color="visualFor(option.value)?.color"
        :type="visualFor(option.value)?.type"
        :depth="visualFor(option.value)?.depth ?? 0"
      />
    </template>
  </SelectField>
</template>
