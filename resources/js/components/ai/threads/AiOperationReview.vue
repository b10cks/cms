<script setup lang="ts">
import { toast } from 'vue-sonner'

import AiOperationChanges from '~/components/ai/operations/AiOperationChanges.vue'
import AiReviewGroup from '~/components/ai/threads/AiReviewGroup.vue'
import { provideItemDecisions } from '~/components/ai/threads/useItemDecisions'
import { useReviewI18n } from '~/components/ai/threads/useReviewI18n'
import { Button } from '~/components/ui/button'
import type { AiChangeset, AiChangesetItem, AiReviewGroup as ReviewGroup } from '~/lib/aiChangesets'
import { changesLine } from '~/lib/aiChangesets'

/**
 * What an operation's changeset holds, which can be thousands of changes. Content
 * is already in its drafts: {@see AiOperationChanges} lists it per entry, and only
 * its conflicts take a decision. Proposals for assets, data entries and blocks show
 * as one row per group with examples, every change loaded only on request. Groups
 * are accepted only when selected, and a group accept leaves conflicts, glossary
 * violations, flagged changes, edits and new folders and tags to an individual
 * decision. A large group is decided on the queue: it shows as queued and takes no
 * other decision until the job is done.
 */
const props = defineProps<{
  spaceId: string
  changeset: AiChangeset & { operation_id: string }
  locales: (item: AiChangesetItem) => { source: string; target: string } | null
}>()

const { t } = useI18n()
const { t: reviewT } = useReviewI18n()
const { useOperationReviewQuery, useOperationChangesQuery, acceptGroupsMutation, rejectGroupsMutation, rejectMutation, refreshGroupTargets } = useAiThreads(() => props.spaceId)
// Its own, as the operation panel mounts this review outside a thread's changeset.
const decisions = provideItemDecisions(() => props.spaceId, () => props.changeset.id)

const { data: groups, isPending } = useOperationReviewQuery(() => props.changeset.operation_id)
const { data: changes } = useOperationChangesQuery(() => props.changeset.operation_id, 1, 1)
const summary = computed(() => changes.value?.summary ?? null)
const { t: globalT } = useI18n()
const happened = computed(() => (summary.value ? changesLine(globalT, summary.value) : null))
const openProposals = computed(() => (groups.value ?? []).reduce((sum, group) => sum + group.counts.pending + group.counts.conflict, 0))

/** Keys of the groups the next group decision takes */
const selected = ref<string[]>([])
const selectable = (group: ReviewGroup) => group.bulk > 0 && group.queued === null

/**
 * A queued accept wrote entries the client never listed; refetch them once
 * the job is done. Groups that can no longer be decided leave the selection.
 */
watch(groups, (list, previous) => {
  const done = (previous ?? []).filter(
    (before) => before.queued === 'accept' && list?.find((group) => group.key === before.key)?.queued === null
  )
  if (done.length > 0) refreshGroupTargets(done)
  selected.value = selected.value.filter((key) => list?.some((group) => group.key === key && selectable(group)))
})

const selectedGroups = computed(() => (groups.value ?? []).filter((group) => selected.value.includes(group.key) && selectable(group)))
const selectedCount = computed(() => selectedGroups.value.reduce((sum, group) => sum + group.bulk, 0))
const individualCount = computed(() => (groups.value ?? []).reduce((sum, group) => sum + group.individual, 0))
const busy = computed(
  () =>
    acceptGroupsMutation.isPending.value ||
    rejectGroupsMutation.isPending.value ||
    decisions.acceptMutation.isPending.value ||
    rejectMutation.isPending.value
)

const setSelected = (key: string, value: boolean) => {
  selected.value = value ? [...selected.value, key] : selected.value.filter((entry) => entry !== key)
}

const accept = (item: AiChangesetItem, overwrite: boolean) => void decisions.accept(item, overwrite)

const reject = (item: AiChangesetItem) =>
  rejectMutation.mutate({ changesetId: props.changeset.id, decision: { item_ids: [item.id] } })

/**
 * Edited proposals go first with their edit and reasons, so the group accept
 * that follows cannot apply the unedited proposal over them. An edit a reason
 * is missing for holds no state on the server yet, so the group accept waits
 * for it.
 */
const acceptSelected = async () => {
  const groupsToAccept = selectedGroups.value
  try {
    for (const item of decisions.unsentEdits(groupsToAccept.map((group) => group.key))) {
      const outcome = await decisions.accept(item)
      if (outcome === 'failed') return
      if (outcome === 'open' && decisions.missingReasons(item).length > 0) {
        toast.warning(reviewT('overrides.groupHeld'))
        return
      }
    }
    const result = await acceptGroupsMutation.mutateAsync({ operationId: props.changeset.operation_id, groups: groupsToAccept })
    selected.value = []
    if (result.queued > 0) toast.info(t('components.aiThreads.review.acceptQueued', { count: result.queued }, result.queued))
    const left = result.conflicts + result.failed
    if (left > 0) toast.warning(t('components.aiThreads.review.acceptedWithLeft', { accepted: result.accepted, left }))
  } catch {
    // The mutations report their own errors.
  }
}

const rejectSelected = () =>
  rejectGroupsMutation.mutate(
    { operationId: props.changeset.operation_id, groups: selectedGroups.value },
    {
      onSuccess: (result) => {
        selected.value = []
        if (result.queued > 0) toast.info(t('components.aiThreads.review.rejectQueued', { count: result.queued }, result.queued))
      },
    }
  )
</script>

<template>
  <section
    class="min-w-0 rounded-xl border border-border bg-card text-card-foreground shadow-soft-sm"
    data-review
  >
    <header class="grid gap-1 px-4 py-3">
      <h3 class="font-semibold text-primary">{{ changeset.title }}</h3>
      <p
        v-if="happened"
        class="text-sm text-muted tabular-nums"
        data-changes-summary
      >
        {{ happened }}. {{ $t('components.aiThreads.summary.notPublished') }}
      </p>
    </header>

    <div
      v-if="summary && (summary.changed > 0 || summary.conflicts > 0)"
      class="border-t border-border"
    >
      <AiOperationChanges
        :space-id="spaceId"
        :operation-id="changeset.operation_id"
        :changeset-id="changeset.id"
        :locales="locales"
      />
    </div>

    <p
      v-if="isPending"
      class="border-t border-border px-4 py-3 text-sm text-muted"
    >
      {{ $t('components.aiThreads.review.loading') }}
    </p>
    <template v-else-if="groups && groups.length > 0">
      <p
        class="border-t border-border px-4 py-3 text-sm text-muted tabular-nums"
        data-proposals-summary
      >
        {{ $t('components.aiThreads.review.counts', { open: openProposals }, openProposals) }}
      </p>
      <ul class="divide-y divide-border border-t border-border">
        <AiReviewGroup
          v-for="group in groups"
          :key="group.key"
          :selected="selected.includes(group.key)"
          :space-id="spaceId"
          :operation-id="changeset.operation_id"
          :group="group"
          :busy="busy"
          :locales="locales"
          @update:selected="(value) => setSelected(group.key, value)"
          @accept="accept"
          @reject="reject"
        />
      </ul>
    </template>

    <footer
      v-if="openProposals > 0"
      class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3"
    >
      <Button
        size="sm"
        variant="ai"
        :disabled="busy || selectedCount === 0"
        :loading="acceptGroupsMutation.isPending.value"
        data-action="accept-groups"
        @click="acceptSelected"
      >
        {{ selectedCount > 0 ? $t('components.aiThreads.review.acceptSelected', { count: selectedCount }) : $t('components.aiThreads.review.selectToAccept') }}
      </Button>
      <Button
        size="sm"
        variant="ghost"
        :disabled="busy || selectedGroups.length === 0"
        :loading="rejectGroupsMutation.isPending.value"
        data-action="reject-groups"
        @click="rejectSelected"
      >
        {{ $t('components.aiThreads.review.rejectSelected') }}
      </Button>
      <span
        v-if="individualCount > 0"
        class="ml-auto text-sm text-muted tabular-nums"
      >
        {{ $t('components.aiThreads.review.individualTotal', { count: individualCount }) }}
      </span>
    </footer>
  </section>
</template>
