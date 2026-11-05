<script setup lang="ts">
import { Checkbox } from '~/components/ui/checkbox'
import { Textarea } from '~/components/ui/textarea'
import { NOTE_MAX_LENGTH, noteTooLong, type ConceptForm } from '~/lib/glossaryConcepts'

import GlossaryField from './GlossaryField.vue'

/** What holds for the row in every language. */
const props = defineProps<{
  form: ConceptForm
  baseline: ConceptForm
}>()

const tooLong = computed(() => noteTooLong(props.form, props.baseline))
</script>

<template>
  <section class="grid gap-4 border-t border-border pt-5">
    <GlossaryField
      id="glossary-concept-note"
      :label="$t('glossaryImport.editor.fields.note')"
      :hint="$t('glossaryImport.editor.fields.noteHint')"
    >
      <template #default="{ describedBy }">
        <Textarea
          id="glossary-concept-note"
          v-model="form.note"
          :aria-describedby="`${describedBy} glossary-concept-note-count`"
          :aria-invalid="tooLong || undefined"
          :rows="2"
          auto-size
        />
        <p
          id="glossary-concept-note-count"
          class="flex flex-wrap justify-between gap-x-3 text-sm"
          :class="tooLong ? 'text-warning-ink' : 'text-muted'"
        >
          <span
            :class="!tooLong && 'sr-only'"
            role="status"
          >{{ tooLong ? $t('glossaryImport.editor.fields.noteTooLong', { max: NOTE_MAX_LENGTH }) : '' }}</span>
          <span class="ml-auto tabular-nums">{{ form.note.trim().length }} / {{ NOTE_MAX_LENGTH }}</span>
        </p>
      </template>
    </GlossaryField>

    <div class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-1">
      <Checkbox
        id="glossary-concept-keep-exact"
        :model-value="form.keep_exact"
        aria-describedby="glossary-concept-keep-exact-hint"
        @update:model-value="(value) => (form.keep_exact = value === true)"
      />
      <label
        for="glossary-concept-keep-exact"
        class="cursor-pointer font-semibold text-primary"
      >{{ $t('glossaryImport.editor.fields.keepExact') }}</label>
      <p
        id="glossary-concept-keep-exact-hint"
        class="col-start-2 text-sm text-muted"
      >
        {{ $t('glossaryImport.editor.fields.keepExactHint') }}
      </p>
    </div>
  </section>
</template>
