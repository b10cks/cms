<script setup lang="ts">
import type { AiGlossaryTermPayload, AiGlossaryTermResource } from '~/api/resources/ai-knowledge'
import { Button } from '~/components/ui/button'
import { CheckboxField, InputField, TextField } from '~/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  term: AiGlossaryTermResource | null
  saving: boolean
}>()

const emit = defineEmits<{
  save: [payload: AiGlossaryTermPayload]
}>()

const form = ref({
  term: '',
  source_locale: 'en',
  translations: '',
  forbidden: '',
  note: '',
  case_sensitive: false,
  do_not_translate: false,
})

watch(open, (isOpen) => {
  if (!isOpen) return
  const term = props.term
  form.value = {
    term: term?.term ?? '',
    source_locale: term?.source_locale ?? 'en',
    translations: Object.entries(term?.translations ?? {})
      .map(([locale, value]) => `${locale}: ${value}`)
      .join('\n'),
    forbidden: (term?.forbidden ?? []).join(', '),
    note: term?.note ?? '',
    case_sensitive: term?.case_sensitive ?? false,
    do_not_translate: term?.do_not_translate ?? false,
  }
})

/** One `locale: term` pair per line. */
const parseTranslations = (value: string): Record<string, string> =>
  Object.fromEntries(
    value
      .split('\n')
      .map((line) => line.split(':'))
      .filter((parts) => parts.length >= 2 && parts[0].trim() !== '')
      .map(([locale, ...rest]) => [locale.trim(), rest.join(':').trim()])
  )

const save = () => {
  emit('save', {
    term: form.value.term,
    source_locale: form.value.source_locale,
    translations: parseTranslations(form.value.translations),
    forbidden: form.value.forbidden
      .split(',')
      .map((value) => value.trim())
      .filter(Boolean),
    note: form.value.note || null,
    case_sensitive: form.value.case_sensitive,
    do_not_translate: form.value.do_not_translate,
  })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-xl!">
      <DialogHeader>
        <DialogTitle>
          {{ term ? $t('components.aiSettings.glossaries.editTerm') : $t('components.aiSettings.glossaries.addTerm') }}
        </DialogTitle>
      </DialogHeader>
      <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
          <InputField
            id="term-term"
            name="term-term"
            v-model="form.term"
            :label="$t('components.aiSettings.glossaries.term')"
            required
          />
          <InputField
            id="term-locale"
            name="term-locale"
            v-model="form.source_locale"
            :label="$t('components.aiSettings.glossaries.sourceLocale')"
            required
          />
        </div>
        <TextField
          id="term-translations"
          name="term-translations"
          v-model="form.translations"
          :label="$t('components.aiSettings.glossaries.translations')"
          :tooltip="$t('components.aiSettings.glossaries.translationsHint')"
          input-class="min-h-24 font-mono text-sm"
        />
        <InputField
          id="term-forbidden"
          name="term-forbidden"
          v-model="form.forbidden"
          :label="$t('components.aiSettings.glossaries.forbidden')"
          :tooltip="$t('components.aiSettings.glossaries.forbiddenHint')"
        />
        <InputField
          id="term-note"
          name="term-note"
          v-model="form.note"
          :label="$t('components.aiSettings.glossaries.note')"
        />
        <CheckboxField
          id="term-dnt"
          name="term-dnt"
          :model-value="form.do_not_translate"
          :label="$t('components.aiSettings.glossaries.doNotTranslate')"
          @update:model-value="(value) => (form.do_not_translate = value === true)"
        />
        <CheckboxField
          id="term-case"
          name="term-case"
          :model-value="form.case_sensitive"
          :label="$t('components.aiSettings.glossaries.caseSensitive')"
          @update:model-value="(value) => (form.case_sensitive = value === true)"
        />
      </div>
      <DialogFooter>
        <Button
          type="button"
          variant="ghost"
          @click="open = false"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          type="button"
          variant="primary"
          :loading="saving"
          @click="save"
        >
          {{ $t('actions.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
