<script setup lang="ts">
import type { AiGlossaryConceptRow } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import { Panel } from '~/components/ui/panel'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { useHoverPrefetch } from '~/composables/useHoverPrefetch'
import { useTableKeyboard } from '~/composables/useTableKeyboard'
import { columnsWithoutWording, conceptTitle, rangeBetween } from '~/lib/glossaryConcepts'
import { formatKeys } from '~/lib/shortcuts'

import GlossaryConceptEmpty from './GlossaryConceptEmpty.vue'
import GlossaryConceptFilters from './GlossaryConceptFilters.vue'
import GlossaryConceptTableRow from './GlossaryConceptTableRow.vue'
import GlossaryCoverage from './GlossaryCoverage.vue'
import GlossarySelectionBar from './GlossarySelectionBar.vue'
import type { GlossaryConceptList } from './useGlossaryConceptList'

/**
 * The concept table: coverage, search and the missing filter, then one row per meaning
 * with a column per language. The page reaches the server, the list only renders its page.
 */
const props = defineProps<{
  list: GlossaryConceptList
  canEdit: boolean
  languageLabel: (code: string) => string
  exportTemplateUrl: (format: 'csv' | 'xlsx') => string
}>()

const emit = defineEmits<{
  open: [rowId: string]
  create: [wording?: string]
  importSheet: []
}>()

/** This many rows fit on the smallest page, so paging has nothing to offer. */
const SMALL_GLOSSARY = 25

const { t } = useI18n()

const rows = props.list.rows
const meta = props.list.meta
const currentPage = props.list.page
const isChangingView = props.list.isChangingView
const isFiltered = props.list.isFiltered
const openId = props.list.concept
const paginationBindings = props.list.table.paginationBindings
const result = props.list.result

const locales = computed(() => meta.value?.available_locales ?? [])
const defaultLocale = computed(() => meta.value?.default_locale ?? locales.value[0]?.code ?? null)
/** The first column is the default language, so it needs no second column. */
const languageColumns = computed(() =>
  locales.value.filter((locale) => !locale.unused && locale.code !== defaultLocale.value).map((locale) => locale.code)
)
/** The language being filtered on stays: its gaps are what the person came to see. */
const foldedColumns = computed(() =>
  columnsWithoutWording(rows.value, languageColumns.value).filter((code) => code !== props.list.missing.value)
)
const columns = computed(() =>
  props.list.allColumns.value ? languageColumns.value : languageColumns.value.filter((code) => !foldedColumns.value.includes(code))
)

const titleOf = (row: AiGlossaryConceptRow) => conceptTitle(row, defaultLocale.value)

const announcement = computed(() => {
  if (!meta.value) return undefined
  return isFiltered.value
    ? t('glossaryImport.editor.countFiltered', { count: meta.value.total, total: meta.value.facets.total })
    : t('glossaryImport.editor.count', { count: meta.value.total }, meta.value.total)
})
const headerMeta = computed(() => {
  if (!meta.value) return undefined
  return isFiltered.value ? `${meta.value.total} / ${meta.value.facets.total}` : String(meta.value.total)
})

const isEmpty = computed(() => meta.value?.facets.total === 0)
const firstLoad = computed(() => result.isLoading.value && !meta.value)

const selected = ref<ReadonlySet<string>>(new Set())
let anchor: number | null = null

const toggle = (index: number, shift: boolean) => {
  const row = rows.value[index]
  if (!row) return

  const next = new Set(selected.value)
  if (shift && anchor !== null) {
    for (const { id } of rangeBetween(rows.value, anchor, index)) next.add(id)
  } else {
    if (next.has(row.id)) next.delete(row.id)
    else next.add(row.id)
    anchor = index
  }
  selected.value = next
}

const extendTo = (index: number) => {
  anchor ??= focusedIndex.value
  selected.value = new Set([...selected.value, ...rangeBetween(rows.value, anchor, index).map(({ id }) => id)])
}

const clearSelection = () => {
  selected.value = new Set()
  anchor = null
}

const pageState = computed<boolean | 'indeterminate'>(() => {
  const count = rows.value.filter(({ id }) => selected.value.has(id)).length
  if (count === 0) return false
  return count === rows.value.length ? true : 'indeterminate'
})

const togglePage = () => {
  const next = new Set(selected.value)
  const adding = pageState.value !== true
  for (const { id } of rows.value) {
    if (adding) next.add(id)
    else next.delete(id)
  }
  selected.value = next
}

/** A selection holds across pages. A new filter starts over, so it can't reach rows out of view. */
watch(() => {
  const { page: _page, per_page: _perPage, ...narrowing } = props.list.filters.value
  return JSON.stringify(narrowing)
}, clearSelection)

const { container, focusedIndex, focusRow } = useTableKeyboard({
  page: currentPage,
  lastPage: () => meta.value?.last_page,
  onOpen: (row) => {
    if (row.dataset.rowId) emit('open', row.dataset.rowId)
  },
})

const onKeydown = (event: KeyboardEvent) => {
  const target = event.target
  if (!(target instanceof HTMLElement) || !target.matches('[data-table-row]')) return

  const index = rows.value.findIndex(({ id }) => id === target.dataset.rowId)
  if (index < 0) return

  const key = event.key.toLowerCase()
  const step = key === 'j' || key === 'arrowdown' ? 1 : key === 'k' || key === 'arrowup' ? -1 : 0

  if (step !== 0) {
    if (key === 'j' || key === 'k') {
      event.preventDefault()
      focusRow(index + step)
    }
    if (event.shiftKey && props.canEdit) extendTo(Math.max(0, Math.min(index + step, rows.value.length - 1)))
    return
  }

  if ((key === ' ' || key === 'x') && props.canEdit) {
    event.preventDefault()
    toggle(index, event.shiftKey)
  }
}

const listHasFocus = ref(false)
const onFocusOut = (event: FocusEvent) => {
  listHasFocus.value = !!container.value?.contains(event.relatedTarget as Node | null)
}

const hints = computed(() => [
  { keys: ['↑', '↓'], label: t('glossaryImport.editor.hints.move') },
  { keys: formatKeys('enter'), label: t('glossaryImport.editor.hints.open') },
  ...(props.canEdit ? [{ keys: formatKeys('space'), label: t('glossaryImport.editor.hints.select') }] : []),
])

const { start: startPrefetch, cancel: cancelPrefetch } = useHoverPrefetch((target: number) => props.list.prefetchPage(target))

/** Puts focus back on a row, used when the sheet closes. */
const focusConcept = (rowId: string) => {
  const index = rows.value.findIndex(({ id }) => id === rowId)
  focusRow(index >= 0 ? index : 0)
  container.value?.querySelector('[data-row-id][tabindex="0"]')?.scrollIntoView({ block: 'nearest' })
}

defineExpose({ focusConcept })
</script>

<template>
  <Panel
    id="glossary-concepts"
    :title="$t('glossaryImport.editor.title')"
    :meta="headerMeta"
    flush
  >
    <template #actions>
      <dl
        class="hidden items-center gap-3 text-sm text-muted transition-opacity motion-reduce:transition-none @3xl:flex"
        :class="listHasFocus ? 'opacity-100' : 'opacity-0'"
        :aria-hidden="!listHasFocus"
      >
        <div
          v-for="hint in hints"
          :key="hint.label"
          class="flex items-center gap-1"
        >
          <dt class="flex gap-0.5">
            <kbd
              v-for="key in hint.keys"
              :key="key"
              class="rounded border border-border bg-surface px-1.5 font-sans text-sm"
            >{{ key }}</kbd>
          </dt>
          <dd>{{ hint.label }}</dd>
        </div>
      </dl>
    </template>

    <GlossaryConceptEmpty
      v-if="isEmpty"
      :can-edit="canEdit"
      :template-url="exportTemplateUrl"
      @create="emit('create')"
      @import-sheet="emit('importSheet')"
    />

    <template v-else>
      <GlossaryCoverage
        v-if="meta"
        :locales="locales"
        :facets="meta.facets"
        :active="list.missing.value"
        :language-label="languageLabel"
        @toggle="list.toggleMissing"
      />
      <div
        v-else
        class="h-22 border-t border-border"
      />
      <GlossaryConceptFilters
        v-if="meta"
        :list="list"
        :locales="locales"
        :facets="meta.facets"
        :folded-columns="foldedColumns"
        :language-label="languageLabel"
      />

      <p
        class="sr-only"
        role="status"
        aria-live="polite"
      >
        {{ announcement }}
      </p>

      <Alert
        v-if="result.isError.value && !meta"
        icon="lucide:triangle-alert"
        color="destructive"
        class="mx-5 mb-4 w-auto"
      >
        <span class="text-sm">{{ $t('glossaryImport.editor.loadFailed') }}</span>
        <Button
          size="sm"
          variant="ghost"
          @click="result.refetch()"
        >
          {{ $t('actions.retry') }}
        </Button>
      </Alert>

      <div
        ref="container"
        class="relative max-h-[calc(100dvh-11rem)] min-h-64 overflow-auto border-t border-border transition-opacity motion-reduce:transition-none"
        :class="isChangingView && 'opacity-60'"
        :aria-busy="isChangingView"
        @keydown="onKeydown"
        @focusin="listHasFocus = true"
        @focusout="onFocusOut"
      >
        <table
          class="w-full border-separate border-spacing-0 text-sm"
          aria-labelledby="glossary-concepts"
        >
          <thead class="sticky top-0 z-20">
            <tr>
              <th
                scope="col"
                class="sticky left-0 z-10 h-10 w-44 max-w-44 border-r border-b border-border border-b-border-strong bg-muted-background px-3 text-left font-bold text-foreground @3xl:w-72 @3xl:max-w-72"
              >
                <div class="flex items-center gap-1">
                  <span
                    v-if="canEdit && rows.length > 0"
                    class="-my-1 -ml-1.5 flex size-7 cursor-pointer items-center justify-center"
                    @click="togglePage"
                  >
                    <Checkbox
                      :model-value="pageState"
                      :aria-label="$t('glossaryImport.editor.selectPage')"
                    />
                  </span>
                  <span class="truncate">{{ defaultLocale ? languageLabel(defaultLocale) : $t('glossaryImport.editor.columns.concept') }}</span>
                </div>
              </th>
              <th
                v-for="code in columns"
                :key="code"
                scope="col"
                :title="code"
                class="h-10 border-b border-b-border-strong bg-muted-background px-3 text-left font-bold whitespace-nowrap text-foreground"
              >
                {{ languageLabel(code) }}
              </th>
            </tr>
          </thead>
          <tbody>
            <TableLoadingRow
              v-if="firstLoad"
              :colspan="columns.length + 1"
              :rows="8"
            />
            <GlossaryConceptTableRow
              v-for="(row, index) in rows"
              :key="row.id"
              :row="row"
              :title="titleOf(row)"
              :default-locale="defaultLocale"
              :locales="columns"
              :is-open="openId === row.id"
              :selected="selected.has(row.id)"
              :selectable="canEdit"
              :language-label="languageLabel"
              @open="emit('open', row.id)"
              @toggle="toggle(index, $event)"
            />
          </tbody>
        </table>

        <div
          v-if="!firstLoad && rows.length === 0 && isFiltered"
          class="sticky left-0 flex w-full max-w-[100cqw] flex-col items-center gap-3 px-5 py-10 text-center"
        >
          <p class="font-semibold text-primary">{{ $t('glossaryImport.editor.noResults.title') }}</p>
          <div class="flex flex-wrap justify-center gap-2">
            <Button
              variant="outline"
              size="sm"
              @click="list.clearFilters()"
            >
              {{ $t('glossaryImport.editor.filters.clear') }}
            </Button>
            <Button
              v-if="canEdit && list.filters.value.search"
              variant="primary"
              size="sm"
              @click="emit('create', list.filters.value.search)"
            >
              <Icon name="lucide:plus" />
              {{ $t('glossaryImport.editor.noResults.create', { wording: list.filters.value.search }) }}
            </Button>
          </div>
        </div>
      </div>

      <div
        v-if="meta && meta.total > SMALL_GLOSSARY"
        class="overflow-x-auto border-t border-border px-3 [&>div]:flex-wrap [&>div]:justify-center [&>div]:gap-2"
        @mouseenter="startPrefetch(currentPage + 1)"
        @mouseleave="cancelPrefetch(currentPage + 1)"
        @focusin="startPrefetch(currentPage + 1)"
        @focusout="cancelPrefetch(currentPage + 1)"
      >
        <TablePaginationFooter
          :meta="meta"
          v-bind="paginationBindings"
        />
      </div>
    </template>

    <GlossarySelectionBar
      v-if="canEdit"
      :list="list"
      :selected="selected"
      @clear="clearSelection"
    />
  </Panel>
</template>
