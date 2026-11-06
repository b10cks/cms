<script setup lang="ts">
import type { AiGlossaryConceptRowsMeta, AiGlossaryAvailableLocale } from '~/api/resources/ai-knowledge'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '~/components/ui/select'

import type { GlossaryConceptList } from './useGlossaryConceptList'

const ALL = '__all__'

/** Search and "Missing in <language>", and the way to unfold languages with nothing to show. */
const props = defineProps<{
  list: GlossaryConceptList
  locales: readonly AiGlossaryAvailableLocale[]
  facets: AiGlossaryConceptRowsMeta['facets']
  foldedColumns: readonly string[]
  languageLabel: (code: string) => string
}>()

const { t } = useI18n()
const searchInput = props.list.searchInput
const allColumns = props.list.allColumns

const missing = computed({
  get: () => props.list.missing.value || ALL,
  set: (value: string) => {
    props.list.missing.value = value === ALL ? '' : value
  },
})

const missingOptions = computed(() =>
  props.locales
    .filter((locale) => !locale.unused)
    .map(({ code }) => ({
      code,
      label: t('glossaryImport.editor.filters.missingIn', { language: props.languageLabel(code) }),
      count: props.facets.missing.find((facet) => facet.value === code)?.count ?? 0,
    }))
)

const foldLabel = computed(() => {
  if (allColumns.value) return t('glossaryImport.editor.columns.fold')
  const count = props.foldedColumns.length
  return count <= 2
    ? t('glossaryImport.editor.columns.show', { languages: props.foldedColumns.map(props.languageLabel).join(', ') })
    : t('glossaryImport.editor.columns.showCount', { count })
})
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 border-t border-border px-5 py-3">
    <div class="relative w-full min-w-0 @2xl:w-auto @2xl:max-w-md @2xl:min-w-72 @2xl:flex-1">
      <Input
        v-model="searchInput"
        data-shortcut-search
        type="search"
        class="pr-8"
        :placeholder="$t('glossaryImport.editor.search')"
        :aria-label="$t('glossaryImport.editor.search')"
      />
      <kbd
        v-if="!searchInput"
        class="pointer-events-none absolute top-1/2 right-2 hidden -translate-y-1/2 rounded border border-border bg-surface px-1.5 font-sans text-sm text-muted md:block"
      >/</kbd>
    </div>
    <Select v-model="missing">
      <SelectTrigger
        :aria-label="$t('glossaryImport.editor.filters.missing')"
        class="w-full min-w-0 font-normal @2xl:w-auto @2xl:max-w-64"
        :class="list.missing.value ? 'border-border-strong font-semibold text-primary' : 'text-foreground'"
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem :value="ALL">{{ $t('glossaryImport.editor.filters.all') }}</SelectItem>
        <SelectItem
          v-for="option in missingOptions"
          :key="option.code"
          :value="option.code"
        >
          {{ option.label }}
          <span class="ml-2 text-muted tabular-nums">{{ option.count }}</span>
        </SelectItem>
      </SelectContent>
    </Select>
    <Button
      v-if="list.isFiltered.value"
      variant="ghost"
      size="sm"
      @click="list.clearFilters()"
    >
      {{ $t('glossaryImport.editor.filters.clear') }}
    </Button>
    <Button
      v-if="foldedColumns.length > 0"
      variant="ghost"
      size="sm"
      class="@2xl:ml-auto"
      :aria-pressed="allColumns"
      @click="allColumns = !allColumns"
    >
      {{ foldLabel }}
    </Button>
  </div>
</template>
