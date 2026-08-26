<script setup lang="ts">
import { computed } from 'vue'

import { SelectField } from '~/components/ui/form'
import type { SelectOption } from '~/components/ui/form/SelectField.vue'
import { useTeamTypes } from '~/composables/useTeamTypes'

const props = withDefaults(
  defineProps<{
    name: string
    /** The type classifies a team commercially, so only root may change it. */
    editable?: boolean
  }>(),
  {
    editable: false,
  }
)

const model = defineModel<string | null>()

const { t } = useI18n()
const { assignableTeamTypeOptions, getTeamTypeLabel } = useTeamTypes()

// "No type" is a real choice, but SelectField reads null as "cleared". Carrying
// it as a sentinel keeps the choice apart from an empty selection, so picking it
// emits null instead of undefined.
const NO_TYPE_VALUE = '__none__'

// A team can already carry a type outside the picker — `personal`, or a legacy
// value. Keep it in the list so the field shows it and a save round-trips it
// instead of silently clearing it.
const options = computed<SelectOption<string>[]>(() => {
  const assignable = assignableTeamTypeOptions.value
  const current = model.value

  return [
    { value: NO_TYPE_VALUE, label: t('labels.teams.noType') as string },
    ...assignable,
    ...(current && !assignable.some((option) => option.value === current)
      ? [{ value: current, label: getTeamTypeLabel(current) }]
      : []),
  ]
})

const selected = computed<string>({
  get: () => model.value ?? NO_TYPE_VALUE,
  set: (value) => {
    model.value = !value || value === NO_TYPE_VALUE ? null : value
  },
})
</script>

<template>
  <SelectField
    v-model="selected"
    :name="name"
    :label="$t('labels.teams.fields.type')"
    :placeholder="$t('labels.teams.fields.typePlaceholder')"
    :description="props.editable ? undefined : $t('labels.teams.fields.typeRootOnly')"
    :options="options"
    :readonly="!props.editable"
  />
</template>
