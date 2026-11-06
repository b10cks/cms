<script setup lang="ts">
import { useOperationLanguages } from '~/components/ai/operations/useOperationFields'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import type { AiOutputRow, AiOutputTone } from '~/lib/aiOperations'

/**
 * One entry's outputs, by language where they have one: each with its outcome
 * and, where the run left them, its reason, note and open question. Questions
 * are answered together in AiOperationQuestions. Translation locales add the
 * units written and remaining, and a unit settled on its own gets its own row.
 */
const props = defineProps<{
  spaceId: string
  rows: readonly AiOutputRow[]
  /** The name of a schema or asset field, its key when unknown */
  fieldLabel: (key: string) => string
}>()

const { t, reasonLabel, outcomeLabel } = useOperationI18n()
const { named } = useOperationLanguages(props.spaceId, (name) => t('plan.metadata.defaultLanguage', { name }))

const TONES = {
  fulfilled: { icon: 'lucide:check', class: 'text-muted' },
  open: { icon: 'lucide:circle-dashed', class: 'text-muted' },
  exception: { icon: 'lucide:circle-alert', class: 'text-warning' },
} as const satisfies Record<AiOutputTone, { icon: string; class: string }>

const groups = computed(() => {
  const byLanguage = new Map<string | null, AiOutputRow[]>()
  for (const row of props.rows) {
    const language = row.target.type === 'locale' ? null : row.target.language
    byLanguage.set(language, [...(byLanguage.get(language) ?? []), row])
  }
  return [...byLanguage].map(([language, rows]) => ({ language, rows }))
})

const targetLabel = (row: AiOutputRow) => {
  const target = row.target
  switch (target.type) {
    case 'locale':
      return named(target.language)
    case 'unit':
      return t('targets.unit', { unit: target.unit })
    case 'field':
      return props.fieldLabel(target.field)
    case 'metadata': {
      const key = `metaKeys.${target.key}`
      return `${props.fieldLabel(target.field)}: ${t(key)}`
    }
    case 'tags':
    case 'folder':
      return t(`targets.${target.type}`)
    default:
      return row.part
  }
}
</script>

<template>
  <div class="grid grid-cols-1 gap-3">
    <div
      v-for="group in groups"
      :key="group.language ?? ''"
      class="grid min-w-0 grid-cols-1 gap-1"
    >
      <h5
        v-if="group.language"
        class="text-sm text-muted"
      >
        {{ named(group.language) }}
      </h5>
      <ul class="grid min-w-0 grid-cols-1 gap-1.5">
        <li
          v-for="row in group.rows"
          :key="row.part"
          class="grid min-w-0 grid-cols-1 gap-0.5 text-sm"
          data-output-row
        >
          <div class="flex flex-wrap items-center gap-x-2">
            <span class="min-w-0 flex-1 break-words">{{ targetLabel(row) }}</span>
            <span
              class="flex items-center gap-1"
              :class="TONES[row.tone].class"
            >
              <Icon :name="TONES[row.tone].icon" />
              {{ outcomeLabel(row.outcome) }}
            </span>
          </div>
          <p
            v-if="row.reason"
            class="text-muted"
          >
            {{ reasonLabel(row.reason) }}
          </p>
          <p
            v-if="row.note"
            class="text-muted"
          >
            {{ row.note }}
          </p>
          <p
            v-if="row.question"
            class="rounded-md bg-info-background px-2 py-1 text-info-foreground"
          >
            {{ t('ledger.question', { question: row.question }) }}
          </p>
          <p
            v-if="row.units"
            class="text-muted tabular-nums"
          >
            {{ t('ledger.units', { written: row.units.written.length, remaining: row.units.remaining.length }) }}
          </p>
          <p
            v-if="row.units && row.units.remaining.length > 0"
            class="break-words text-muted"
          >
            {{ t('ledger.remaining', { units: row.units.remaining.join(', ') }) }}
          </p>
          <p
            v-if="row.overwritable && row.tone === 'open'"
            class="text-muted"
          >
            {{ t('ledger.mayReplace') }}
          </p>
        </li>
      </ul>
    </div>
  </div>
</template>
