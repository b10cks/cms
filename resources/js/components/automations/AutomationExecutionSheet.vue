<script setup lang="ts">
import AutomationActionTypeBadge from '~/components/automation-actions/AutomationActionTypeBadge.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Sheet, SheetContent, SheetHeaderCombined } from '~/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'

const props = withDefaults(
  defineProps<{
    open: boolean
    execution: AutomationExecutionResource | null
    canManage?: boolean
    replayingId?: string | null
  }>(),
  {
    canManage: false,
    replayingId: null,
  }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  replay: [execution: AutomationExecutionResource]
}>()

const { t } = useI18n()
const { formatDateTime, formatDuration } = useFormat()

const executionSource = computed(() => String(props.execution?.context?.source ?? 'unknown'))
const canReplay = computed(() => {
  if (!props.execution || !props.canManage) {
    return false
  }

  return !['queued', 'running'].includes(props.execution.status)
})

const statusVariant = computed(() => {
  switch (props.execution?.status) {
    case 'queued':
      return 'secondary'
    case 'running':
      return 'info'
    case 'completed':
      return 'success'
    case 'failed':
      return 'destructive'
    default:
      return 'surface'
  }
})

const sourceLabel = computed(() => {
  switch (executionSource.value) {
    case 'manual':
    case 'replay':
    case 'trigger':
    case 'schedule':
      return t(`labels.automationExecutions.sources.${executionSource.value}`)
    default:
      return t('labels.automationExecutions.sources.unknown')
  }
})

const formattedDuration = computed(() => {
  const duration = props.execution?.duration

  if (duration === null || duration === undefined) {
    return '—'
  }

  return duration >= 1000 ? formatDuration(duration, 1, 's') : formatDuration(duration)
})

const formattedContext = computed(() => {
  if (!props.execution?.context) {
    return ''
  }

  return JSON.stringify(props.execution.context, null, 2)
})

const formattedResult = computed(() => {
  if (!props.execution?.result) {
    return ''
  }

  return JSON.stringify(props.execution.result, null, 2)
})

const handleReplay = () => {
  if (props.execution && canReplay.value) {
    emit('replay', props.execution)
  }
}
</script>

<template>
  <Sheet
    :open="open"
    @update:open="emit('update:open', $event)"
  >
    <SheetContent class="sm:max-w-3xl">
      <SheetHeaderCombined
        :title="$t('labels.automationExecutions.detailTitle')"
        :description="$t('labels.automationExecutions.detailDescription')"
      />

      <div
        v-if="execution"
        class="space-y-6"
      >
        <div class="rounded-xl border border-border bg-surface/70 p-4">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-2">
              <div class="flex flex-wrap items-center gap-2">
                <p class="font-semibold text-lg">
                  {{ execution.automation?.name || execution.automation_id }}
                </p>
                <Badge
                  :variant="statusVariant"
                  size="sm"
                >
                  {{ $t(`labels.automationExecutions.status.${execution.status}`) }}
                </Badge>
                <Badge
                  variant="surface"
                  size="sm"
                >
                  {{ sourceLabel }}
                </Badge>
              </div>

              <p class="text-muted-foreground text-sm">
                {{
                  execution.automation?.description ||
                  execution.automation?.action?.name ||
                  execution.id
                }}
              </p>

              <div
                v-if="execution.automation?.action"
                class="flex flex-wrap items-center gap-2"
              >
                <AutomationActionTypeBadge :type="execution.automation.action.type" />
                <span class="text-muted-foreground text-sm">
                  {{ execution.automation.action.name }}
                </span>
              </div>
            </div>

            <Button
              v-if="canManage"
              variant="outline"
              :loading="replayingId === execution.id"
              :disabled="!canReplay"
              @click="handleReplay"
            >
              <Icon
                v-if="replayingId !== execution.id"
                name="lucide:rotate-ccw"
              />
              {{ $t('actions.automationExecutions.replay') }}
            </Button>
          </div>
        </div>

        <Tabs
          default-value="overview"
          class="space-y-4"
        >
          <TabsList>
            <TabsTrigger value="overview">
              {{ $t('labels.automationExecutions.tabs.overview') }}
            </TabsTrigger>
            <TabsTrigger value="context">
              {{ $t('labels.automationExecutions.tabs.context') }}
            </TabsTrigger>
            <TabsTrigger value="result">
              {{ $t('labels.automationExecutions.tabs.result') }}
            </TabsTrigger>
          </TabsList>

          <TabsContent
            value="overview"
            class="space-y-4"
          >
            <div class="grid gap-4 md:grid-cols-2">
              <div class="rounded-xl border border-border p-4">
                <p class="text-muted-foreground text-xs uppercase tracking-wide">
                  {{ $t('labels.automationExecutions.fields.automation') }}
                </p>
                <p class="mt-2 font-medium text-sm">
                  {{ execution.automation?.name || execution.automation_id }}
                </p>
              </div>
              <div class="rounded-xl border border-border p-4">
                <p class="text-muted-foreground text-xs uppercase tracking-wide">
                  {{ $t('labels.automationExecutions.fields.action') }}
                </p>
                <p class="mt-2 font-medium text-sm">
                  {{ execution.automation?.action?.name || '—' }}
                </p>
              </div>
              <div class="rounded-xl border border-border p-4">
                <p class="text-muted-foreground text-xs uppercase tracking-wide">
                  {{ $t('labels.automationExecutions.fields.queuedAt') }}
                </p>
                <p class="mt-2 font-medium text-sm">{{ formatDateTime(execution.created_at) }}</p>
              </div>
              <div class="rounded-xl border border-border p-4">
                <p class="text-muted-foreground text-xs uppercase tracking-wide">
                  {{ $t('labels.automationExecutions.fields.startedAt') }}
                </p>
                <p class="mt-2 font-medium text-sm">
                  {{ execution.started_at ? formatDateTime(execution.started_at) : '—' }}
                </p>
              </div>
              <div class="rounded-xl border border-border p-4">
                <p class="text-muted-foreground text-xs uppercase tracking-wide">
                  {{ $t('labels.automationExecutions.fields.completedAt') }}
                </p>
                <p class="mt-2 font-medium text-sm">
                  {{ execution.completed_at ? formatDateTime(execution.completed_at) : '—' }}
                </p>
              </div>
              <div class="rounded-xl border border-border p-4">
                <p class="text-muted-foreground text-xs uppercase tracking-wide">
                  {{ $t('labels.automationExecutions.fields.duration') }}
                </p>
                <p class="mt-2 font-medium text-sm">{{ formattedDuration }}</p>
              </div>
            </div>

            <div
              v-if="execution.error"
              class="rounded-xl border border-destructive/30 bg-destructive/5 p-4"
            >
              <p class="font-medium text-destructive text-sm">
                {{ $t('labels.automationExecutions.fields.error') }}
              </p>
              <p class="mt-2 whitespace-pre-wrap text-sm">{{ execution.error }}</p>
            </div>
          </TabsContent>

          <TabsContent value="context">
            <div
              v-if="formattedContext"
              class="overflow-auto rounded-xl border border-border bg-surface/70 p-4"
            >
              <pre class="font-mono text-sm whitespace-pre-wrap">{{ formattedContext }}</pre>
            </div>
            <div
              v-else
              class="rounded-xl border border-dashed border-border p-4 text-muted-foreground text-sm"
            >
              {{ $t('labels.automationExecutions.emptyContext') }}
            </div>
          </TabsContent>

          <TabsContent value="result">
            <div
              v-if="formattedResult"
              class="overflow-auto rounded-xl border border-border bg-surface/70 p-4"
            >
              <pre class="font-mono text-sm whitespace-pre-wrap">{{ formattedResult }}</pre>
            </div>
            <div
              v-else
              class="rounded-xl border border-dashed border-border p-4 text-muted-foreground text-sm"
            >
              {{ $t('labels.automationExecutions.emptyResult') }}
            </div>
          </TabsContent>
        </Tabs>
      </div>
    </SheetContent>
  </Sheet>
</template>
