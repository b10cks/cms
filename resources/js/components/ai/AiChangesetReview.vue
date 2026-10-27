<script setup lang="ts">
import { resolveDiffComponent } from '~/components/content/diff/field-diff'
import ValueRenderer from '~/components/content/ValueRenderer.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Panel } from '~/components/ui/panel'
import { Spinner } from '~/components/ui/spinner'
import { Textarea } from '~/components/ui/textarea'
import type { AiChangesetItem, AiDecision } from '~/lib/aiChangesets'
import { diffFieldType, groupChangesetItems, isOpenItem } from '~/lib/aiChangesets'

const props = defineProps<{
  spaceId: string
  changesetId: string
}>()

const { t } = useI18n()
const { useChangesetQuery, acceptMutation, rejectMutation } = useAiInbox(() => props.spaceId)
const { data: changeset, isLoading } = useChangesetQuery(() => props.changesetId)

const entries = computed(() => groupChangesetItems(changeset.value?.items ?? []))
const busy = computed(() => acceptMutation.isPending.value || rejectMutation.isPending.value)

const meta = computed(() => {
  const current = changeset.value
  if (!current) return undefined
  return t('components.aiChangeset.meta', { pending: current.pending_count, total: current.item_count })
})

const openIds = (items: readonly AiChangesetItem[]) => items.filter(isOpenItem).map((item) => item.id)

/** Reviewer edits of text proposals; accepting applies them instead of the proposal. */
const edits = ref<Record<string, string>>({})

const isEditable = (item: AiChangesetItem) =>
  item.status === 'pending' && item.operation === 'update' && item.target_type !== 'selection' && typeof item.after === 'string'

const startEdit = (item: AiChangesetItem) => {
  if (typeof item.after === 'string') edits.value = { ...edits.value, [item.id]: item.after }
}

const dropEdits = (ids?: readonly string[]) => {
  edits.value = ids ? Object.fromEntries(Object.entries(edits.value).filter(([id]) => !ids.includes(id))) : {}
}

const accept = (decision: AiDecision) => {
  const ids = decision.item_ids
  const values = Object.fromEntries(Object.entries(edits.value).filter(([id]) => !ids || ids.includes(id)))
  acceptMutation.mutate(
    {
      changesetId: props.changesetId,
      decision: Object.keys(values).length > 0 ? { ...decision, values } : decision,
    },
    { onSuccess: () => dropEdits(ids) }
  )
}
const reject = (decision: AiDecision) =>
  rejectMutation.mutate({ changesetId: props.changesetId, decision })

const formatPath = (item: AiChangesetItem) => {
  const segments = (item.path ?? '').split('.')
  // Content paths alternate field keys and block IDs; the IDs mean nothing to a reader.
  const shown = item.target_type === 'content' ? segments.filter((_, index) => index % 2 === 0) : segments
  return shown.join(' → ')
}

const diffFor = (item: AiChangesetItem) =>
  resolveDiffComponent({
    type: item.before === null || item.before === undefined ? 'added' : 'changed',
    path: item.path ?? '',
    oldValue: item.before,
    newValue: item.final ?? item.after,
    fieldType: diffFieldType(item),
  })

const createdContent = (item: AiChangesetItem) =>
  typeof item.after === 'object' && item.after !== null && 'content' in item.after
    ? (item.after as { content: unknown }).content
    : item.after

const STATUS_VARIANTS = {
  pending: 'secondary',
  accepted: 'success',
  rejected: 'surface',
  conflict: 'warning',
} as const satisfies Record<AiChangesetItem['status'], string>
</script>

<template>
  <div
    v-if="isLoading"
    class="flex justify-center py-10"
  >
    <Spinner />
  </div>
  <Panel
    v-else-if="changeset"
    :title="changeset.title"
    :meta="meta"
  >
    <template #actions>
      <Button
        size="sm"
        variant="ghost"
        :disabled="busy || changeset.pending_count === 0"
        @click="reject({})"
      >
        {{ $t('components.aiChangeset.rejectAll') }}
      </Button>
      <Button
        size="sm"
        variant="ai"
        :disabled="busy || changeset.pending_count === 0"
        :loading="acceptMutation.isPending.value"
        @click="accept({})"
      >
        {{ $t('components.aiChangeset.acceptAll') }}
      </Button>
    </template>

    <p
      v-if="changeset.run?.partial"
      class="mb-4 rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
    >
      {{ $t('components.aiChangeset.partial', { reason: $t(`components.aiChangeset.stoppedBy.${changeset.run.stopped_by ?? 'other'}`) }) }}
    </p>

    <div class="divide-y divide-border">
      <section
        v-for="entry in entries"
        :key="entry.key"
        class="py-4 first:pt-0 last:pb-0"
      >
        <header class="mb-3 flex items-center gap-2">
          <h3 class="min-w-0 truncate font-semibold">
            <RouterLink
              v-if="entry.targetType === 'content' && entry.targetId"
              :to="`/${spaceId}/content/${entry.targetId}`"
              class="hover:underline"
            >
              {{ entry.label }}
            </RouterLink>
            <span v-else>{{ entry.label }}</span>
          </h3>
          <span class="text-sm text-muted">{{ $t(`components.aiChangeset.targets.${entry.targetType}`) }}</span>
          <div
            v-if="openIds(entry.items).length > 0 && entries.length > 1"
            class="ml-auto flex gap-2"
          >
            <Button
              size="xs"
              variant="ghost"
              :disabled="busy"
              @click="reject({ item_ids: openIds(entry.items) })"
            >
              {{ $t('components.aiChangeset.rejectEntry') }}
            </Button>
            <Button
              size="xs"
              variant="outline"
              :disabled="busy"
              @click="accept({ item_ids: openIds(entry.items) })"
            >
              {{ $t('components.aiChangeset.acceptEntry') }}
            </Button>
          </div>
        </header>

        <ul class="space-y-3">
          <li
            v-for="item in entry.items"
            :key="item.id"
            class="rounded-lg border border-border p-3"
          >
            <div class="mb-2 flex items-center gap-2">
              <span class="min-w-0 truncate text-sm font-medium">
                {{ item.operation === 'create' ? $t('components.aiChangeset.newEntry') : formatPath(item) }}
              </span>
              <Badge
                :variant="STATUS_VARIANTS[item.status]"
                size="sm"
              >
                {{ $t(`components.aiChangeset.status.${item.status}`) }}
              </Badge>
              <div
                v-if="isOpenItem(item)"
                class="ml-auto flex gap-2"
              >
                <Button
                  v-if="isEditable(item) && edits[item.id] === undefined"
                  size="xs"
                  variant="ghost"
                  :disabled="busy"
                  @click="startEdit(item)"
                >
                  <Icon name="lucide:pencil" />
                  {{ $t('components.aiChangeset.edit') }}
                </Button>
                <Button
                  v-else-if="edits[item.id] !== undefined"
                  size="xs"
                  variant="ghost"
                  :disabled="busy"
                  @click="dropEdits([item.id])"
                >
                  {{ $t('components.aiChangeset.discardEdit') }}
                </Button>
                <Button
                  size="xs"
                  variant="ghost"
                  :disabled="busy"
                  @click="reject({ item_ids: [item.id] })"
                >
                  {{ item.status === 'conflict' ? $t('components.aiChangeset.keepCurrent') : $t('components.aiChangeset.reject') }}
                </Button>
                <Button
                  size="xs"
                  variant="outline"
                  :disabled="busy"
                  @click="accept({ item_ids: [item.id], overwrite: item.status === 'conflict' })"
                >
                  {{ item.status === 'conflict' ? $t('components.aiChangeset.useProposal') : $t('components.aiChangeset.accept') }}
                </Button>
              </div>
            </div>

            <Textarea
              v-if="edits[item.id] !== undefined"
              v-model="edits[item.id]"
              :aria-label="$t('components.aiChangeset.editLabel')"
              auto-size
              class="text-sm"
            />
            <div
              v-else-if="item.status === 'conflict'"
              class="grid gap-3 text-sm md:grid-cols-3"
            >
              <div>
                <div class="mb-1 text-muted">{{ $t('components.aiChangeset.columns.read') }}</div>
                <ValueRenderer :value="item.before" />
              </div>
              <div>
                <div class="mb-1 text-muted">{{ $t('components.aiChangeset.columns.current') }}</div>
                <ValueRenderer :value="item.current" />
              </div>
              <div>
                <div class="mb-1 text-muted">{{ $t('components.aiChangeset.columns.proposed') }}</div>
                <ValueRenderer :value="item.after" />
              </div>
            </div>
            <div
              v-else-if="item.operation === 'create'"
              class="text-sm"
            >
              <ValueRenderer :value="createdContent(item)" />
            </div>
            <component
              :is="diffFor(item)"
              v-else-if="diffFor(item)"
              :old-value="item.before"
              :new-value="item.final ?? item.after"
            />
            <div
              v-else
              class="grid gap-3 text-sm md:grid-cols-2"
            >
              <ValueRenderer
                :value="item.before"
                class="text-muted"
              />
              <ValueRenderer :value="item.final ?? item.after" />
            </div>
            <p
              v-if="item.final !== null && item.final !== undefined"
              class="mt-2 text-sm text-muted"
            >
              {{ $t('components.aiChangeset.editedBeforeAccepting') }}
            </p>

            <ul
              v-if="item.glossary_findings.length > 0"
              class="mt-2 space-y-1 text-sm text-warning"
            >
              <li
                v-for="finding in item.glossary_findings"
                :key="`${finding.type}:${finding.term}:${finding.found ?? ''}`"
                class="flex items-center gap-1"
              >
                <Icon name="lucide:book-a" />
                {{ $t(`components.aiActions.findings.${finding.type}`, { term: finding.term, expected: finding.expected ?? '', found: finding.found ?? '' }) }}
              </li>
            </ul>
          </li>
        </ul>
      </section>
    </div>
  </Panel>
</template>
