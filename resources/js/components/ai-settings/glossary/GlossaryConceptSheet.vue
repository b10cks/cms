<script setup lang="ts">
import { toast } from 'vue-sonner'

import type {
  AiGlossaryConceptConflict,
  AiGlossaryConceptRow,
  AiOwner,
} from '~/api/resources/ai-knowledge'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '~/components/ui/sheet'
import { Tabs, TabsContent } from '~/components/ui/tabs'
import { useAiEnabled } from '~/composables/useAiEnabled'
import { useAiErrorToast } from '~/composables/useAiErrorToast'
import { useAiRun } from '~/composables/useAiRun'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { useShortcut } from '~/composables/useShortcuts'
import {
  addLanguageSlot,
  applyAiFill,
  conceptFormError,
  conceptFormOf,
  conceptFormOnto,
  conceptPayload,
  conceptTitle,
  conceptTitleLocale,
  conceptWriteError,
  fillableUse,
  isValidLocaleCode,
  languageMark,
  normalizedLocale,
  noteTooLong,
  type ConceptForm,
} from '~/lib/glossaryConcepts'
import { glossaryFindingText, type AiGlossaryFinding } from '~/lib/aiRun'
import { formatKeys } from '~/lib/shortcuts'
import { errorMessage } from '~/lib/toast-error'
import { useUnsavedChangesGuard } from '~/lib/unsavedChangesGuard'

import GlossaryConceptReadOnly from './GlossaryConceptReadOnly.vue'
import GlossaryConceptShared from './GlossaryConceptShared.vue'
import GlossaryLanguageFields from './GlossaryLanguageFields.vue'
import GlossaryLanguageTabs from './GlossaryLanguageTabs.vue'
import { NEW_CONCEPT, type GlossaryConceptList } from './useGlossaryConceptList'

/** Refusals that show beside their field. The rest go to the alert above Save. */
const INLINE_CONFLICTS = new Set<AiGlossaryConceptConflict['code']>(['wording_conflict', 'use_avoided', 'term_required'])

/**
 * One concept in a side sheet that leaves the list usable beside it. Saving is explicit,
 * because a save changes live AI policy, and nothing typed is lost on any error.
 */
const props = defineProps<{
  list: GlossaryConceptList
  owner: AiOwner
  canEdit: boolean
  /** Who owns this glossary, when that is not who is looking. */
  ownerName: string | null
  /** Wording for a new concept, from the search box. */
  prefill: string
  languageLabel: (code: string) => string
}>()

const emit = defineEmits<{
  /** The sheet closed; focus goes back to this row. */
  closed: [rowId: string | null]
  /** Move along the list. `index` is where the row stood before a save moved it. */
  step: [delta: 1 | -1, rowId: string | null, index: number]
  openConcept: [rowId: string]
  tryText: [prefill: { text: string; locale: string }]
}>()

const { t, locale } = useI18n()
const { alert } = useAlertDialog()
const { showAiError } = useAiErrorToast()

const api = props.list.rowsApi
const createRow = api.useCreateRowMutation()
const updateRow = api.useUpdateRowMutation()
const deleteRow = api.useDeleteRowMutation()

const openId = props.list.concept
const isOpen = computed(() => !!openId.value)
const isNew = computed(() => openId.value === NEW_CONCEPT)

const rowOnPage = computed(() => props.list.rows.value.find(({ id }) => id === openId.value) ?? null)
const fetchedId = computed(() => (openId.value && !isNew.value && !rowOnPage.value ? openId.value : null))
const fetched = api.useRowQuery(fetchedId)
const liveRow = computed<AiGlossaryConceptRow | null>(() => rowOnPage.value ?? fetched.data.value ?? null)

const meta = props.list.meta
const availableLocales = computed(() => meta.value?.available_locales ?? [])
const usedCodes = computed(() => availableLocales.value.filter((locale) => !locale.unused).map(({ code }) => code))
const unusedCodes = computed(() => availableLocales.value.filter((locale) => locale.unused).map(({ code }) => code))
const defaultCode = computed(() => meta.value?.default_locale ?? usedCodes.value[0] ?? null)
const isTeam = computed(() => props.owner.kind === 'team')

/** The row the form was loaded from. Set by hand, so a refetch never replaces typed text. */
const row = shallowRef<AiGlossaryConceptRow | null>(null)
const form = ref<ConceptForm | null>(null)
const baseline = ref<ConceptForm | null>(null)
const loadedId = ref<string | null>(null)
const addedCodes = ref<string[]>([])
const conflict = ref<AiGlossaryConceptConflict | null>(null)
const saveError = ref<string | null>(null)
const wordingRequired = ref<string | null>(null)
/** Per language, the Use the AI wrote, so its line goes away once the field is edited. */
const aiFilled = ref<Record<string, string>>({})
/** What the glossary said about the last suggestion, in the language it was made for. */
const aiFindings = shallowRef<{ code: string; findings: readonly AiGlossaryFinding[] } | null>(null)
const announcement = ref('')

const load = (source: AiGlossaryConceptRow | null) => {
  const codes = [...usedCodes.value, ...unusedCodes.value]
  // A team glossary with no language yet starts in the language of the screen.
  if (codes.length === 0 && !source) addedCodes.value = [locale.value]
  const all = [...codes, ...addedCodes.value]
  aiFindings.value = null
  row.value = source
  form.value = conceptFormOf(source, all)
  baseline.value = conceptFormOf(source, all)
  conflict.value = null
  saveError.value = null
  wordingRequired.value = null
  aiFilled.value = {}
  loadedId.value = openId.value
}

watch(
  [openId, liveRow, meta],
  () => {
    if (!openId.value) {
      form.value = null
      baseline.value = null
      loadedId.value = null
      addedCodes.value = []
      return
    }
    if (loadedId.value === openId.value || !meta.value) return

    if (isNew.value) {
      load(null)
      const target = activeCode.value
      if (props.prefill && target && form.value) form.value.languages[target].use = props.prefill
    } else if (liveRow.value) {
      load(liveRow.value)
    }
  },
  { immediate: true }
)

const tabCodes = computed(() => {
  const stored = Object.keys(form.value?.languages ?? {})
  return [
    ...usedCodes.value,
    ...stored.filter((code) => !usedCodes.value.includes(code) && !unusedCodes.value.includes(code)),
  ]
})

/** The tab in the URL, else the language being filtered on, else the default language. */
const activeCode = computed<string | null>({
  get: () => {
    const available = [...tabCodes.value, ...unusedCodes.value]
    const wanted = props.list.language.value || props.list.missing.value
    if (wanted && available.includes(wanted)) return wanted
    return defaultCode.value && available.includes(defaultCode.value) ? defaultCode.value : (available[0] ?? null)
  },
  set: (value) => {
    props.list.language.value = value ?? ''
  },
})

const referenceCode = computed(() => {
  const code = defaultCode.value ?? tabCodes.value[0] ?? null
  return code && code !== activeCode.value ? code : null
})

const title = computed(() => {
  if (isNew.value) return t('glossaryImport.editor.add')
  return row.value ? conceptTitle(row.value, defaultCode.value) : t('glossaryImport.editor.columns.concept')
})

const payload = computed(() =>
  form.value && baseline.value ? conceptPayload(form.value, baseline.value, row.value) : {}
)
const isDirty = computed(() => !!form.value && Object.keys(payload.value).length > 0)
const isSaving = computed(() => createRow.isPending.value || updateRow.isPending.value)

const markOf = (code: string) => (form.value ? languageMark(form.value, code, row.value) : 'missing')
const isDirtyTab = (code: string) => !!payload.value.languages?.[code] || !!payload.value.remove_languages?.includes(code)

const wordingErrorFor = (code: string) => (wordingRequired.value === code ? t('glossaryImport.editor.fields.wordingRequired') : null)

/** Adds what is still in a chips input before the form is read. */
const settleInputs = async () => {
  const active = document.activeElement
  if (active instanceof HTMLInputElement) {
    active.blur()
    await nextTick()
    active.focus()
  }
}

/**
 * Moves the form onto the row as stored now, and returns the sentence about that same row,
 * the one the next Save sends as its base. If it can't be read the old base stays, so a
 * further Save is refused again and replaces nothing. Null when the person has moved on.
 */
const moveToNewerRow = async (rowId: string) => {
  const newer = await api.refreshRow(rowId).catch(() => null)
  if (!form.value || !baseline.value || row.value?.id !== rowId) return null
  if (!newer) return t('glossaryImport.editor.fields.rowChangedUnread')

  const { form: moved, baseline: stored, replaced } = conceptFormOnto(form.value, baseline.value, newer)
  form.value = moved
  baseline.value = stored
  row.value = newer
  if (replaced.length === 0) return t('glossaryImport.editor.fields.rowChangedApart')

  return t('glossaryImport.editor.fields.rowChanged', {
    fields: replaced
      .map(({ field, locale: code }) => {
        const label = t(`glossaryImport.editor.fields.${field === 'keep_exact' ? 'keepExact' : field}`)
        return code ? `${label} (${props.languageLabel(code)})` : label
      })
      .join(', '),
  })
}

/** Returns the saved row, or null when nothing went through. The form keeps its text either way. */
const save = async (): Promise<AiGlossaryConceptRow | null | false> => {
  if (!form.value || !props.canEdit) return false
  await settleInputs()
  conflict.value = null
  saveError.value = null
  wordingRequired.value = null

  if (!isDirty.value) return row.value

  if (baseline.value && noteTooLong(form.value, baseline.value)) {
    document.getElementById('glossary-concept-note')?.focus()
    return false
  }

  const invalid = conceptFormError(form.value, row.value)
  if (invalid) {
    if (invalid.code === 'wording_required') {
      wordingRequired.value = invalid.locale
      activeCode.value = invalid.locale
    } else {
      saveError.value = t('glossaryImport.editor.fields.noLanguage')
    }
    return false
  }

  try {
    const wasNew = isNew.value
    const saved = row.value
      ? await updateRow.mutateAsync({
          rowId: row.value.id,
          payload: {
            ...payload.value,
            base: { keep_exact: row.value.keep_exact, note: row.value.note, languages: row.value.languages },
          },
        })
      : await createRow.mutateAsync(payload.value)

    const message = wasNew
      ? t('glossaryImport.editor.created', { term: conceptTitle(saved, defaultCode.value) })
      : t('glossaryImport.editor.saved', { term: conceptTitle(saved, defaultCode.value) })
    toast.success(message)
    announcement.value = message
    openId.value = saved.id
    load(saved)
    return saved
  } catch (error) {
    const written = conceptWriteError(error)
    if (written.kind === 'conflict') {
      conflict.value = written.conflict
      if (written.conflict.locale) {
        const target = [...tabCodes.value, ...unusedCodes.value].find(
          (code) => normalizedLocale(code) === normalizedLocale(written.conflict.locale ?? '')
        )
        if (target) activeCode.value = target
      }
      if (written.conflict.code === 'term_required') wordingRequired.value = activeCode.value
      if (written.conflict.code === 'row_changed' && row.value) saveError.value = await moveToNewerRow(row.value.id)
      else saveError.value = INLINE_CONFLICTS.has(written.conflict.code) ? null : written.conflict.message
    } else if (written.kind === 'validation') {
      saveError.value = Object.values(written.fields).flat().join(' ') || written.message
    } else {
      saveError.value = written.message
    }
    announcement.value = saveError.value ?? t('glossaryImport.editor.fields.refused')
    return false
  }
}

const onSave = async () => {
  await save()
}

const rowIndex = computed(() => props.list.rows.value.findIndex(({ id }) => id === openId.value))

const saveAndNext = async () => {
  const index = rowIndex.value
  const saved = await save()
  if (saved === false) return
  emit('step', 1, saved?.id ?? null, index)
}

const saveAndAddAnother = async () => {
  const saved = await save()
  if (saved === false) return
  openId.value = NEW_CONCEPT
  loadedId.value = null
  load(null)
  await nextTick()
  focusWording()
}

/** Asks before unsaved changes go away. True means carry on. */
const confirmLeave = async (): Promise<boolean> => {
  if (!isDirty.value) return true

  let choice: 'stay' | 'discard' | 'save' = 'stay'
  await alert.dialog({
    title: t('labels.unsavedChanges.title'),
    message: t('labels.unsavedChanges.messageWithSave'),
    actions: [
      { type: 'cancel', label: t('labels.unsavedChanges.stay') },
      { type: 'destructive', label: t('labels.unsavedChanges.discard'), click: () => { choice = 'discard' } },
      { type: 'primary', label: t('labels.unsavedChanges.save'), click: () => { choice = 'save' } },
    ],
  })
  if (choice === 'stay') return false
  if (choice === 'save') return (await save()) !== false

  if (baseline.value) form.value = JSON.parse(JSON.stringify(baseline.value)) as ConceptForm
  return true
}

useUnsavedChangesGuard({
  isDirty,
  onSave: async () => (await save()) !== false,
})

const requestClose = async () => {
  if (!(await confirmLeave())) return
  const closing = openId.value
  void run.cancel()
  openId.value = ''
  props.list.language.value = ''
  emit('closed', closing === NEW_CONCEPT ? null : closing)
}

const step = async (delta: 1 | -1) => {
  if (await confirmLeave()) emit('step', delta, row.value?.id ?? null, rowIndex.value)
}

const removeConcept = async () => {
  if (!row.value) return
  const confirmed = await alert.confirm(t('glossaryImport.editor.confirmDelete', { term: title.value }), {
    title: t('glossaryImport.editor.delete', { term: title.value }),
    variant: 'destructive',
    confirmLabel: t('actions.delete'),
  })
  if (!confirmed) return

  try {
    await deleteRow.mutateAsync(row.value.id)
    toast.success(t('glossaryImport.editor.deleted', { term: title.value }))
    const closing = row.value.id
    if (baseline.value) form.value = JSON.parse(JSON.stringify(baseline.value)) as ConceptForm
    openId.value = ''
    emit('closed', closing)
  } catch (error) {
    saveError.value = errorMessage(error)
  }
}

const removeLanguage = (code: string) => {
  form.value?.removed.push(code)
}
const restoreLanguage = (code: string) => {
  if (form.value) form.value.removed = form.value.removed.filter((value) => value !== code)
}

const newCodeError = ref<string | null>(null)
const addLanguage = (code: string) => {
  if (!isValidLocaleCode(code)) {
    newCodeError.value = t('glossaryImport.editor.languages.invalid')
    return
  }
  newCodeError.value = null
  const added = normalizedLocale(code)
  const known = Object.keys(form.value?.languages ?? {}).find((value) => normalizedLocale(value) === added)
  if (!known && form.value) {
    addLanguageSlot(form.value, added)
    if (baseline.value) addLanguageSlot(baseline.value, added)
    addedCodes.value = [...addedCodes.value, added]
  }
  activeCode.value = known ?? added
}

const spaceId = computed(() => (props.owner.kind === 'space' ? props.owner.id : null))
const aiEnabled = useAiEnabled(spaceId)
const run = useAiRun(() => spaceId.value ?? '')
const isFilling = computed(() => run.status.value === 'running' || run.status.value === 'background')

const fillable = computed(() => {
  const source = referenceCode.value ? form.value?.languages[referenceCode.value] : undefined
  const target = activeCode.value ? form.value?.languages[activeCode.value] : undefined
  return fillableUse(source, target)
})
const aiFindingLines = computed(() => {
  const current = aiFindings.value
  if (!current || current.code !== activeCode.value || !isAiFilled(current.code)) return []
  return current.findings.map((finding) => glossaryFindingText(finding, (key, params) => t(key, params)))
})

const fillWithAi = async () => {
  const source = referenceCode.value
  const target = activeCode.value
  if (!source || !target || !fillable.value) return
  aiFindings.value = null

  const startedFor = loadedId.value
  const outcome = await run.start<Record<string, unknown>>({
    surface: 'translate',
    skill: 'translate-fields',
    context: { source, target, fields: { use: fillable.value } },
  })

  if (outcome.status === 'failed') {
    showAiError(outcome.error?.reason, outcome.error?.message)
    return
  }
  const language = form.value?.languages[target]
  if (outcome.status !== 'completed' || !language || loadedId.value !== startedFor) return

  if (applyAiFill(language, outcome.result?.data)) {
    aiFilled.value = { ...aiFilled.value, [target]: language.use }
    announcement.value = t('glossaryImport.editor.ai.filled')
    aiFindings.value = { code: target, findings: outcome.result?.glossary_findings ?? [] }
  }
}

const isAiFilled = (code: string) => {
  const filled = aiFilled.value[code]
  return !!filled && form.value?.languages[code]?.use === filled
}

/** What a save does, in words, where the Save button is. */
const scopeNotice = computed(() => t(isTeam.value ? 'glossaryImport.editor.scope.team' : 'glossaryImport.editor.scope.space'))

/** Set when the row goes by another language because the default language has no wording. */
const titleFallback = computed(() => {
  const current = row.value
  const code = current ? conceptTitleLocale(current, defaultCode.value) : null
  return code && defaultCode.value && code !== defaultCode.value
    ? t('glossaryImport.editor.noDefaultWording', { missing: props.languageLabel(defaultCode.value), language: props.languageLabel(code) })
    : null
})

/**
 * The wording this concept goes by in the open language, for trying it in a text.
 * The check reads saved rules, so unsaved changes are saved first.
 */
const tryThisConcept = async () => {
  if (isDirty.value && (await save()) === false) return
  const code = activeCode.value
  const language = code ? form.value?.languages[code] : undefined
  const wording = language?.use.trim() || language?.avoid[0]
  if (code && wording) {
    emit('tryText', { text: wording, locale: code })
    return
  }
  const current = row.value
  const named = current ? conceptTitleLocale(current, defaultCode.value) : null
  if (current && named) emit('tryText', { text: conceptTitle(current, defaultCode.value), locale: named })
}

const canNavigate = computed(() => !isNew.value && rowIndex.value >= 0)
const fields = ref<{ focusWording: () => void } | null>(null)
const heading = ref<HTMLElement | null>(null)
/** Phones focus the title, so opening a concept doesn't throw up the keyboard over it. */
const focusWording = () => {
  if (props.canEdit && !window.matchMedia('(pointer: coarse)').matches) fields.value?.focusWording()
  else heading.value?.focus()
}

watch(
  [loadedId, activeCode],
  async ([id], [previousId]) => {
    if (id && id !== previousId) {
      await nextTick()
      focusWording()
    }
  },
  { flush: 'post' }
)

useShortcut({
  keys: 'mod+s',
  scope: 'glossary-concept',
  description: () => t('glossaryImport.editor.save'),
  allowInInput: true,
  allowInOverlay: true,
  enabled: () => isOpen.value && props.canEdit,
  handler: onSave,
})

useShortcut({
  keys: 'mod+enter',
  scope: 'glossary-concept',
  description: () => t('glossaryImport.editor.saveAndNext'),
  allowInInput: true,
  allowInOverlay: true,
  enabled: () => isOpen.value && props.canEdit,
  handler: () => {
    void (isNew.value ? saveAndAddAnother() : saveAndNext())
  },
})

const saveKeys = formatKeys('mod+s').join('')
const nextKeys = formatKeys('mod').join('') + '↵'

defineExpose({ confirmLeave, isDirty })
</script>

<template>
  <Sheet
    :open="isOpen"
    :modal="false"
    @update:open="(value) => { if (!value) void requestClose() }"
  >
    <SheetContent
      side="right"
      class="flex w-full! flex-col gap-0! p-0! motion-reduce:animate-none! sm:max-w-none! lg:w-176! [&>button]:top-3.5 [&>button]:right-3.5 [&>button]:p-1"
      @open-auto-focus.prevent="focusWording"
      @close-auto-focus.prevent
      @interact-outside.prevent
      @escape-key-down.prevent="requestClose"
    >
      <header class="flex min-h-14 shrink-0 items-center gap-2 border-b border-border py-2 pr-12 pl-5">
        <div class="min-w-0 flex-1">
          <SheetTitle class="min-w-0">
            <span
              ref="heading"
              tabindex="-1"
              class="block truncate text-panel font-semibold text-primary outline-none"
            >{{ title }}</span>
          </SheetTitle>
          <SheetDescription
            class="truncate text-sm text-muted"
            :class="!row?.key && !titleFallback && canEdit && 'sr-only'"
          >
            <template v-if="!canEdit">{{ ownerName ? $t('glossaryImport.editor.readOnlyOwner', { owner: ownerName }) : $t('glossaryImport.editor.readOnlySheet') }}</template>
            <template v-else-if="titleFallback">{{ titleFallback }}</template>
            <template v-else-if="row?.key">{{ $t('glossaryImport.editor.key', { key: row.key }) }}</template>
            <template v-else>{{ $t('glossaryImport.editor.sheetDescription') }}</template>
          </SheetDescription>
        </div>
        <span
          v-if="isDirty"
          class="shrink-0 text-sm font-semibold text-warning-ink"
        >{{ $t('glossaryImport.editor.unsaved') }}</span>
        <Button
          v-if="canNavigate"
          variant="ghost"
          size="icon"
          :aria-label="$t('glossaryImport.editor.previous')"
          @click="step(-1)"
        >
          <Icon name="lucide:chevron-up" />
        </Button>
        <Button
          v-if="canNavigate"
          variant="ghost"
          size="icon"
          :aria-label="$t('glossaryImport.editor.next')"
          @click="step(1)"
        >
          <Icon name="lucide:chevron-down" />
        </Button>
        <Button
          v-if="canEdit && row"
          variant="ghost"
          size="icon"
          :aria-label="$t('glossaryImport.editor.delete', { term: title })"
          @click="removeConcept"
        >
          <Icon name="lucide:trash-2" />
        </Button>
      </header>

      <div
        v-if="form"
        class="grid min-h-0 flex-1 content-start gap-5 overflow-y-auto p-5"
      >
        <Tabs
          v-if="activeCode"
          v-model="activeCode"
          class="grid min-w-0 gap-4"
        >
          <GlossaryLanguageTabs
            :used="tabCodes"
            :unused="unusedCodes"
            :can-add="isTeam && canEdit"
            :add-error="newCodeError"
            :mark-of="markOf"
            :is-dirty="isDirtyTab"
            :language-label="languageLabel"
            @add="addLanguage"
          />

          <TabsContent
            :value="activeCode"
            class="min-w-0"
            force-mount
          >
            <GlossaryLanguageFields
              v-if="canEdit"
              :key="activeCode"
              ref="fields"
              :list="list"
              :form="form"
              :code="activeCode"
              :row="row"
              :reference-code="referenceCode"
              :server-conflict="conflict"
              :wording-error="wordingErrorFor(activeCode)"
              :ai-filled="isAiFilled(activeCode)"
              :language-label="languageLabel"
              @open-concept="emit('openConcept', $event)"
              @restore="restoreLanguage(activeCode ?? '')"
            >
              <template
                v-if="aiEnabled && referenceCode && fillable"
                #fill
              >
                <Button
                  variant="ai"
                  size="sm"
                  :loading="isFilling"
                  @click="fillWithAi"
                >
                  <Icon name="ai:bot-translate" />
                  {{ $t('glossaryImport.editor.ai.fill', { language: languageLabel(activeCode) }) }}
                </Button>
              </template>
            </GlossaryLanguageFields>
            <GlossaryConceptReadOnly
              v-else-if="row"
              :row="row"
              :code="activeCode"
              :language-label="languageLabel"
            />
          </TabsContent>
          <div
            v-if="aiFindingLines.length > 0"
            class="grid gap-1 text-sm text-warning-ink"
            role="status"
          >
            <p class="font-semibold">{{ $t('glossaryImport.editor.ai.findingsTitle') }}</p>
            <ul class="grid gap-1">
              <li
                v-for="line in aiFindingLines"
                :key="line"
              >
                {{ line }}
              </li>
            </ul>
          </div>
        </Tabs>

        <GlossaryConceptShared
          v-if="canEdit && baseline"
          :form="form"
          :baseline="baseline"
        />

        <div
          v-if="row || isDirty"
          class="-ml-3 flex flex-wrap items-center gap-2"
        >
          <Button
            variant="ghost"
            size="sm"
            :loading="isSaving"
            @click="tryThisConcept"
          >
            <Icon name="lucide:text-search" />
            {{ isDirty ? $t('glossaryImport.editor.tryText.saveAndTry') : $t('glossaryImport.editor.tryText.title') }}
          </Button>
          <Button
            v-if="canEdit && row && activeCode && row.languages[activeCode] && !form.removed.includes(activeCode)"
            variant="ghost"
            size="sm"
            @click="removeLanguage(activeCode)"
          >
            <Icon
              name="lucide:trash-2"
              class="text-destructive"
            />
            {{ $t('glossaryImport.editor.languages.remove', { language: languageLabel(activeCode) }) }}
          </Button>
        </div>
      </div>
      <div
        v-else-if="openId"
        class="flex-1 p-5 text-sm text-muted"
      >
        {{ fetched.isError.value ? $t('glossaryImport.editor.notFound') : $t('labels.loading') }}
      </div>

      <footer
        v-if="canEdit && form"
        class="grid shrink-0 gap-2 border-t border-border px-5 py-3"
      >
        <Alert
          v-if="saveError"
          icon="lucide:triangle-alert"
          color="destructive"
        >
          <span class="text-sm">{{ saveError }}</span>
        </Alert>
        <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
          <p class="mr-auto min-w-48 flex-1 text-sm text-muted">{{ scopeNotice }}</p>
          <Button
            variant="primary"
            :loading="isSaving"
            :disabled="!isDirty"
            @click="onSave"
          >
            {{ $t('glossaryImport.editor.save') }}
            <kbd class="hidden font-sans font-normal opacity-70 md:inline">{{ saveKeys }}</kbd>
          </Button>
          <Button
            v-if="isNew"
            variant="outline"
            :loading="isSaving"
            :disabled="!isDirty"
            @click="saveAndAddAnother"
          >
            {{ $t('glossaryImport.editor.saveAndAdd') }}
            <kbd class="hidden font-sans font-normal text-muted md:inline">{{ nextKeys }}</kbd>
          </Button>
          <Button
            v-else
            variant="outline"
            :loading="isSaving"
            @click="saveAndNext"
          >
            {{ $t('glossaryImport.editor.saveAndNext') }}
            <kbd class="hidden font-sans font-normal text-muted md:inline">{{ nextKeys }}</kbd>
          </Button>
        </div>
      </footer>
      <p
        class="sr-only"
        role="status"
        aria-live="polite"
      >
        {{ announcement }}
      </p>
    </SheetContent>
  </Sheet>
</template>
