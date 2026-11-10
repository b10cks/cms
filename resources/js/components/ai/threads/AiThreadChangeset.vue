<script setup lang="ts">
import AiOperationReview from '~/components/ai/threads/AiOperationReview.vue'
import AiThreadEntryCard from '~/components/ai/threads/AiThreadEntryCard.vue'
import { provideItemDecisions } from '~/components/ai/threads/useItemDecisions'
import { Button } from '~/components/ui/button'
import type { AiChangeset, AiChangesetItem, AiDecision } from '~/lib/aiChangesets'
import { attentionFirst, changesLine, changesSummary, groupChangesetItems, isOpenItem, needsIndividualLook } from '~/lib/aiChangesets'
import { itemLocales, SUMMARY_ENTRY_LIMIT } from '~/lib/aiThreads'
import { languageName } from '~/lib/languageNames'

/**
 * A turn's changeset. One line says what happened, and entries that need a
 * person come first: a card per entry, or past five entries one summary card with
 * the entries as collapsed rows, a page at a time. Content is already in its draft.
 * Accept all and Reject all are for the proposals in it, and leave those that need
 * an individual look open. An operation's changeset lists its changes per entry.
 */
const props = defineProps<{
  spaceId: string
  changeset: AiChangeset
}>()

const { locale } = useI18n()
const { rejectMutation } = useAiThreads(() => props.spaceId)
const decisions = provideItemDecisions(() => props.spaceId, () => props.changeset.id)
const { acceptMutation } = decisions
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => props.spaceId)

/** Collapsed rows the summary card shows before "Show more". */
const ROW_PAGE = 50

const operationChangeset = computed(() =>
  props.changeset.operation_id ? { ...props.changeset, operation_id: props.changeset.operation_id } : null
)
const items = computed(() => props.changeset.items ?? [])
const entries = computed(() => attentionFirst(groupChangesetItems(items.value)))
const happened = computed(() => changesSummary(items.value))
const summary = computed(() => entries.value.length > SUMMARY_ENTRY_LIMIT)
const busy = computed(() => acceptMutation.isPending.value || rejectMutation.isPending.value)

const proposals = computed(() => items.value.filter((item) => item.target_type !== 'content' && isOpenItem(item)))
const individualCount = computed(() => proposals.value.filter(needsIndividualLook).length)

const shownRows = ref(ROW_PAGE)
const rows = computed(() => entries.value.slice(0, shownRows.value))

const locales = (item: AiChangesetItem) => {
  const settings = space.value?.settings
  const found = itemLocales(item, settings?.default_language ?? 'en')
  if (!found) return null
  return {
    source: languageName(found.source, settings?.languages, locale.value),
    target: languageName(found.target, settings?.languages, locale.value),
  }
}

/** Applies what needs no individual look, with its edits. */
const acceptAll = async () => {
  const values = decisions.editsOf(proposals.value.filter((item) => !needsIndividualLook(item)))
  try {
    await acceptMutation.mutateAsync({
      changesetId: props.changeset.id,
      decision: { bulk: true, ...(Object.keys(values).length > 0 && { values }) },
      items: items.value,
    })
  } catch {
    // The mutation reports its own error.
  }
}

const reject = (decision: AiDecision) => rejectMutation.mutate({ changesetId: props.changeset.id, decision })
</script>

<template>
  <div class="grid min-w-0 gap-3">
    <p
      v-if="changeset.run?.partial"
      class="rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
    >
      {{ $t('components.aiChangeset.partial', { reason: $t(`components.aiChangeset.stoppedBy.${changeset.run.stopped_by ?? 'other'}`) }) }}
    </p>

    <p
      v-if="!operationChangeset && (happened.changed > 0 || happened.conflicts > 0)"
      class="text-sm text-muted"
      data-changes-summary
    >
      {{ changesLine($t, happened) }}. {{ $t('components.aiThreads.summary.notPublished') }}
    </p>

    <AiOperationReview
      v-if="operationChangeset"
      :space-id="spaceId"
      :changeset="operationChangeset"
      :locales="locales"
    />

    <template v-else-if="!summary">
      <AiThreadEntryCard
        v-for="entry in entries"
        :key="entry.key"
        :space-id="spaceId"
        :entry="entry"
        :busy="busy"
        :locales="locales"
        variant="card"
        @accept="(item, overwrite) => decisions.accept(item, overwrite)"
        @reject="(itemIds) => reject({ item_ids: itemIds })"
      />
    </template>

    <section
      v-else
      class="rounded-xl border border-border bg-card text-card-foreground shadow-soft-sm"
      data-summary
    >
      <header class="grid gap-3 px-4 py-3">
        <div>
          <h3 class="font-semibold text-primary">{{ changeset.title }}</h3>
        </div>
        <div
          v-if="proposals.length > 0"
          class="flex gap-2"
        >
          <Button
            size="sm"
            variant="ai"
            :disabled="busy"
            :loading="acceptMutation.isPending.value"
            @click="acceptAll"
          >
            {{ $t('components.aiChangeset.acceptAll') }}
          </Button>
          <Button
            size="sm"
            variant="ghost"
            :disabled="busy"
            @click="reject({})"
          >
            {{ $t('components.aiChangeset.rejectAll') }}
          </Button>
          <span
            v-if="individualCount > 0"
            class="ml-auto self-center text-sm text-muted tabular-nums"
          >
            {{ $t('components.aiThreads.review.individualTotal', { count: individualCount }) }}
          </span>
        </div>
      </header>
      <div class="divide-y divide-border border-t border-border">
        <AiThreadEntryCard
          v-for="entry in rows"
          :key="entry.key"
            :space-id="spaceId"
          :entry="entry"
          :busy="busy"
          :locales="locales"
          variant="row"
          @accept="(item, overwrite) => decisions.accept(item, overwrite)"
          @reject="(itemIds) => reject({ item_ids: itemIds })"
        />
        <div
          v-if="entries.length > rows.length"
          class="px-4 py-2"
        >
          <Button
            size="sm"
            variant="ghost"
            class="-ml-3"
            @click="shownRows += ROW_PAGE"
          >
            {{ $t('components.aiThreads.review.showMoreEntries', { count: Math.min(ROW_PAGE, entries.length - rows.length) }) }}
          </Button>
        </div>
      </div>
    </section>
  </div>
</template>
