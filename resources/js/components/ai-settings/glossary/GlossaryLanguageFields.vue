<script setup lang="ts">
import { refDebounced } from '@vueuse/core'

import type { AiGlossaryConceptConflict, AiGlossaryConceptRow } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { conceptCell, type ConceptForm } from '~/lib/glossaryConcepts'

import GlossaryChipsField from './GlossaryChipsField.vue'
import GlossaryField from './GlossaryField.vue'
import type { GlossaryConceptList } from './useGlossaryConceptList'

/** One language of a row: Use, Avoid and Also written as. No Use with some Avoid is a ban. */
const props = defineProps<{
  list: GlossaryConceptList
  form: ConceptForm
  code: string
  row: AiGlossaryConceptRow | null
  /** The language shown as a reference, null when this is it. */
  referenceCode: string | null
  serverConflict: AiGlossaryConceptConflict | null
  wordingError: string | null
  aiFilled: boolean
  languageLabel: (code: string) => string
}>()

const emit = defineEmits<{
  openConcept: [rowId: string]
  restore: []
}>()

const { t } = useI18n()

const language = computed(() => props.form.languages[props.code])
const reference = computed(() => {
  const source = props.referenceCode ? props.form.languages[props.referenceCode] : null
  return source?.use.trim() || source?.avoid[0] || null
})
const removed = computed(() => props.form.removed.includes(props.code))

const use = computed(() => language.value?.use.trim() ?? '')
const isBan = computed(() => !use.value && (language.value?.avoid.length ?? 0) > 0)

/** What this language goes by, which is what must be unique: its Use, or a ban's first Avoid. */
const wording = computed(() => use.value || language.value?.avoid[0] || '')
const storedLanguage = computed(() => props.row?.languages[props.code])
const storedWording = computed(() => storedLanguage.value?.use ?? storedLanguage.value?.avoid[0] ?? null)

const debouncedWording = refDebounced(wording, 300)
const holderCheck = computed(() =>
  debouncedWording.value && debouncedWording.value === wording.value && wording.value !== storedWording.value
    ? { locale: props.code, wording: wording.value, rowId: props.row?.id ?? null }
    : null
)
const { data: holder } = props.list.rowsApi.useWordingHolderQuery(holderCheck)

const sameWording = (a: string | undefined, b: string) => a?.toLocaleLowerCase() === b.toLocaleLowerCase()

const conflictHolder = computed(() => {
  const conflict = props.serverConflict
  if (conflict?.code === 'wording_conflict' && conflict.concept && sameWording(conflict.wording, wording.value)) {
    return { id: conflict.concept.id, term: conflict.concept.term }
  }
  if (holderCheck.value && holder.value) {
    const { languages, id } = holder.value
    const defaultLocale = props.list.meta.value?.default_locale
    const shown = (defaultLocale && languages[defaultLocale]) || languages[props.code]
    return { id, term: shown?.use ?? shown?.avoid[0] ?? '' }
  }
  return null
})

/** Stays until the wording is out of one of the two fields. */
const useAvoided = computed(() => {
  const conflict = props.serverConflict
  if (conflict?.code !== 'use_avoided' || !conflict.wording || !conflict.avoid) return null
  const { wording: held, avoid } = conflict
  return sameWording(held, use.value) && language.value?.avoid.some((entry) => sameWording(entry, avoid))
    ? t('glossaryImport.editor.fields.useAvoided', { wording: held, avoid })
    : null
})

const cell = computed(() => (props.row ? conceptCell(props.row, props.code) : null))
const fallback = computed(() => (!wording.value && cell.value?.state === 'fallback' ? cell.value : null))

const isAiFilled = computed(() => props.aiFilled && !!use.value)

defineExpose({
  focusWording: () => document.getElementById(`glossary-term-${props.code}`)?.focus(),
})
</script>

<template>
  <div
    v-if="language"
    class="grid gap-4"
  >
    <Alert
      v-if="removed"
      icon="lucide:trash-2"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm">{{ $t('glossaryImport.editor.languages.removedHint', { language: languageLabel(code) }) }}</span>
        <Button
          variant="ghost"
          size="sm"
          @click="emit('restore')"
        >
          {{ $t('glossaryImport.editor.languages.undo') }}
        </Button>
      </div>
    </Alert>

    <template v-else>
      <GlossaryField
        :id="`glossary-term-${code}`"
        :label="$t('glossaryImport.editor.fields.use')"
        :hint="$t('glossaryImport.editor.fields.useHint')"
        :error="wordingError ?? useAvoided"
      >
        <template #default="{ describedBy, invalid }">
          <Input
            :id="`glossary-term-${code}`"
            v-model="language.use"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            maxlength="255"
          />
          <div
            v-if="reference || $slots.fill"
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
          >
            <p
              v-if="referenceCode && reference && fallback?.from !== referenceCode"
              class="min-w-0 text-sm text-muted"
            >
              {{ languageLabel(referenceCode) }}: <span class="text-foreground">{{ reference }}</span>
            </p>
            <slot name="fill" />
          </div>
          <p
            v-if="isBan"
            class="flex items-center gap-1.5 text-sm font-semibold text-primary"
          >
            <Icon
              name="lucide:ban"
              class="text-destructive"
            />
            {{ $t('glossaryImport.editor.fields.ban') }}
          </p>
          <p
            v-if="isAiFilled"
            class="flex items-center gap-1.5 text-sm text-ai-tint-foreground"
          >
            <Icon name="ai:bot-translate" />
            {{ $t('glossaryImport.editor.ai.filled') }}
          </p>
          <p
            v-if="conflictHolder"
            class="text-sm text-warning-ink"
            role="status"
          >
            {{ $t('glossaryImport.editor.fields.duplicate', { language: languageLabel(code) }) }}
            <button
              type="button"
              class="min-h-6 cursor-pointer rounded-sm font-semibold underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              @click="emit('openConcept', conflictHolder.id)"
            >
              {{ $t('glossaryImport.editor.fields.duplicateOpen', { term: conflictHolder.term }) }}
            </button>
          </p>
          <p
            v-if="fallback"
            class="text-sm text-muted"
          >
            {{ $t('glossaryImport.editor.fields.fallback', { language: languageLabel(fallback.from), wording: fallback.wording }) }}
          </p>
        </template>
      </GlossaryField>

      <GlossaryChipsField
        :id="`glossary-avoid-${code}`"
        v-model="language.avoid"
        :label="$t('glossaryImport.editor.fields.avoid')"
        :hint="$t('glossaryImport.editor.fields.avoidHint')"
        :max="20"
      />

      <GlossaryChipsField
        :id="`glossary-also-${code}`"
        v-model="language.also"
        :label="$t('glossaryImport.editor.fields.also')"
        :hint="$t('glossaryImport.editor.fields.alsoHint')"
        :max="100"
      />
    </template>
  </div>
</template>
