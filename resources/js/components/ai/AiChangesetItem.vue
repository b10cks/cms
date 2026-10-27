<script setup lang="ts">
import { resolveDiffComponent } from '~/components/content/diff/field-diff'
import ValueRenderer from '~/components/content/ValueRenderer.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Textarea } from '~/components/ui/textarea'
import type { AiChangesetItem } from '~/lib/aiChangesets'
import { diffFieldType, isOpenItem } from '~/lib/aiChangesets'

/**
 * One proposed field change: its diff on the history renderers, accept and
 * reject as icon buttons, an optional reviewer edit, conflicts and
 * cross-locale changes side by side and glossary findings as warnings on the
 * field. The status shows once the field is decided.
 */
const props = defineProps<{
  item: AiChangesetItem
  busy: boolean
  /** Language names of a cross-locale change; source and translation show side by side */
  locales?: { source: string; target: string } | null
}>()

/** The reviewer's edit of a text proposal; undefined while not editing. */
const edit = defineModel<string | undefined>('edit')

const emit = defineEmits<{
  accept: [overwrite: boolean]
  reject: []
}>()

const { t } = useI18n()

const isEditable = computed(
  () =>
    props.item.status === 'pending' &&
    props.item.operation === 'update' &&
    props.item.target_type !== 'selection' &&
    typeof props.item.after === 'string'
)

const startEdit = () => {
  if (typeof props.item.after === 'string') edit.value = props.item.after
}

const path = computed(() => {
  const segments = (props.item.path ?? '').split('.')
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
        {{ item.operation === 'create' ? $t('components.aiChangeset.newEntry') : path }}
      </span>
      <Badge
        v-if="item.status !== 'pending'"
        :variant="STATUS_VARIANTS[item.status]"
        size="sm"
      >
        {{ $t(`components.aiChangeset.status.${item.status}`) }}
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
          @click="edit = undefined"
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
          :title="item.status === 'conflict' ? $t('components.aiChangeset.useProposal') : $t('components.aiChangeset.accept')"
          :aria-label="item.status === 'conflict' ? $t('components.aiChangeset.useProposal') : $t('components.aiChangeset.accept')"
          data-action="accept"
          @click="emit('accept', item.status === 'conflict')"
        >
          <Icon name="lucide:check" />
        </Button>
      </div>
    </div>

    <Textarea
      v-if="edit !== undefined"
      v-model="edit"
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
    <div
      v-else-if="item.operation === 'create'"
      class="text-sm"
    >
      <ValueRenderer :value="createdContent" />
    </div>
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

    <ul
      v-if="item.glossary_findings.length > 0"
      class="grid gap-1 rounded-md bg-warning-background/20 px-3 py-2 text-sm text-warning"
      :aria-label="$t('components.aiChangeset.glossaryFindings')"
    >
      <li
        v-for="finding in item.glossary_findings"
        :key="`${finding.type}:${finding.term}:${finding.found ?? ''}`"
        class="flex items-center gap-2"
      >
        <Icon name="lucide:triangle-alert" />
        {{ $t(`components.aiActions.findings.${finding.type}`, { term: finding.term, expected: finding.expected ?? '', found: finding.found ?? '' }) }}
      </li>
    </ul>
  </div>
</template>
