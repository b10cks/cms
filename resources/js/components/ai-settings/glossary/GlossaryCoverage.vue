<script setup lang="ts">
import type { AiGlossaryAvailableLocale, AiGlossaryConceptRowsMeta } from '~/api/resources/ai-knowledge'

/** How complete each language is. A complete one stays quiet, a gap stands out and lists itself when pressed. */
const props = defineProps<{
  locales: readonly AiGlossaryAvailableLocale[]
  facets: AiGlossaryConceptRowsMeta['facets']
  active: string
  languageLabel: (code: string) => string
}>()

const emit = defineEmits<{ toggle: [code: string] }>()

const countOf = (list: readonly { value: string; count: number }[], code: string) =>
  list.find((facet) => facet.value === code)?.count ?? 0

const used = computed(() =>
  props.locales
    .filter((locale) => !locale.unused)
    .map(({ code }) => {
      const missing = countOf(props.facets.missing, code)
      return { code, missing, covered: props.facets.total - missing }
    })
)
const unused = computed(() =>
  props.locales
    .filter((locale) => locale.unused)
    .map(({ code }) => ({ code, count: countOf(props.facets.locales, code) }))
)
</script>

<template>
  <div
    v-if="used.length > 0 || unused.length > 0"
    class="flex gap-1 overflow-x-auto border-t border-border px-3 py-2 @3xl:flex-wrap"
    role="group"
    :aria-label="$t('glossaryImport.editor.coverage.label')"
  >
    <button
      v-for="language in used"
      :key="language.code"
      type="button"
      :aria-pressed="active === language.code"
      :disabled="language.missing === 0 && active !== language.code"
      class="group shrink-0 cursor-pointer rounded-lg px-2.5 py-1 text-left text-sm transition-colors hover:bg-tree-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-default disabled:hover:bg-transparent aria-pressed:bg-tree-selected motion-reduce:transition-none"
      @click="emit('toggle', language.code)"
    >
      <span
        class="block max-w-40 truncate"
        :class="language.missing > 0 ? 'text-foreground' : 'text-muted'"
      >{{ languageLabel(language.code) }}</span>
      <span class="block tabular-nums">
        <span :class="language.missing > 0 ? 'font-semibold text-primary' : 'text-muted'">
          {{ $t('glossaryImport.editor.coverage.of', { covered: language.covered, total: facets.total }) }}
        </span>
        <span
          v-if="language.missing > 0"
          class="ml-1.5 font-semibold text-warning-ink underline-offset-2 group-hover:underline"
        >{{ $t('glossaryImport.editor.coverage.missing', { count: language.missing }) }}</span>
      </span>
    </button>
    <div
      v-if="unused.length > 0"
      class="ml-1 shrink-0 border-l border-border px-3 py-1 text-sm"
    >
      <span class="block text-muted">{{ $t('glossaryImport.editor.unused') }}</span>
      <span
        v-for="language in unused"
        :key="language.code"
        class="mr-3 inline-block text-muted tabular-nums"
      >{{ languageLabel(language.code) }}: {{ language.count }}</span>
    </div>
  </div>
</template>
