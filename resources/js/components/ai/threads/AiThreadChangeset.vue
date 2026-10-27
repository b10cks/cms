<script setup lang="ts">
import AiThreadEntryCard from '~/components/ai/threads/AiThreadEntryCard.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import type { AiChangeset, AiChangesetItem, AiDecision } from '~/lib/aiChangesets'
import { groupChangesetItems, isOpenItem } from '~/lib/aiChangesets'
import { itemLocales, SUMMARY_ENTRY_LIMIT } from '~/lib/aiThreads'
import { languageName } from '~/lib/languageNames'

/**
 * A turn's changeset as cards: one per entry, or past five entries one
 * summary card with counts, glossary warnings, Accept all and Reject all, and
 * the entries as collapsed rows.
 */
const props = defineProps<{
  spaceId: string
  changeset: AiChangeset
}>()

const { locale } = useI18n()
const { acceptMutation, rejectMutation } = useAiThreads(() => props.spaceId)
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => props.spaceId)

const items = computed(() => props.changeset.items ?? [])
const entries = computed(() => groupChangesetItems(items.value))
const summary = computed(() => entries.value.length > SUMMARY_ENTRY_LIMIT)
const busy = computed(() => acceptMutation.isPending.value || rejectMutation.isPending.value)

const counts = computed(() => ({
  open: items.value.filter(isOpenItem).length,
  accepted: items.value.filter((item) => item.status === 'accepted').length,
  rejected: items.value.filter((item) => item.status === 'rejected').length,
  findings: items.value.reduce((sum, item) => sum + item.glossary_findings.length, 0),
}))

const locales = (item: AiChangesetItem) => {
  const settings = space.value?.settings
  const found = itemLocales(item, settings?.default_language ?? 'en')
  if (!found) return null
  return {
    source: languageName(found.source, settings?.languages, locale.value),
    target: languageName(found.target, settings?.languages, locale.value),
  }
}

/** Reviewer edits of text proposals; accepting applies them instead of the proposal. */
const edits = ref<Record<string, string | undefined>>({})

const accept = (decision: AiDecision) => {
  const ids = decision.item_ids
  const values = Object.fromEntries(
    Object.entries(edits.value).filter(
      (entry): entry is [string, string] => entry[1] !== undefined && (!ids || ids.includes(entry[0]))
    )
  )
  acceptMutation.mutate(
    {
      changesetId: props.changeset.id,
      decision: Object.keys(values).length > 0 ? { ...decision, values } : decision,
      items: props.changeset.items ?? [],
    },
    {
      onSuccess: () => {
        edits.value = ids ? Object.fromEntries(Object.entries(edits.value).filter(([id]) => !ids.includes(id))) : {}
      },
    }
  )
}

const reject = (decision: AiDecision) => rejectMutation.mutate({ changesetId: props.changeset.id, decision })
</script>

<template>
  <div class="grid gap-3">
    <p
      v-if="changeset.run?.partial"
      class="rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
    >
      {{ $t('components.aiChangeset.partial', { reason: $t(`components.aiChangeset.stoppedBy.${changeset.run.stopped_by ?? 'other'}`) }) }}
    </p>

    <template v-if="!summary">
      <AiThreadEntryCard
        v-for="entry in entries"
        :key="entry.key"
        v-model:edits="edits"
        :space-id="spaceId"
        :entry="entry"
        :busy="busy"
        :locales="locales"
        variant="card"
        @accept="(itemIds, overwrite) => accept({ item_ids: itemIds, overwrite })"
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
          <p class="text-sm text-muted">
            {{ $t('components.aiThreads.summary.counts', { entries: entries.length, open: counts.open, accepted: counts.accepted, rejected: counts.rejected }) }}
          </p>
        </div>
        <p
          v-if="counts.findings > 0"
          class="flex items-center gap-2 text-sm text-warning"
        >
          <Icon name="lucide:triangle-alert" />
          {{ $t('components.aiThreads.summary.findings', { count: counts.findings }) }}
        </p>
        <div
          v-if="counts.open > 0"
          class="flex gap-2"
        >
          <Button
            size="sm"
            variant="ai"
            :disabled="busy"
            :loading="acceptMutation.isPending.value"
            @click="accept({})"
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
        </div>
      </header>
      <div class="divide-y divide-border border-t border-border">
        <AiThreadEntryCard
          v-for="entry in entries"
          :key="entry.key"
          v-model:edits="edits"
          :space-id="spaceId"
          :entry="entry"
          :busy="busy"
          :locales="locales"
          variant="row"
          @accept="(itemIds, overwrite) => accept({ item_ids: itemIds, overwrite })"
          @reject="(itemIds) => reject({ item_ids: itemIds })"
        />
      </div>
    </section>
  </div>
</template>
