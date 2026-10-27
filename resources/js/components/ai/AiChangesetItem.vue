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
 * reject, an optional reviewer edit, conflicts side by side and glossary
 * findings as warnings on the field.
 */
const props = defineProps<{
  item: AiChangesetItem
  busy: boolean
}>()

/** The reviewer's edit of a text proposal; undefined while not editing. */
const edit = defineModel<string | undefined>('edit')

const emit = defineEmits<{
  accept: [overwrite: boolean]
  reject: []
}>()

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
    <div class="flex flex-wrap items-center gap-2">
      <span class="min-w-0 truncate text-sm font-medium">
        {{ item.operation === 'create' ? $t('components.aiChangeset.newEntry') : path }}
      </span>
      <Badge :variant="STATUS_VARIANTS[item.status]">
        {{ $t(`components.aiChangeset.status.${item.status}`) }}
      </Badge>
      <div
        v-if="isOpenItem(item)"
        class="ml-auto flex gap-2"
      >
        <Button
          v-if="isEditable && edit === undefined"
          size="sm"
          variant="ghost"
          :disabled="busy"
          @click="startEdit"
        >
          <Icon name="lucide:pencil" />
          {{ $t('components.aiChangeset.edit') }}
        </Button>
        <Button
          v-else-if="edit !== undefined"
          size="sm"
          variant="ghost"
          :disabled="busy"
          @click="edit = undefined"
        >
          {{ $t('components.aiChangeset.discardEdit') }}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          :disabled="busy"
          @click="emit('reject')"
        >
          {{ item.status === 'conflict' ? $t('components.aiChangeset.keepCurrent') : $t('components.aiChangeset.reject') }}
        </Button>
        <Button
          size="sm"
          variant="outline"
          :disabled="busy"
          @click="emit('accept', item.status === 'conflict')"
        >
          {{ item.status === 'conflict' ? $t('components.aiChangeset.useProposal') : $t('components.aiChangeset.accept') }}
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
