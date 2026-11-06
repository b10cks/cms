<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import type { ContentTranslationGlossaryFinding } from '~/types/content-translations'

/**
 * What a translation breaks, one warning line each: glossary findings and a
 * length over the schema's maximum. Flags only, they never block a save.
 */
const props = defineProps<{
  findings: ContentTranslationGlossaryFinding[]
  length?: { count: number; max: number | null } | null
}>()

const { t } = useI18n()

const message = (finding: ContentTranslationGlossaryFinding): string => {
  switch (finding.type) {
    case 'forbidden_variant':
      return finding.expected
        ? t('components.translationAssist.checkGlossarySays', {
            expected: finding.expected,
            found: finding.found ?? '',
          })
        : t('components.translationAssist.checkAvoid', { found: finding.found ?? '' })
    case 'translated_do_not_translate':
      return t('components.translationAssist.checkKeepExact', {
        expected: finding.expected ?? finding.term,
      })
    case 'missing_term':
      return t('components.translationAssist.checkMissing', {
        term: finding.term,
        expected: finding.expected ?? '',
      })
  }
}

/** A "found X" finding already names the wording to use, so the missing-term line for it would repeat it. */
const shown = computed(() => {
  const named = new Set(
    props.findings.flatMap((finding) =>
      finding.type === 'forbidden_variant' && finding.expected ? [finding.expected] : []
    )
  )
  return props.findings.filter(
    (finding) => finding.type !== 'missing_term' || !named.has(finding.expected ?? '')
  )
})

const tooLong = computed(
  () => !!props.length?.max && props.length.count > props.length.max
)
</script>

<template>
  <div
    v-if="shown.length || tooLong"
    class="space-y-1 text-sm text-warning-ink"
  >
    <div
      v-for="(finding, index) in shown"
      :key="index"
      class="flex items-center gap-1.5"
    >
      <Icon
        name="lucide:triangle-alert"
        class="size-3.5 shrink-0"
      />
      <span>{{ message(finding) }}</span>
    </div>
    <div
      v-if="tooLong && length"
      class="flex items-center justify-between gap-4"
    >
      <span class="flex items-center gap-1.5">
        <Icon
          name="lucide:triangle-alert"
          class="size-3.5 shrink-0"
        />
        {{ t('components.translationAssist.checkTooLong', { max: length.max }) }}
      </span>
      <span class="tabular-nums">{{ length.count }}/{{ length.max }}</span>
    </div>
  </div>
</template>
