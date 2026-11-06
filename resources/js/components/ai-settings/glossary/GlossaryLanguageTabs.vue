<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { TabsList, TabsTrigger } from '~/components/ui/tabs'
import type { LanguageMark } from '~/lib/glossaryConcepts'

/** No mark means the language has its wording, so only what needs a look carries an icon. */
const MARK_ICONS: Partial<Record<LanguageMark, string>> = {
  fallback: 'lucide:corner-down-right',
  missing: 'lucide:circle-dashed',
  removed: 'lucide:circle-minus',
}

/**
 * The language tabs: full names on wide screens, codes on a phone so they don't push the
 * wording down. A team glossary adds a language by code, behind a button.
 */
defineProps<{
  used: readonly string[]
  unused: readonly string[]
  canAdd: boolean
  addError: string | null
  markOf: (code: string) => LanguageMark
  isDirty: (code: string) => boolean
  languageLabel: (code: string) => string
}>()

const emit = defineEmits<{ add: [code: string] }>()

const adding = ref(false)
const newCode = ref('')

const submit = () => {
  emit('add', newCode.value.trim())
  newCode.value = ''
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
    <TabsList
      v-for="group in [
        { key: 'used', codes: used, label: $t('glossaryImport.editor.languages.label') },
        { key: 'unused', codes: unused, label: $t('glossaryImport.editor.unused') },
      ].filter(({ codes }) => codes.length > 0)"
      :key="group.key"
      :aria-label="group.label"
      class="h-auto flex-wrap overflow-visible! mask-none! [animation:none]!"
    >
      <span
        v-if="group.key === 'unused'"
        class="px-2 text-sm text-muted"
      >{{ group.label }}</span>
      <TabsTrigger
        v-for="code in group.codes"
        :key="code"
        :value="code"
        :aria-label="languageLabel(code)"
        class="min-h-7"
      >
        <Icon
          v-if="MARK_ICONS[markOf(code)]"
          :name="MARK_ICONS[markOf(code)] ?? ''"
          :class="markOf(code) === 'missing' && group.key === 'used' ? 'text-warning-ink' : 'text-muted'"
        />
        <span class="hidden sm:inline">{{ languageLabel(code) }}</span>
        <span class="sm:hidden">{{ code }}</span>
        <span
          v-if="markOf(code) !== 'filled'"
          class="sr-only"
        >{{ $t(`glossaryImport.editor.marks.${markOf(code)}`) }}</span>
        <span
          v-if="isDirty(code)"
          class="text-warning-ink"
          :title="$t('glossaryImport.editor.unsaved')"
        >*<span class="sr-only">{{ $t('glossaryImport.editor.unsaved') }}</span></span>
      </TabsTrigger>
    </TabsList>
    <Button
      v-if="canAdd && !adding"
      variant="ghost"
      size="sm"
      @click="adding = true"
    >
      <Icon name="lucide:plus" />
      {{ $t('glossaryImport.editor.languages.add') }}
    </Button>
  </div>
  <div
    v-if="canAdd && adding"
    class="flex items-end gap-2"
  >
    <InputField
      id="glossary-new-language"
      v-model="newCode"
      name="glossary-new-language"
      :label="$t('glossaryImport.editor.languages.add')"
      :tooltip="$t('glossaryImport.editor.languages.addHint')"
      :error="addError"
      placeholder="de-AT"
      class="max-w-48"
      @keydown.enter.prevent="submit"
    />
    <Button
      variant="outline"
      @click="submit"
    >
      {{ $t('actions.add') }}
    </Button>
  </div>
</template>
