<script setup lang="ts">
import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'
import AiSuggestionActions from '~/components/ai/AiSuggestionActions.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Panel } from '~/components/ui/panel'
import { Skeleton } from '~/components/ui/skeleton'
import type { AiInboxFilter, AiInboxItem } from '~/lib/aiInbox'
import { AI_INBOX_FILTERS, filterAiInboxItems } from '~/lib/aiInbox'
import type { AiRunRecord } from '~/lib/aiRun'
import { aiSkillLabel } from '~/lib/aiSkills'

/** Everything waiting in the inbox, one row each, with filter chips by kind. */
const props = defineProps<{
  spaceId: string
  items: readonly AiInboxItem[]
  glossaries: readonly AiGlossaryResource[]
  loading?: boolean
  busy?: boolean
}>()

const emit = defineEmits<{
  acceptSuggestion: [suggestionId: string, glossaryId: string]
  dismissSuggestion: [suggestionId: string]
  answer: [run: AiRunRecord]
  cancel: [runId: string]
}>()

const { t } = useI18n()
const { formatRelativeTime } = useFormat()

const filter = ref<AiInboxFilter>('all')
const visible = computed(() => filterAiInboxItems(props.items, filter.value))
const counts = computed(() =>
  Object.fromEntries(AI_INBOX_FILTERS.map((key) => [key, filterAiInboxItems(props.items, key).length]))
)

const ICONS = {
  changeset: 'lucide:git-pull-request-arrow',
  suggestion: 'lucide:book-a',
  questions: 'lucide:message-circle-question',
  failed: 'lucide:circle-alert',
  running: 'lucide:loader',
} as const

const iconFor = (item: AiInboxItem) =>
  item.kind === 'run' ? ICONS[item.filter ?? 'running'] : ICONS[item.kind]

const runTitle = (run: AiRunRecord) =>
  run.skill
    ? aiSkillLabel(run.skill, t)
    : t(`components.aiInbox.surfaces.${run.surface.startsWith('bulk:') ? 'bulk' : 'agent'}`)

const title = (item: AiInboxItem) => {
  if (item.kind === 'changeset') return item.changeset.title
  if (item.kind === 'suggestion') return item.suggestion.term
  return runTitle(item.run)
}

const scope = (item: AiInboxItem): string => {
  if (item.kind === 'changeset') {
    const { entry_count: entries, item_count: fields } = item.changeset
    return entries === undefined
      ? t('components.aiInbox.scope.fields', { count: fields })
      : t('components.aiInbox.scope.entriesFields', {
          entries: t('components.aiInbox.scope.entries', { count: entries }),
          fields: t('components.aiInbox.scope.fields', { count: fields }),
        })
  }
  if (item.kind === 'suggestion') {
    const { translations, forbidden } = item.suggestion
    const parts = Object.entries(translations).map(([locale, value]) => `${locale.toUpperCase()} ${value}`)
    if (forbidden.length > 0) parts.push(t('components.aiSettings.glossaries.avoid', { terms: forbidden.join(', ') }))
    return parts.join(', ')
  }
  if (item.run.state === 'failed') return item.run.error ?? ''
  return item.run.questionnaire?.questions[0]?.title ?? ''
}

const byline = (item: AiInboxItem) => {
  const who =
    item.kind === 'changeset'
      ? item.changeset.actor?.name
      : item.kind === 'suggestion'
        ? item.suggestion.actor?.name
        : t('components.aiInbox.you')
  const when = item.at ? formatRelativeTime(item.at) : null
  return [who, when].filter(Boolean).join(', ')
}

const status = (item: AiInboxItem) => {
  if (item.kind === 'changeset') {
    return item.changeset.run?.partial
      ? { label: t('components.aiInbox.partial'), variant: 'warning' as const }
      : { label: t('components.aiInbox.toReview', { count: item.changeset.pending_count }), variant: 'info' as const }
  }
  if (item.kind === 'suggestion') return { label: t('components.aiInbox.suggestion'), variant: 'secondary' as const }

  const variant = item.run.state === 'failed' ? 'destructive' : item.run.state === 'awaiting_input' ? 'warning' : 'info'
  return { label: t(`components.aiInbox.states.${item.run.state}`), variant: variant as 'destructive' | 'warning' | 'info' }
}
</script>

<template>
  <div class="grid gap-4">
    <div
      role="group"
      class="flex flex-wrap gap-1"
      :aria-label="$t('components.aiInbox.filterLabel')"
    >
      <Button
        v-for="key in AI_INBOX_FILTERS"
        :key="key"
        type="button"
        size="sm"
        :variant="filter === key ? 'default' : 'ghost'"
        :aria-pressed="filter === key"
        :data-filter="key"
        @click="filter = key"
      >
        {{ $t(`components.aiInbox.filters.${key}`) }}
        <span class="text-muted tabular-nums">{{ counts[key] }}</span>
      </Button>
    </div>

    <Panel flush>
      <ul
        v-if="loading"
        class="divide-y divide-border"
        aria-hidden="true"
      >
        <li
          v-for="index in 4"
          :key="index"
          class="flex items-center gap-4 px-5 py-4"
        >
          <Skeleton class="size-5 rounded-full" />
          <Skeleton class="h-4 w-1/3" />
          <Skeleton class="ml-auto h-4 w-24" />
        </li>
      </ul>
      <p
        v-else-if="visible.length === 0"
        class="px-5 py-10 text-center text-sm text-muted"
      >
        {{ $t('components.aiInbox.empty') }}
        <RouterLink
          :to="{ name: 'space-settings-ai-skills', params: { space: spaceId } }"
          class="font-medium text-primary underline-offset-4 hover:underline"
        >
          {{ $t('components.aiInbox.emptyLink') }}
        </RouterLink>
      </p>
      <ul
        v-else
        class="divide-y divide-border"
      >
        <li
          v-for="item in visible"
          :key="`${item.kind}:${item.id}`"
          class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3"
          :data-kind="item.filter ?? 'running'"
        >
          <Icon
            :name="iconFor(item)"
            class="size-5 shrink-0 text-muted"
          />
          <div class="min-w-0 flex-[1_1_16rem]">
            <RouterLink
              v-if="item.kind === 'changeset'"
              :to="{ name: 'space-ai-inbox-changeset', params: { space: spaceId, changeset: item.id } }"
              class="block truncate rounded-sm font-medium hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
              {{ title(item) }}
            </RouterLink>
            <span
              v-else
              class="block truncate font-medium"
            >
              {{ title(item) }}
            </span>
            <span
              v-if="scope(item)"
              class="block truncate text-sm text-muted"
              :title="scope(item)"
            >
              {{ scope(item) }}
            </span>
          </div>
          <span class="text-sm text-muted">{{ byline(item) }}</span>
          <Badge :variant="status(item).variant">{{ status(item).label }}</Badge>
          <div class="ml-auto flex items-center gap-2">
            <AiSuggestionActions
              v-if="item.kind === 'suggestion'"
              :space-id="spaceId"
              :term="item.suggestion.term"
              :glossaries="glossaries"
              :disabled="busy"
              @accept="(glossaryId) => emit('acceptSuggestion', item.id, glossaryId)"
              @dismiss="emit('dismissSuggestion', item.id)"
            />
            <template v-else-if="item.kind === 'run'">
              <Button
                v-if="item.run.state === 'awaiting_input' && item.run.questionnaire"
                size="sm"
                variant="outline"
                :disabled="busy"
                @click="emit('answer', item.run)"
              >
                {{ $t('components.aiInbox.answer') }}
              </Button>
              <Button
                v-if="item.run.state === 'running' || item.run.state === 'awaiting_input'"
                size="sm"
                variant="ghost"
                :disabled="busy"
                @click="emit('cancel', item.run.id)"
              >
                {{ $t('components.aiInbox.cancel') }}
              </Button>
            </template>
          </div>
        </li>
      </ul>
    </Panel>
  </div>
</template>
