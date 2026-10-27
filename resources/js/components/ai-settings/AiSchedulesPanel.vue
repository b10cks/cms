<script setup lang="ts">
import type { AiSchedule, AiSchedulePayload } from '~/api/resources/ai'
import AiScheduleDialog from '~/components/ai-settings/AiScheduleDialog.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Panel } from '~/components/ui/panel'
import { Switch } from '~/components/ui/switch'
import { TooltipIcon } from '~/components/ui/tooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { toastError } from '~/lib/toast-error'

const props = defineProps<{
  spaceId: string
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime, formatRelativeTime, formatCurrency } = useFormat()
const { schedulesQuery, saveMutation, toggleMutation, deleteMutation } = useAiSchedules(() => props.spaceId)

const schedules = computed(() => schedulesQuery.data.value?.data ?? [])
const skills = computed(() => schedulesQuery.data.value?.skills ?? { content: [], data_entry: [], asset: [] })

const dialogOpen = ref(false)
const editing = ref<AiSchedule | null>(null)

const openDialog = (schedule: AiSchedule | null) => {
  editing.value = schedule
  dialogOpen.value = true
}

const onSave = async (payload: AiSchedulePayload) => {
  try {
    await saveMutation.mutateAsync({ id: editing.value?.id ?? null, payload })
    dialogOpen.value = false
  } catch (error) {
    toastError(t, 'components.aiSettings.schedules.saveFailed', error)
  }
}

const remove = async (schedule: AiSchedule) => {
  if (await alert.confirm(t('components.aiSettings.schedules.confirmDelete', { name: schedule.name }))) {
    deleteMutation.mutate(schedule.id)
  }
}

const scheduleMeta = (schedule: AiSchedule) =>
  schedule.creator
    ? `${schedule.skill}, ${t('components.aiSettings.schedules.runsAs', { name: schedule.creator.name })}`
    : schedule.skill

const filterSummary = (schedule: AiSchedule) =>
  Object.entries(schedule.scope.filters)
    .map(([key, value]) => `${t(`components.aiSettings.schedules.filters.${key}`)}: ${value}`)
    .join(', ')
</script>

<template>
  <Panel
    id="ai-schedules"
    :title="$t('components.aiSettings.schedules.title')"
    flush
  >
    <template #actions>
      <TooltipIcon>
        <span class="block max-w-sm">{{ $t('components.aiSettings.schedules.description') }}</span>
      </TooltipIcon>
      <Button
        type="button"
        size="sm"
        variant="outline"
        @click="openDialog(null)"
      >
        <Icon name="lucide:plus" />
        {{ $t('components.aiSettings.schedules.add') }}
      </Button>
    </template>

    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('components.aiSettings.schedules.name') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.schedules.scope') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.schedules.cron') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.schedules.budget') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.schedules.lastRun') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.enabled') }}</TableHead>
          <TableHead class="text-right">{{ $t('components.aiSettings.columns.actions') }}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow
          v-for="schedule in schedules"
          :key="schedule.id"
        >
          <TableCell>
            <div class="font-medium">{{ schedule.name }}</div>
            <div class="text-sm text-muted">{{ scheduleMeta(schedule) }}</div>
            <p
              v-if="schedule.paused_reason"
              class="mt-1 text-sm text-warning"
            >
              {{ $t(`components.aiSettings.schedules.paused.${schedule.paused_reason}`) }}
            </p>
          </TableCell>
          <TableCell class="text-sm">
            <div>{{ $t(`components.aiChangeset.targets.${schedule.scope.type}`) }}</div>
            <div class="text-muted">{{ filterSummary(schedule) }}</div>
          </TableCell>
          <TableCell class="text-sm">
            <div class="font-mono">{{ schedule.cron }}</div>
            <div
              v-if="schedule.next_run_at"
              class="text-muted"
            >
              {{ $t('components.aiSettings.schedules.next', { time: formatDateTime(schedule.next_run_at) }) }}
            </div>
          </TableCell>
          <TableCell class="text-sm">{{ formatCurrency(schedule.max_usd, 'USD') }}</TableCell>
          <TableCell class="text-sm">
            <template v-if="schedule.last_run_at">
              <div>{{ formatRelativeTime(schedule.last_run_at) }}</div>
              <Badge
                v-if="schedule.last_run"
                size="sm"
                :variant="schedule.last_run.state === 'failed' ? 'destructive' : 'secondary'"
              >
                {{ $t(`components.aiInbox.states.${schedule.last_run.state}`) }}
              </Badge>
            </template>
            <span
              v-else
              class="text-muted"
            >
              {{ $t('components.aiSettings.schedules.never') }}
            </span>
          </TableCell>
          <TableCell>
            <Switch
              :model-value="schedule.enabled"
              :disabled="toggleMutation.isPending.value"
              :aria-label="$t('components.aiSettings.columns.enabled')"
              @update:model-value="(enabled: boolean) => toggleMutation.mutate({ id: schedule.id, enabled })"
            />
          </TableCell>
          <TableCell class="text-right">
            <div class="flex justify-end gap-1">
              <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="$t('actions.edit')"
                @click="openDialog(schedule)"
              >
                <Icon name="lucide:pencil" />
              </Button>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="$t('actions.delete')"
                @click="remove(schedule)"
              >
                <Icon name="lucide:trash-2" />
              </Button>
            </div>
          </TableCell>
        </TableRow>
        <TableEmpty
          v-if="!schedulesQuery.isLoading.value && schedules.length === 0"
          :colspan="7"
        >
          {{ $t('components.aiSettings.schedules.empty') }}
        </TableEmpty>
      </TableBody>
    </Table>

    <AiScheduleDialog
      v-model:open="dialogOpen"
      :schedule="editing"
      :skills="skills"
      :saving="saveMutation.isPending.value"
      @save="onSave"
    />
  </Panel>
</template>
