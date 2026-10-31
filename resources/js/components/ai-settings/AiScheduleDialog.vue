<script setup lang="ts">
import type { AiSchedule, AiSchedulePayload, AiSchedules, AiScheduleTargetType } from '~/api/resources/ai'
import { Button } from '~/components/ui/button'
import { CheckboxField, InputField, SelectField } from '~/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  schedule: AiSchedule | null
  skills: AiSchedules['skills']
  saving: boolean
}>()

const emit = defineEmits<{
  save: [payload: AiSchedulePayload]
}>()

const { t } = useI18n()

/** The EntryScope filters each scope type understands. */
const FILTERS = {
  content: ['block', 'language', 'missing_locale', 'query'],
  data_entry: ['data_source', 'missing_dimension', 'query'],
  asset: ['folder_id', 'missing_field', 'query'],
} as const satisfies Record<AiScheduleTargetType, readonly string[]>

const SURFACES = {
  content: 'bulk:content',
  data_entry: 'bulk:data_entries',
  asset: 'bulk:assets',
} as const satisfies Record<AiScheduleTargetType, string>

const emptyForm = () => ({
  name: '',
  type: 'content' as AiScheduleTargetType,
  skill: '',
  filters: {} as Record<string, string>,
  cron: '0 6 * * 1',
  max_usd: '1',
  enabled: true,
})

const form = ref(emptyForm())

watch(open, (isOpen) => {
  if (!isOpen) return
  const schedule = props.schedule
  form.value = schedule
    ? {
        name: schedule.name,
        type: schedule.scope.type,
        skill: schedule.skill,
        filters: { ...schedule.scope.filters },
        cron: schedule.cron,
        max_usd: String(schedule.max_usd),
        enabled: schedule.enabled,
      }
    : emptyForm()
})

const typeOptions = computed(() =>
  (Object.keys(FILTERS) as AiScheduleTargetType[]).map((value) => ({
    value,
    label: t(`components.aiChangeset.targets.${value}`),
  }))
)

const skillOptions = computed(() =>
  (props.skills[form.value.type] ?? []).map((skill) => ({ value: skill.name, label: skill.name }))
)

watch(
  () => form.value.type,
  () => {
    if (!skillOptions.value.some((option) => option.value === form.value.skill)) {
      form.value.skill = skillOptions.value[0]?.value ?? ''
    }
  }
)

const canSave = computed(
  () => form.value.name.trim() !== '' && form.value.skill !== '' && form.value.cron.trim() !== '' && Number(form.value.max_usd) > 0
)

const save = () => {
  const allowed: readonly string[] = FILTERS[form.value.type]
  emit('save', {
    name: form.value.name.trim(),
    skill: form.value.skill,
    scope: {
      type: form.value.type,
      filters: Object.fromEntries(
        Object.entries(form.value.filters).filter(([key, value]) => allowed.includes(key) && value.trim() !== '')
      ),
    },
    cron: form.value.cron.trim(),
    max_usd: Number(form.value.max_usd),
    enabled: form.value.enabled,
  })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-xl!">
      <DialogHeader>
        <DialogTitle>
          {{ schedule ? $t('components.aiSettings.schedules.edit') : $t('components.aiSettings.schedules.add') }}
        </DialogTitle>
      </DialogHeader>
      <div class="space-y-4">
        <InputField
          id="schedule-name"
          name="schedule-name"
          v-model="form.name"
          :label="$t('components.aiSettings.schedules.name')"
          :tooltip="$t('components.aiSettings.schedules.nameHint')"
          required
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <SelectField
            id="schedule-type"
            name="schedule-type"
            v-model="form.type"
            :label="$t('components.aiSettings.schedules.scope')"
            :options="typeOptions"
          />
          <SelectField
            id="schedule-skill"
            name="schedule-skill"
            v-model="form.skill"
            :label="$t('components.aiSettings.schedules.skill')"
            :options="skillOptions"
            :disabled="skillOptions.length === 0"
          />
        </div>
        <p
          v-if="skillOptions.length === 0"
          class="text-sm text-muted"
        >
          {{ $t('components.aiSettings.schedules.noSkills', { surface: SURFACES[form.type] }) }}
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <InputField
            v-for="key in FILTERS[form.type]"
            :id="`schedule-filter-${key}`"
            :key="key"
            :name="`schedule-filter-${key}`"
            :model-value="form.filters[key] ?? ''"
            :label="$t(`components.aiSettings.schedules.filters.${key}`)"
            @update:model-value="(value) => (form.filters[key] = String(value ?? ''))"
          />
        </div>
        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
          <InputField
            id="schedule-cron"
            name="schedule-cron"
            v-model="form.cron"
            :label="$t('components.aiSettings.schedules.cron')"
            :tooltip="$t('components.aiSettings.schedules.cronHint')"
            required
          />
          <InputField
            id="schedule-budget"
            name="schedule-budget"
            v-model="form.max_usd"
            type="number"
            :label="$t('components.aiSettings.schedules.budget')"
            :tooltip="$t('components.aiSettings.schedules.budgetHint')"
            required
          />
        </div>
        <CheckboxField
          id="schedule-enabled"
          name="schedule-enabled"
          :model-value="form.enabled"
          :label="$t('components.aiSettings.columns.enabled')"
          @update:model-value="(value) => (form.enabled = value === true)"
        />
      </div>
      <DialogFooter>
        <Button
          type="button"
          variant="ghost"
          @click="open = false"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          type="button"
          variant="primary"
          :loading="saving"
          :disabled="!canSave"
          @click="save"
        >
          {{ $t('actions.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
