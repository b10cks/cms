<script setup lang="ts">
import AiItemReview from '~/components/ai/threads/AiItemReview.vue'
import { useItemDecisions } from '~/components/ai/threads/useItemDecisions'
import BlockItemDiff from '~/components/content/diff/BlockItemDiff.vue'
import { resolveDiffComponent } from '~/components/content/diff/field-diff'
import InlineTextDiff from '~/components/content/diff/InlineTextDiff.vue'
import ValueRenderer from '~/components/content/ValueRenderer.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Textarea } from '~/components/ui/textarea'
import type { AiChangesetItem, AiItemFlag } from '~/lib/aiChangesets'
import {
  assetFolderChange,
  assetTagChange,
  blockListChanges,
  createdBlockFields,
  diffFieldType,
  isOpenItem,
  isWrittenItem,
  itemNotes,
  plainText,
  readablePath,
  STRUCTURE_TARGET_TYPES,
  translationUnitValue,
} from '~/lib/aiChangesets'

/**
 * One field change: its diff on the history renderers, conflicts and
 * cross-locale changes side by side. Content is already in its draft and has
 * nothing to decide. A conflict and a proposal take accept and reject as icon
 * buttons, and a proposal an optional edit. Translations show per unit the source (with its change since
 * the current translation was made), the current and the written translation.
 * Asset proposals show tag and folder changes by name, new folders and tags
 * their reason. Flags show as warnings. The status shows once the field is decided.
 */
const props = defineProps<{
  item: AiChangesetItem
  busy: boolean
  /** Language names of a cross-locale change; source and translation show side by side */
  locales?: { source: string; target: string } | null
  /** The review group it shows in; a group accept sends the edits of its groups */
  scope?: string
}>()

const decisions = useItemDecisions()
watch(
  () => props.item,
  (item) => decisions.track(item, props.scope ?? null),
  { immediate: true }
)

/** The reviewer's edit of a text proposal; undefined while not editing. */
const edit = computed(() => decisions.edits.value[props.item.id])
const updateEdit = (value: string | number) => decisions.setEdit(props.item, String(value))

const emit = defineEmits<{
  accept: [overwrite: boolean]
  reject: []
}>()

const { t, te } = useI18n()

/** A block list proposal shows one row per added, removed or changed block. */
const blockChanges = computed(() =>
  props.item.field_type === 'blocks' && props.item.operation === 'update' && Array.isArray(props.item.after)
    ? blockListChanges(props.item.before, props.item.final ?? props.item.after)
    : null
)

const BLOCK_CHANGE_VARIANTS = { added: 'success', removed: 'destructive', changed: 'secondary' } as const

const isEditable = computed(
  () =>
    props.item.status === 'pending' &&
    props.item.operation === 'update' &&
    props.item.target_type !== 'selection' &&
    typeof props.item.after === 'string'
)

const startEdit = () => {
  if (typeof props.item.after === 'string') decisions.setEdit(props.item, props.item.after)
}

const path = computed(() => {
  const field = props.item.target_type === 'block' ? /^schema\.(.+)$/.exec(props.item.path ?? '') : null
  if (field) return t('components.aiChangeset.blockField', { key: field[1] })

  if (props.item.target_type === 'asset') {
    if (props.item.path === 'tags') return t('components.aiChangeset.assetFields.tags')
    if (props.item.path === 'folder_id') return t('components.aiChangeset.assetFields.folder')
  }

  const segments = (props.item.path ?? '').split('.')
  // Asset metadata paths are `fields.<language>.<field>`; the language shows on the columns.
  if (props.item.target_type === 'asset' && segments[0] === 'fields') return segments.slice(2).join(' → ')
  // Content paths alternate field keys and block IDs; the IDs mean nothing to a reader.
  const shown = props.item.target_type === 'content' ? segments.filter((_, index) => index % 2 === 0) : segments
  return shown.join(' → ')
})

const hasBefore = computed(() => props.item.before !== null && props.item.before !== undefined && props.item.before !== '')

const diff = computed(() =>
  resolveDiffComponent({
    type: props.item.before === null || props.item.before === undefined ? 'added' : 'changed',
    path: props.item.path ?? '',
    oldValue: props.item.before,
    newValue: props.item.final ?? props.item.after,
    fieldType: diffFieldType(props.item),
  })
)

const blockFields = computed(() => createdBlockFields(props.item))

const createdContent = computed(() => {
  const after = props.item.after
  return typeof after === 'object' && after !== null && 'content' in after ? (after as { content: unknown }).content : after
})

/** The source text when the item carries it, else what the field held before. */
const sourceColumn = computed(() =>
  props.item.source !== undefined && props.item.source !== null
    ? { label: props.locales?.source ?? '', value: props.item.source }
    : { label: t('components.aiChangeset.columns.currentIn', { language: props.locales?.target ?? '' }), value: props.item.before }
)

const isBlank = (value: unknown) => value === null || value === undefined || (typeof value === 'string' && value.trim() === '')

/** Per translated unit: why, the source and its change, the current and the proposed translation. */
const units = computed(() =>
  (props.item.translation_units ?? []).map((unit) => {
    const current = translationUnitValue(props.item, unit.unit_id, 'before')
    return {
      id: unit.unit_id,
      label: unit.unit_id === props.item.path ? null : readablePath(unit.unit_id),
      reason: unit.reason,
      source: plainText(unit.source),
      previous: unit.previous_source === null ? null : plainText(unit.previous_source),
      current: isBlank(current) ? null : current,
      proposed: translationUnitValue(props.item, unit.unit_id, 'after'),
    }
  })
)

const isStructure = computed(() => STRUCTURE_TARGET_TYPES.has(props.item.target_type))
const tagChange = computed(() => assetTagChange(props.item))
const folderChange = computed(() => assetFolderChange(props.item))
const notes = computed(() => itemNotes(props.item))
const noteTitle = (note: AiItemFlag) =>
  te(`components.aiChangeset.notes.${note.code}`) ? t(`components.aiChangeset.notes.${note.code}`) : null

const CREATED_LABELS: Partial<Record<AiChangesetItem['target_type'], string>> = {
  block: 'components.aiChangeset.newBlock',
  asset_folder: 'components.aiChangeset.newFolder',
  asset_tag: 'components.aiChangeset.newTag',
}

const UNIT_REASON_VARIANTS = { missing: 'secondary', empty: 'secondary', same_as_source: 'secondary', stale: 'warning' } as const

const STATUS_VARIANTS = {
  pending: 'secondary',
  accepted: 'success',
  rejected: 'surface',
  conflict: 'warning',
} as const satisfies Record<AiChangesetItem['status'], string>
</script>

<template>
  <div
    class="grid gap-2"
    :class="item.status === 'conflict' && 'border-l-2 border-warning pl-3'"
  >
    <div class="flex min-h-7 items-center gap-2">
      <span class="min-w-0 truncate text-sm font-medium">
        {{
          item.operation === 'create'
            ? $t(CREATED_LABELS[item.target_type] ?? 'components.aiChangeset.newEntry')
            : path
        }}
      </span>
      <Badge
        v-if="item.status !== 'pending'"
        :variant="STATUS_VARIANTS[item.status]"
        size="sm"
      >
        {{ isWrittenItem(item) ? $t('components.aiChangeset.status.written') : $t(`components.aiChangeset.status.${item.status}`) }}
      </Badge>
      <div
        v-if="isOpenItem(item)"
        class="ml-auto flex shrink-0 gap-1"
      >
        <Button
          v-if="isEditable && edit === undefined"
          size="toolbar"
          variant="ghost"
          :disabled="busy"
          :title="$t('components.aiChangeset.edit')"
          :aria-label="$t('components.aiChangeset.edit')"
          @click="startEdit"
        >
          <Icon name="lucide:pencil" />
        </Button>
        <Button
          v-else-if="edit !== undefined"
          size="toolbar"
          variant="ghost"
          :disabled="busy"
          :title="$t('components.aiChangeset.discardEdit')"
          :aria-label="$t('components.aiChangeset.discardEdit')"
          @click="decisions.discardEdit(item)"
        >
          <Icon name="lucide:pencil-off" />
        </Button>
        <Button
          size="toolbar"
          variant="ghost"
          :disabled="busy"
          :title="item.status === 'conflict' ? $t('components.aiChangeset.keepCurrent') : $t('components.aiChangeset.reject')"
          :aria-label="item.status === 'conflict' ? $t('components.aiChangeset.keepCurrent') : $t('components.aiChangeset.reject')"
          data-action="reject"
          @click="emit('reject')"
        >
          <Icon name="lucide:x" />
        </Button>
        <Button
          size="toolbar"
          variant="ai"
          :disabled="busy"
          :title="item.status === 'conflict' && edit === undefined ? $t('components.aiChangeset.useProposal') : $t('components.aiChangeset.accept')"
          :aria-label="item.status === 'conflict' && edit === undefined ? $t('components.aiChangeset.useProposal') : $t('components.aiChangeset.accept')"
          data-action="accept"
          @click="emit('accept', item.status === 'conflict')"
        >
          <Icon name="lucide:check" />
        </Button>
      </div>
    </div>

    <div
      v-if="item.status === 'conflict'"
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
      <Textarea
        v-if="edit !== undefined"
        :model-value="edit"
        :aria-label="$t('components.aiChangeset.editLabel')"
        :disabled="busy"
        auto-size
        class="text-sm md:col-span-3"
        @update:model-value="updateEdit"
      />
    </div>
    <Textarea
      v-else-if="edit !== undefined"
      :model-value="edit"
      :aria-label="$t('components.aiChangeset.editLabel')"
      :disabled="busy"
      auto-size
      class="text-sm"
      @update:model-value="updateEdit"
    />
    <ul
      v-else-if="units.length > 0"
      class="grid gap-3"
      :aria-label="$t('components.aiChangeset.translationUnits')"
    >
      <li
        v-for="unit in units"
        :key="unit.id"
        class="grid gap-1.5"
        :data-unit="unit.id"
      >
        <div
          v-if="unit.label || unit.reason"
          class="flex min-w-0 items-center gap-2 text-sm"
        >
          <span
            v-if="unit.label"
            class="min-w-0 truncate text-muted"
          >
            {{ unit.label }}
          </span>
          <Badge
            v-if="unit.reason"
            :variant="UNIT_REASON_VARIANTS[unit.reason]"
            size="sm"
          >
            {{ $t(`components.aiChangeset.unitReasons.${unit.reason}`) }}
          </Badge>
        </div>
        <div class="grid gap-3 text-sm @md:grid-cols-2">
          <div>
            <div class="mb-1 text-muted">
              {{ unit.previous !== null ? $t('components.aiChangeset.columns.sourceChange') : (locales?.source ?? $t('components.aiChangeset.columns.source')) }}
            </div>
            <InlineTextDiff
              v-if="unit.previous !== null"
              :old-value="unit.previous"
              :new-value="unit.source"
            />
            <ValueRenderer
              v-else
              :value="unit.source"
            />
          </div>
          <div class="grid content-start gap-2">
            <div v-if="unit.current !== null">
              <div class="mb-1 text-muted">{{ $t('components.aiChangeset.columns.currentTranslation') }}</div>
              <ValueRenderer
                :value="unit.current"
                class="text-muted"
              />
            </div>
            <div>
              <div class="mb-1 text-muted">{{ locales?.target ?? $t('components.aiChangeset.columns.proposedTranslation') }}</div>
              <ValueRenderer :value="unit.proposed" />
            </div>
          </div>
        </div>
      </li>
    </ul>
    <div
      v-else-if="isStructure"
      class="grid gap-1 text-sm"
    >
      <span class="font-medium">{{ item.target_label }}</span>
      <span
        v-if="item.review?.examples?.length"
        class="text-muted"
      >
        {{ $t('components.aiChangeset.structureExamples', { count: item.review.examples.length }) }}
      </span>
    </div>
    <div
      v-else-if="tagChange"
      class="grid gap-1.5 text-sm"
    >
      <div
        v-for="side in (['add', 'remove'] as const)"
        v-show="tagChange[side].length > 0"
        :key="side"
        class="flex flex-wrap items-center gap-1.5"
      >
        <span class="text-muted">{{ $t(`components.aiChangeset.tagChange.${side}`) }}</span>
        <span
          v-for="name in tagChange[side]"
          :key="name"
          class="rounded-md bg-chip px-1.5 py-0.5 text-chip-foreground"
          :class="side === 'remove' && 'line-through'"
        >
          {{ name }}
        </span>
      </div>
    </div>
    <div
      v-else-if="folderChange"
      class="flex flex-wrap items-center gap-2 text-sm"
    >
      <span class="text-muted">{{ folderChange.from ?? $t('components.aiChangeset.libraryRoot') }}</span>
      <Icon
        name="lucide:arrow-right"
        class="text-muted"
      />
      <span class="font-medium">{{ folderChange.to ?? $t('components.aiChangeset.libraryRoot') }}</span>
    </div>
    <div
      v-else-if="locales && item.operation === 'update'"
      class="grid gap-3 text-sm @md:grid-cols-2"
    >
      <div>
        <div class="mb-1 text-muted">{{ sourceColumn.label }}</div>
        <ValueRenderer :value="sourceColumn.value" />
      </div>
      <div>
        <div class="mb-1 text-muted">{{ locales.target }}</div>
        <ValueRenderer :value="item.final ?? item.after" />
      </div>
    </div>
    <ul
      v-else-if="item.operation === 'create' && item.target_type === 'block'"
      class="grid gap-1 text-sm"
      :aria-label="$t('components.aiChangeset.blockFields')"
    >
      <li
        v-for="field in blockFields"
        :key="field.key"
        class="flex items-baseline gap-2"
      >
        <span class="font-medium">{{ field.name }}</span>
        <span class="text-muted">{{ field.key }}</span>
        <Badge
          variant="secondary"
          size="sm"
          class="ml-auto"
        >
          {{ field.type }}
        </Badge>
      </li>
      <li
        v-if="blockFields.length === 0"
        class="text-muted"
      >
        {{ $t('components.aiChangeset.noBlockFields') }}
      </li>
    </ul>
    <div
      v-else-if="item.operation === 'create'"
      class="text-sm"
    >
      <ValueRenderer :value="createdContent" />
    </div>
    <ul
      v-else-if="blockChanges"
      class="grid gap-3"
    >
      <li
        v-for="change in blockChanges"
        :key="change.id"
        class="grid gap-1.5"
        :class="change.type === 'removed' && 'opacity-70'"
      >
        <Badge
          :variant="BLOCK_CHANGE_VARIANTS[change.type]"
          size="sm"
          class="w-fit"
        >
          {{ $t(`components.aiChangeset.blockChange.${change.type}`) }}
        </Badge>
        <BlockItemDiff
          :old-value="change.oldValue"
          :new-value="change.newValue"
          :children="change.children"
        />
      </li>
    </ul>
    <component
      :is="diff"
      v-else-if="diff"
      :old-value="item.before"
      :new-value="item.final ?? item.after"
    />
    <div
      v-else
      class="grid gap-3 text-sm"
      :class="hasBefore && '@md:grid-cols-2'"
    >
      <ValueRenderer
        v-if="hasBefore"
        :value="item.before"
        class="text-muted"
      />
      <ValueRenderer :value="item.final ?? item.after" />
    </div>
    <p
      v-if="item.final !== null && item.final !== undefined"
      class="text-sm text-muted"
    >
      {{ $t('components.aiChangeset.editedBeforeAccepting') }}
    </p>
    <p
      v-if="item.review?.reason"
      class="text-sm text-muted"
    >
      {{ item.review.reason }}
    </p>

    <ul
      v-if="notes.length > 0 && isOpenItem(item)"
      class="grid gap-1 rounded-md bg-warning-background/20 px-3 py-2 text-sm text-warning"
      :aria-label="$t('components.aiChangeset.notesLabel')"
    >
      <li
        v-for="note in notes"
        :key="`${note.code}:${note.message}`"
        class="flex items-start gap-2"
      >
        <Icon
          :name="note.code === 'uncertain' ? 'lucide:circle-help' : 'lucide:triangle-alert'"
          class="mt-0.5 shrink-0"
        />
        <span>
          <span
            v-if="noteTitle(note)"
            class="font-medium"
          >{{ noteTitle(note) }}</span>
          {{ note.message }}
        </span>
      </li>
    </ul>

    <AiItemReview
      v-if="item.status !== 'rejected'"
      :item="item"
    />
  </div>
</template>
