<script setup lang="ts">
import { toast } from 'vue-sonner'

import type {
  AiGlossaryExportFormat,
  AiGlossaryTermPayload,
  AiGlossaryTermResource,
  AiOwner,
} from '~/api/resources/ai-knowledge'
import AiGlossaryTermDialog from '~/components/ai-settings/AiGlossaryTermDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { SettingsSection } from '~/components/ui/settings-section'
import { Switch } from '~/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import { useAlertDialog } from '~/composables/useAlertDialog'

const props = defineProps<{
  owner: AiOwner
  canManage: boolean
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const knowledge = useAiKnowledge(() => props.owner)
const { data: glossaries } = knowledge.useGlossariesQuery()
const { mutateAsync: createGlossary } = knowledge.useCreateGlossaryMutation()
const { mutate: updateGlossary } = knowledge.useUpdateGlossaryMutation()
const { mutate: deleteGlossary } = knowledge.useDeleteGlossaryMutation()
const { mutateAsync: saveTerm, isPending: isSavingTerm } = knowledge.useSaveTermMutation()
const { mutate: deleteTerm } = knowledge.useDeleteTermMutation()
const { mutateAsync: importTerms } = knowledge.useImportTermsMutation()

const selectedId = ref<string | null>(null)
const search = ref('')
const newName = ref('')
const { data: terms } = knowledge.useTermsQuery(selectedId, search)

watch(
  glossaries,
  (list) => {
    if (!list?.some((glossary) => glossary.id === selectedId.value)) {
      selectedId.value = list?.[0]?.id ?? null
    }
  },
  { immediate: true }
)

const selected = computed(() => glossaries.value?.find((glossary) => glossary.id === selectedId.value) ?? null)

const addGlossary = async () => {
  if (!newName.value.trim()) return
  selectedId.value = (await createGlossary(newName.value.trim())).data.id
  newName.value = ''
}

const removeGlossary = async () => {
  const glossary = selected.value
  if (glossary && (await alert.confirm(t('components.aiSettings.glossaries.confirmDelete', { name: glossary.name })))) {
    deleteGlossary(glossary.id)
  }
}

const toggleSelected = (enabled: boolean) => {
  if (selected.value) updateGlossary({ id: selected.value.id, payload: { enabled } })
}

const removeTerm = (term: AiGlossaryTermResource) => {
  if (selectedId.value) deleteTerm({ glossaryId: selectedId.value, id: term.id })
}

const termDialogOpen = ref(false)
const editingTerm = ref<AiGlossaryTermResource | null>(null)

const openTerm = (term: AiGlossaryTermResource | null) => {
  editingTerm.value = term
  termDialogOpen.value = true
}

const onSaveTerm = async (payload: AiGlossaryTermPayload) => {
  if (!selectedId.value) return
  await saveTerm({ glossaryId: selectedId.value, id: editingTerm.value?.id ?? null, payload })
  termDialogOpen.value = false
}

const onImport = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !selectedId.value) return

  const { data } = await importTerms({ glossaryId: selectedId.value, file })
  if (data.summary.total_errors > 0) {
    toast.warning(t('components.aiSettings.glossaries.importErrors', { count: data.summary.total_errors }))
  }
}

const exportFormats: AiGlossaryExportFormat[] = ['csv', 'xlsx', 'tbx']
</script>

<template>
  <SettingsSection
    id="glossaries"
    :title="$t('components.aiSettings.glossaries.title')"
    :description="$t('components.aiSettings.glossaries.description')"
    wide
  >
    <div class="flex flex-wrap items-end gap-2">
      <div class="flex flex-wrap gap-1">
        <Button
          v-for="glossary in glossaries ?? []"
          :key="glossary.id"
          type="button"
          size="sm"
          :variant="glossary.id === selectedId ? 'primary' : 'outline'"
          @click="selectedId = glossary.id"
        >
          {{ glossary.name }}
          <span class="text-muted">{{ glossary.terms_count ?? 0 }}</span>
        </Button>
      </div>
      <form
        v-if="canManage"
        class="ml-auto flex items-end gap-2"
        @submit.prevent="addGlossary"
      >
        <InputField
          id="new-glossary"
          name="new-glossary"
          v-model="newName"
          :label="$t('components.aiSettings.glossaries.newName')"
        />
        <Button
          type="submit"
          size="sm"
          variant="primary"
          :disabled="!newName.trim()"
        >
          <Icon name="lucide:plus" />
          {{ $t('components.aiSettings.glossaries.create') }}
        </Button>
      </form>
    </div>

    <template v-if="selected">
      <div class="flex flex-wrap items-center gap-2">
        <InputField
          id="glossary-search"
          name="glossary-search"
          v-model="search"
          class="w-64"
          :label="$t('actions.search')"
        />
        <div class="ml-auto flex flex-wrap items-center gap-2">
          <label class="flex items-center gap-2 text-sm">
            <Switch
              :model-value="selected.enabled"
              :disabled="!canManage"
              @update:model-value="toggleSelected"
            />
            {{ $t('components.aiSettings.columns.enabled') }}
          </label>
          <a
            v-for="format in exportFormats"
            :key="format"
            :href="knowledge.exportUrl(selected.id, format)"
            class="text-sm font-medium text-primary underline-offset-4 hover:underline"
          >
            {{ $t('components.aiSettings.glossaries.export', { format: format.toUpperCase() }) }}
          </a>
          <template v-if="canManage">
            <label class="cursor-pointer text-sm font-medium text-primary underline-offset-4 hover:underline">
              {{ $t('components.aiSettings.glossaries.import') }}
              <input
                type="file"
                class="sr-only"
                accept=".csv,.xlsx,.tbx,.xml"
                @change="onImport"
              />
            </label>
            <Button
              type="button"
              size="sm"
              variant="primary"
              @click="openTerm(null)"
            >
              <Icon name="lucide:plus" />
              {{ $t('components.aiSettings.glossaries.addTerm') }}
            </Button>
            <Button
              type="button"
              size="icon"
              variant="ghost"
              :aria-label="$t('actions.delete')"
              @click="removeGlossary"
            >
              <Icon name="lucide:trash-2" />
            </Button>
          </template>
        </div>
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('components.aiSettings.glossaries.term') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.glossaries.translations') }}</TableHead>
            <TableHead>{{ $t('components.aiSettings.glossaries.rules') }}</TableHead>
            <TableHead class="text-right">{{ $t('components.aiSettings.columns.actions') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="term in terms ?? []"
            :key="term.id"
          >
            <TableCell>
              <div class="font-medium">{{ term.term }}</div>
              <div class="text-sm text-muted">{{ term.source_locale }}</div>
            </TableCell>
            <TableCell class="text-sm">
              {{ Object.entries(term.translations).map(([locale, value]) => `${locale}: ${value}`).join(', ') }}
            </TableCell>
            <TableCell class="text-sm text-muted">
              <span v-if="term.do_not_translate">{{ $t('components.aiSettings.glossaries.doNotTranslate') }}</span>
              <span v-if="term.forbidden.length > 0">
                {{ $t('components.aiSettings.glossaries.neverUse', { terms: term.forbidden.join(', ') }) }}
              </span>
            </TableCell>
            <TableCell class="text-right">
              <div
                v-if="canManage"
                class="flex justify-end gap-1"
              >
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  :aria-label="$t('actions.edit')"
                  @click="openTerm(term)"
                >
                  <Icon name="lucide:pencil" />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  :aria-label="$t('actions.delete')"
                  @click="removeTerm(term)"
                >
                  <Icon name="lucide:trash-2" />
                </Button>
              </div>
            </TableCell>
          </TableRow>
          <TableEmpty
            v-if="(terms ?? []).length === 0"
            :colspan="4"
          >
            {{ $t('components.aiSettings.glossaries.emptyTerms') }}
          </TableEmpty>
        </TableBody>
      </Table>
    </template>
    <p
      v-else
      class="text-sm text-muted"
    >
      {{ $t('components.aiSettings.glossaries.empty') }}
    </p>

    <AiGlossaryTermDialog
      v-model:open="termDialogOpen"
      :term="editingTerm"
      :saving="isSavingTerm"
      @save="onSaveTerm"
    />
  </SettingsSection>
</template>
