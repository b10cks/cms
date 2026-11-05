<script setup lang="ts">
import { watchDebounced } from '@vueuse/core'

import type { AiGlossaryAvailableLocale, AiGlossaryCheckResult } from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '~/components/ui/dialog'
import { SelectField, TextField } from '~/components/ui/form'
import { glossaryFindingText, isGlossaryHint, type AiGlossaryFinding } from '~/lib/aiRun'
import { markedSegments } from '~/lib/glossaryConcepts'
import { errorMessage } from '~/lib/toast-error'

import type { GlossaryConceptList } from './useGlossaryConceptList'

const NO_TARGET = '__none__'
const MAX_LENGTH = 50000

/**
 * Tries a text against saved wording without a provider call: where the terms are, what
 * the AI is told about each, and what an output would be flagged for. It reads this
 * glossary alone, or everything a run in this space uses.
 */
const props = defineProps<{
  list: GlossaryConceptList
  glossaryId: string
  /** A space runs AI, so it can try what a run uses. A team has only its glossaries. */
  canCheckSpace: boolean
  /** Whether runs use this glossary at all. */
  enabled: boolean
  locales: readonly AiGlossaryAvailableLocale[]
  defaultLocale: string | null
  languageLabel: (code: string) => string
}>()

const emit = defineEmits<{ openConcept: [rowId: string] }>()

const { t } = useI18n()
const { mutateAsync } = props.list.rowsApi.useCheckMutation()

const open = ref(false)
const codes = computed(() => props.locales.filter((locale) => !locale.unused).map(({ code }) => code))
const text = ref('')
const output = ref('')
const pickedSource = ref('')
const target = ref(NO_TARGET)
const scope = ref<'glossary' | 'space'>('glossary')
const source = computed(() => pickedSource.value || props.defaultLocale || codes.value[0] || '')

const scopeOptions = computed(() => [
  { value: 'glossary', label: t('glossaryImport.editor.tryText.scopeGlossary') },
  { value: 'space', label: t('glossaryImport.editor.tryText.scopeSpace') },
])
const sourceOptions = computed(() => codes.value.map((value) => ({ value, label: props.languageLabel(value) })))
const targetOptions = computed(() => [
  { value: NO_TARGET, label: t('glossaryImport.editor.tryText.noTarget') },
  ...codes.value.filter((value) => value !== source.value).map((value) => ({ value, label: props.languageLabel(value) })),
])

/** The result and the text it belongs to, so highlights never point into newer typing. */
const checked = shallowRef<{ result: AiGlossaryCheckResult; text: string } | null>(null)
const failure = ref<string | null>(null)
const isChecking = ref(false)
let latest = 0
/** What the last check was asked, so the change that opening makes is not checked twice. */
let lastAsked = ''
const asking = () => JSON.stringify([text.value, output.value, source.value, target.value, scope.value])

const check = async () => {
  const body = text.value
  const run = ++latest
  lastAsked = asking()

  if (!body.trim() || !source.value) {
    checked.value = null
    failure.value = null
    isChecking.value = false
    return
  }

  isChecking.value = true
  try {
    const result = await mutateAsync({
      text: body,
      source_locale: source.value,
      target_locale: target.value === NO_TARGET ? undefined : target.value,
      scope: scope.value,
      output: output.value.trim() ? output.value : undefined,
    })
    if (run !== latest) return
    checked.value = { result, text: body }
    failure.value = null
  } catch (error) {
    if (run === latest) failure.value = errorMessage(error)
  } finally {
    if (run === latest) isChecking.value = false
  }
}

watchDebounced(
  [text, output, source, target, scope],
  () => {
    if (asking() !== lastAsked) void check()
  },
  { debounce: 400 }
)

const terms = computed(() => checked.value?.result.terms ?? [])
const segments = computed(() =>
  checked.value ? markedSegments(checked.value.text, terms.value.flatMap((term) => term.matches)) : []
)
const findings = computed(() => checked.value?.result.findings ?? null)

/** A hint only goes to the agent, so it says so instead of reading like a flag. */
const findingText = (finding: AiGlossaryFinding) =>
  isGlossaryHint(finding)
    ? t('glossaryImport.editor.tryText.told', { term: finding.term, expected: finding.expected ?? '' })
    : t('glossaryImport.editor.tryText.flagged', {
        finding: glossaryFindingText(finding, (key, params) => t(key, params)),
      })

const summary = computed(() => {
  if (!checked.value) return ''
  const count = terms.value.length
  return t('glossaryImport.editor.tryText.summary', { count }, count)
})

const openTerm = (rowId: string) => {
  open.value = false
  emit('openConcept', rowId)
}

/**
 * Opens the dialog, with a concept's wording in place of the text when given one. Saved
 * wording can have changed since the last result, also under the same text, so it checks
 * again at once and shows nothing from before.
 */
const start = (prefill?: { text: string; locale: string }) => {
  if (prefill) {
    text.value = prefill.text
    pickedSource.value = prefill.locale
    target.value = NO_TARGET
    output.value = ''
  }
  checked.value = null
  failure.value = null
  open.value = true
  void check()
}

defineExpose({ start })
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-4xl!">
      <div class="pr-6">
        <DialogTitle>{{ $t('glossaryImport.editor.tryText.title') }}</DialogTitle>
        <DialogDescription>{{ $t('glossaryImport.editor.tryText.meta') }}</DialogDescription>
      </div>

      <div class="grid min-w-0 gap-5 md:grid-cols-2">
        <div class="grid min-w-0 content-start gap-4">
          <TextField
            id="glossary-try-source"
            v-model="text"
            name="glossary-try-source"
            :label="$t('glossaryImport.editor.tryText.text')"
            :rows="5"
            :maxlength="MAX_LENGTH"
            auto-size
          />
          <div class="grid gap-4 sm:grid-cols-2">
            <SelectField
              id="glossary-try-source-locale"
              name="glossary-try-source-locale"
              :label="$t('glossaryImport.editor.tryText.source')"
              :options="sourceOptions"
              :model-value="source"
              @update:model-value="(value) => (pickedSource = String(value))"
            />
            <SelectField
              id="glossary-try-target-locale"
              v-model="target"
              name="glossary-try-target-locale"
              :label="$t('glossaryImport.editor.tryText.target')"
              :options="targetOptions"
            />
          </div>
          <SelectField
            v-if="canCheckSpace"
            id="glossary-try-scope"
            v-model="scope"
            name="glossary-try-scope"
            :label="$t('glossaryImport.editor.tryText.scope')"
            :options="scopeOptions"
          />
          <TextField
            id="glossary-try-output"
            v-model="output"
            name="glossary-try-output"
            :label="$t('glossaryImport.editor.tryText.output')"
            :tooltip="$t('glossaryImport.editor.tryText.outputHint')"
            :rows="3"
            :maxlength="MAX_LENGTH"
            auto-size
          />
        </div>

        <div
          class="grid min-w-0 content-start gap-5 transition-opacity motion-reduce:transition-none"
          :class="isChecking && 'opacity-60'"
          :aria-busy="isChecking"
        >
          <p
            class="sr-only"
            role="status"
            aria-live="polite"
          >
            {{ summary }}
          </p>
          <Alert
            v-if="scope === 'glossary' && !enabled"
            icon="lucide:power-off"
          >
            <span class="text-sm">{{ $t('glossaryImport.editor.tryText.off') }}</span>
          </Alert>
          <Alert
            v-if="failure"
            icon="lucide:triangle-alert"
            color="destructive"
          >
            <span class="text-sm">{{ failure }}</span>
          </Alert>

          <p
            v-if="!checked && !failure && !isChecking"
            class="text-sm text-muted"
          >
            {{ $t('glossaryImport.editor.tryText.empty') }}
          </p>

          <template v-if="checked">
            <section class="grid gap-2">
              <h3 class="text-sm font-semibold text-primary">{{ $t('glossaryImport.editor.tryText.found') }}</h3>
              <p class="text-sm wrap-anywhere whitespace-pre-wrap text-primary">
                <template
                  v-for="segment in segments"
                  :key="segment.start"
                >
                  <mark
                    v-if="segment.matches.length > 0"
                    class="rounded-sm bg-chip px-0.5 text-chip-foreground underline decoration-2 underline-offset-2"
                  >{{ segment.text }}</mark>
                  <template v-else>{{ segment.text }}</template>
                </template>
              </p>
            </section>

            <section
              v-if="findings"
              class="grid gap-2"
            >
              <h3 class="text-sm font-semibold text-primary">{{ $t('glossaryImport.editor.tryText.findings') }}</h3>
              <p
                v-if="findings.length === 0"
                class="flex items-center gap-1.5 text-sm text-muted"
              >
                <Icon name="lucide:circle-check" />
                {{ $t('glossaryImport.editor.tryText.clean') }}
              </p>
              <ul
                v-else
                class="grid gap-1.5 text-sm"
              >
                <li
                  v-for="(finding, index) in findings"
                  :key="index"
                  class="flex items-start gap-1.5"
                  :class="isGlossaryHint(finding) ? 'text-muted' : 'text-primary'"
                >
                  <Icon
                    :name="isGlossaryHint(finding) ? 'lucide:info' : 'lucide:octagon-alert'"
                    class="mt-0.5 shrink-0"
                    :class="!isGlossaryHint(finding) && 'text-destructive'"
                  />
                  {{ findingText(finding) }}
                </li>
              </ul>
            </section>

            <section class="grid gap-2">
              <h3 class="text-sm font-semibold text-primary">{{ $t('glossaryImport.editor.tryText.terms') }}</h3>
              <p
                v-if="terms.length === 0"
                class="text-sm text-muted"
              >
                {{ $t('glossaryImport.editor.tryText.noTerms') }}
              </p>
              <ul class="-mx-2 grid gap-1">
                <li
                  v-for="term in terms"
                  :key="`${term.row_id}:${term.term}`"
                >
                  <p
                    v-if="term.glossary_id !== glossaryId"
                    class="p-2 text-sm text-primary"
                  >
                    {{ term.instruction }}
                    <span class="block text-muted">{{ $t('glossaryImport.editor.tryText.fromGlossary', { glossary: term.glossary_name ?? '' }) }}</span>
                  </p>
                  <button
                    v-else
                    type="button"
                    class="w-full cursor-pointer rounded-lg p-2 text-left text-sm text-primary transition-colors hover:bg-tree-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
                    @click="openTerm(term.row_id)"
                  >
                    {{ term.instruction }}
                  </button>
                </li>
              </ul>
            </section>
          </template>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
