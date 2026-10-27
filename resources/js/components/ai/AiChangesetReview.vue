<script setup lang="ts">
import { useEventListener } from '@vueuse/core'

import AiChangesetItem from '~/components/ai/AiChangesetItem.vue'
import { Button } from '~/components/ui/button'
import { Panel } from '~/components/ui/panel'
import { Spinner } from '~/components/ui/spinner'
import type { AiChangeset, AiChangesetItem as Item, AiDecision } from '~/lib/aiChangesets'
import { groupChangesetItems, isOpenItem } from '~/lib/aiChangesets'

/**
 * Review of one changeset: a panel per entry with its field diffs, accept and
 * reject per field, per entry or for everything. The `header` slot replaces
 * the compact default header (the inbox review page renders its own); with
 * `keyboard`, j/k and the arrow keys move between entries.
 */
const props = defineProps<{
  spaceId: string
  changesetId: string
  keyboard?: boolean
}>()

defineSlots<{
  header?: (props: {
    changeset: AiChangeset
    busy: boolean
    accepting: boolean
    acceptAll: () => void
    rejectAll: () => void
  }) => unknown
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

const openIds = (items: readonly Item[]) => items.filter(isOpenItem).map((item) => item.id)

/** Reviewer edits of text proposals; accepting applies them instead of the proposal. */
const edits = ref<Record<string, string | undefined>>({})

const dropEdits = (ids?: readonly string[]) => {
  edits.value = ids ? Object.fromEntries(Object.entries(edits.value).filter(([id]) => !ids.includes(id))) : {}
}

const accept = (decision: AiDecision) => {
  const ids = decision.item_ids
  const values = Object.fromEntries(
    Object.entries(edits.value).filter(
      (entry): entry is [string, string] => entry[1] !== undefined && (!ids || ids.includes(entry[0]))
    )
  )
  acceptMutation.mutate(
    {
      changesetId: props.changesetId,
      decision: Object.keys(values).length > 0 ? { ...decision, values } : decision,
    },
    { onSuccess: () => dropEdits(ids) }
  )
}
const reject = (decision: AiDecision) => rejectMutation.mutate({ changesetId: props.changesetId, decision })

const acceptAll = () => accept({})
const rejectAll = () => reject({})

const panels = ref<HTMLElement[]>([])

const moveFocus = (step: number) => {
  const list = panels.value
  if (list.length === 0) return
  const current = list.findIndex((panel) => panel.contains(document.activeElement))
  const next = current === -1 ? (step > 0 ? 0 : list.length - 1) : Math.min(list.length - 1, Math.max(0, current + step))
  list[next]?.focus()
  list[next]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

useEventListener('keydown', (event: KeyboardEvent) => {
  if (!props.keyboard || event.metaKey || event.ctrlKey || event.altKey) return
  const target = event.target
  if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return
  if (document.querySelector('[role="dialog"], [role="menu"]')) return

  const step = event.key === 'j' || event.key === 'ArrowDown' ? 1 : event.key === 'k' || event.key === 'ArrowUp' ? -1 : 0
  if (step === 0) return
  event.preventDefault()
  moveFocus(step)
})
</script>

<template>
  <div
    v-if="isLoading"
    class="flex justify-center py-10"
  >
    <Spinner />
  </div>
  <div
    v-else-if="changeset"
    class="grid gap-4"
  >
    <slot
      name="header"
      :changeset="changeset"
      :busy="busy"
      :accepting="acceptMutation.isPending.value"
      :accept-all="acceptAll"
      :reject-all="rejectAll"
    >
      <div class="flex flex-wrap items-center gap-2">
        <div class="min-w-0 flex-1">
          <h3 class="truncate font-semibold">{{ changeset.title }}</h3>
          <p class="text-sm text-muted">{{ meta }}</p>
        </div>
        <Button
          size="sm"
          variant="ghost"
          :disabled="busy || changeset.pending_count === 0"
          @click="rejectAll"
        >
          {{ $t('components.aiChangeset.rejectAll') }}
        </Button>
        <Button
          size="sm"
          variant="ai"
          :disabled="busy || changeset.pending_count === 0"
          :loading="acceptMutation.isPending.value"
          @click="acceptAll"
        >
          {{ $t('components.aiChangeset.acceptAll') }}
        </Button>
      </div>
    </slot>

    <p
      v-if="changeset.run?.partial"
      class="rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
    >
      {{ $t('components.aiChangeset.partial', { reason: $t(`components.aiChangeset.stoppedBy.${changeset.run.stopped_by ?? 'other'}`) }) }}
    </p>

    <div
      v-for="entry in entries"
      :key="entry.key"
      ref="panels"
      tabindex="-1"
      class="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
      <Panel :meta="$t(`components.aiChangeset.targets.${entry.targetType}`)">
        <template #title>
          <h2 class="min-w-0 truncate text-panel font-semibold text-primary">
            <RouterLink
              v-if="entry.targetType === 'content' && entry.targetId"
              :to="`/${spaceId}/content/${entry.targetId}`"
              class="hover:underline"
            >
              {{ entry.label }}
            </RouterLink>
            <span v-else>{{ entry.label }}</span>
          </h2>
        </template>
        <template
          v-if="openIds(entry.items).length > 0 && entries.length > 1"
          #actions
        >
          <Button
            size="sm"
            variant="ghost"
            :disabled="busy"
            @click="reject({ item_ids: openIds(entry.items) })"
          >
            {{ $t('components.aiChangeset.rejectEntry') }}
          </Button>
          <Button
            size="sm"
            variant="outline"
            :disabled="busy"
            @click="accept({ item_ids: openIds(entry.items) })"
          >
            {{ $t('components.aiChangeset.acceptEntry') }}
          </Button>
        </template>

        <ul class="divide-y divide-border">
          <li
            v-for="item in entry.items"
            :key="item.id"
            class="py-4 first:pt-0 last:pb-0"
          >
            <AiChangesetItem
              v-model:edit="edits[item.id]"
              :item="item"
              :busy="busy"
              @accept="(overwrite) => accept({ item_ids: [item.id], overwrite })"
              @reject="reject({ item_ids: [item.id] })"
            />
          </li>
        </ul>
      </Panel>
    </div>
  </div>
</template>
