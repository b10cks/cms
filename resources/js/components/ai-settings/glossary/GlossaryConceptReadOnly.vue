<script setup lang="ts">
import type { AiGlossaryConceptRow } from '~/api/resources/ai-knowledge'
import { conceptCell } from '~/lib/glossaryConcepts'

/** One concept for someone who can't change it: plain text, nothing greyed out. */
const props = defineProps<{
  row: AiGlossaryConceptRow
  code: string
  languageLabel: (code: string) => string
}>()

const language = computed(() => props.row.languages[props.code] ?? null)
const cell = computed(() => conceptCell(props.row, props.code))
</script>

<template>
  <dl class="grid gap-3 text-sm">
    <div class="grid gap-0.5">
      <dt class="text-muted">{{ $t('glossaryImport.editor.fields.use') }}</dt>
      <dd class="font-semibold text-primary">
        <template v-if="cell.state === 'own'">{{ cell.ban ? $t('glossaryImport.editor.fields.ban') : cell.wording }}</template>
        <template v-else-if="cell.state === 'fallback'">
          {{ $t('glossaryImport.editor.fields.fallback', { language: languageLabel(cell.from), wording: cell.wording }) }}
        </template>
        <template v-else>{{ $t('glossaryImport.editor.cell.missing') }}</template>
      </dd>
    </div>
    <div
      v-if="language?.avoid.length"
      class="grid gap-0.5"
    >
      <dt class="text-muted">{{ $t('glossaryImport.editor.fields.avoid') }}</dt>
      <dd class="text-primary">{{ language.avoid.join(', ') }}</dd>
    </div>
    <div
      v-if="language?.also.length"
      class="grid gap-0.5"
    >
      <dt class="text-muted">{{ $t('glossaryImport.editor.fields.also') }}</dt>
      <dd class="text-primary">{{ language.also.join(', ') }}</dd>
    </div>
    <div
      v-if="row.note"
      class="grid gap-0.5"
    >
      <dt class="text-muted">{{ $t('glossaryImport.editor.fields.note') }}</dt>
      <dd class="whitespace-pre-line text-primary">{{ row.note }}</dd>
    </div>
    <div
      v-if="row.keep_exact"
      class="grid gap-0.5"
    >
      <dt class="text-muted">{{ $t('glossaryImport.editor.fields.keepExact') }}</dt>
      <dd class="text-primary">{{ $t('glossaryImport.editor.fields.keepExactHint') }}</dd>
    </div>
  </dl>
</template>
