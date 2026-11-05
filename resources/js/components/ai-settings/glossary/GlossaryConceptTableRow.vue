<script setup lang="ts">
import type { AiGlossaryConceptRow } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Checkbox } from '~/components/ui/checkbox'
import { conceptCell, conceptTitleIsBan, conceptTitleLocale } from '~/lib/glossaryConcepts'

import GlossaryBanMark from './GlossaryBanMark.vue'
import { CELL, FROZEN_CELL } from './presentation'

/** One concept across its languages. The whole row opens it; the box beside the name selects it. */
const props = defineProps<{
  row: AiGlossaryConceptRow
  title: string
  /** The language the first column stands for. */
  defaultLocale: string | null
  locales: readonly string[]
  isOpen: boolean
  selected: boolean
  selectable: boolean
  languageLabel: (code: string) => string
}>()

const emit = defineEmits<{
  open: []
  toggle: [shift: boolean]
}>()

const route = useRoute()

/** A plain click opens the sheet through the row, so unsaved changes are asked about. A modified click keeps the new tab. */
const onLinkClick = (event: MouseEvent) => {
  if (event.metaKey || event.ctrlKey || event.shiftKey) event.stopPropagation()
  else event.preventDefault()
}

const cells = computed(() => props.locales.map((code) => ({ code, cell: conceptCell(props.row, code) })))
const titleIsBan = computed(() => conceptTitleIsBan(props.row, props.defaultLocale))
const lacksDefault = computed(() => !!props.defaultLocale && !props.row.languages[props.defaultLocale])
const titleLocale = computed(() => conceptTitleLocale(props.row, props.defaultLocale))
</script>

<template>
  <tr
    data-table-row
    :data-row-id="row.id"
    :data-open="isOpen || undefined"
    :data-selected="selected || undefined"
    class="group/row cursor-pointer outline-none"
    @click="emit('open')"
  >
    <td :class="[FROZEN_CELL, 'font-semibold text-primary']">
      <div class="flex min-w-0 items-center gap-1">
        <span
          v-if="selectable"
          class="-my-1 -ml-1.5 flex size-7 shrink-0 cursor-pointer items-center justify-center"
          @click.stop="emit('toggle', $event.shiftKey)"
        >
          <Checkbox
            :model-value="selected"
            tabindex="-1"
            :aria-label="$t('glossaryImport.editor.select', { term: title })"
          />
        </span>
        <RouterLink
          :to="{ query: { ...route.query, concept: row.id } }"
          tabindex="-1"
          class="truncate outline-none"
          :class="lacksDefault && 'font-normal text-muted'"
          :title="title"
          @click="onLinkClick"
        >{{ title }}</RouterLink>
        <GlossaryBanMark v-if="titleIsBan" />
        <span
          v-if="lacksDefault && titleLocale"
          class="shrink-0 text-sm font-normal text-muted"
        >{{ languageLabel(titleLocale) }}</span>
        <Icon
          v-if="lacksDefault && defaultLocale"
          name="lucide:circle-dashed"
          class="shrink-0 text-warning-ink"
          :title="$t('glossaryImport.editor.filters.missingIn', { language: languageLabel(defaultLocale) })"
          :aria-label="$t('glossaryImport.editor.filters.missingIn', { language: languageLabel(defaultLocale) })"
        />
      </div>
    </td>
    <td
      v-for="{ code, cell } in cells"
      :key="code"
      :class="[CELL, 'max-w-64 min-w-40']"
    >
      <span
        v-if="cell.state === 'own'"
        class="flex min-w-0 items-center gap-1.5"
      >
        <span
          class="truncate"
          :title="cell.wording"
        >{{ cell.wording }}</span>
        <GlossaryBanMark v-if="cell.ban" />
      </span>
      <span
        v-else-if="cell.state === 'fallback'"
        class="flex min-w-0 items-center gap-1.5 text-muted"
        :title="$t('glossaryImport.editor.cell.fallback', { language: languageLabel(cell.from) })"
      >
        <Icon
          name="lucide:corner-down-right"
          class="shrink-0"
        />
        <span class="truncate">{{ cell.wording }}</span>
        <span class="sr-only">{{ $t('glossaryImport.editor.cell.fallback', { language: languageLabel(cell.from) }) }}</span>
      </span>
      <span
        v-else
        class="inline-flex items-center gap-1.5 text-warning-ink"
      >
        <Icon
          name="lucide:circle-dashed"
          class="shrink-0"
        />
        {{ $t('glossaryImport.editor.cell.missing') }}
      </span>
    </td>
  </tr>
</template>
