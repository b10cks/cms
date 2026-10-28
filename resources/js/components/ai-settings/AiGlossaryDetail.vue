<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import { toast } from 'vue-sonner'

import type {
  AiGlossaryExportFormat,
  AiGlossaryTermPayload,
  AiGlossaryTermResource,
  AiOwner,
} from '~/api/resources/ai-knowledge'
import AiGlossaryTermDialog from '~/components/ai-settings/AiGlossaryTermDialog.vue'
import AiGlossaryTermsTable from '~/components/ai-settings/AiGlossaryTermsTable.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { Input } from '~/components/ui/input'
import { Panel } from '~/components/ui/panel'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'

/**
 * One glossary: its terms in a table with a column per language. Without
 * `languages` (team glossaries) the columns are the locales the terms use.
 */
const props = defineProps<{
  owner: AiOwner
  glossaryId: string
  canManage: boolean
  languages?: readonly string[]
}>()

const { t } = useI18n()
const router = useRouter()
const { alert } = useAlertDialog()
const knowledge = useAiKnowledge(() => props.owner)
const routes = computed(() => aiSettingsRoutes(props.owner))

const { data: glossaries, isLoading: isLoadingGlossary } = knowledge.useGlossariesQuery()
const glossary = computed(() => glossaries.value?.find((item) => item.id === props.glossaryId) ?? null)

const search = ref('')
const debouncedSearch = refDebounced(search, 250)
const { data: terms, isLoading: isLoadingTerms } = knowledge.useTermsQuery(() => props.glossaryId, debouncedSearch)

const { mutateAsync: saveTerm, isPending: isSavingTerm } = knowledge.useSaveTermMutation()
const { mutate: deleteTerm } = knowledge.useDeleteTermMutation()
const { mutateAsync: importTerms } = knowledge.useImportTermsMutation()
const { mutateAsync: deleteGlossary } = knowledge.useDeleteGlossaryMutation()

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => (props.owner.kind === 'space' ? props.owner.id : null))

const columns = computed(() => {
  if (props.languages) return props.languages
  const locales = new Set((terms.value ?? []).flatMap((term) => Object.keys(term.translations)))
  return [...locales].sort()
})

useSeoMeta({
  title: computed(() => glossary.value?.name ?? t('components.aiSettings.knowledge.types.glossary')),
})

const termDialogOpen = ref(false)
const editingTerm = ref<AiGlossaryTermResource | null>(null)

const openTerm = (term: AiGlossaryTermResource | null) => {
  editingTerm.value = term
  termDialogOpen.value = true
}

const onSaveTerm = async (payload: AiGlossaryTermPayload) => {
  await saveTerm({ glossaryId: props.glossaryId, id: editingTerm.value?.id ?? null, payload })
  termDialogOpen.value = false
}

const removeTerm = async (term: AiGlossaryTermResource) => {
  if (await alert.confirm(t('components.aiSettings.glossaries.confirmDeleteTerm', { term: term.term }))) {
    deleteTerm({ glossaryId: props.glossaryId, id: term.id })
  }
}

const fileInput = ref<HTMLInputElement | null>(null)

const onImport = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  const { data } = await importTerms({ glossaryId: props.glossaryId, file })
  if (data.summary.total_errors > 0) {
    toast.warning(t('components.aiSettings.glossaries.importErrors', { count: data.summary.total_errors }))
  }
}

const removeGlossary = async () => {
  const current = glossary.value
  if (!current) return
  if (await alert.confirm(t('components.aiSettings.glossaries.confirmDelete', { name: current.name }))) {
    await deleteGlossary(current.id)
    void router.push(routes.value.knowledge)
  }
}

const exportFormats: AiGlossaryExportFormat[] = ['csv', 'xlsx', 'tbx']
</script>

<template>
  <div class="grid min-w-0 gap-4">
    <ContentHeader
      :header="glossary?.name ?? (isLoadingGlossary ? '' : $t('components.aiSettings.glossaries.notFound'))"
      :description="$t('components.aiSettings.glossaries.description')"
    >
      <template #start>
        <RouterLink
          :to="routes.knowledge"
          class="flex w-fit items-center gap-2 rounded-sm text-sm text-muted hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
          <Icon name="lucide:chevron-left" />
          {{ $t('components.aiSettings.knowledge.back') }}
        </RouterLink>
      </template>
      <template
        v-if="glossary"
        #actions
      >
        <div class="flex items-center gap-2">
          <Button
            v-if="canManage"
            variant="primary"
            @click="openTerm(null)"
          >
            <Icon name="lucide:plus" />
            {{ $t('components.aiSettings.glossaries.addTerm') }}
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button
                variant="outline"
                size="icon"
                :aria-label="$t('actions.moreActions')"
              >
                <Icon name="lucide:ellipsis" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                v-if="canManage"
                @select="fileInput?.click()"
              >
                <Icon name="lucide:upload" />
                {{ $t('components.aiSettings.glossaries.import') }}
              </DropdownMenuItem>
              <DropdownMenuSeparator v-if="canManage" />
              <DropdownMenuLabel>{{ $t('components.aiSettings.glossaries.exportLabel') }}</DropdownMenuLabel>
              <DropdownMenuItem
                v-for="format in exportFormats"
                :key="format"
                as-child
              >
                <a
                  :href="knowledge.exportUrl(glossary.id, format)"
                  download
                >
                  <Icon name="lucide:download" />
                  {{ format.toUpperCase() }}
                </a>
              </DropdownMenuItem>
              <template v-if="canManage">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  class="text-destructive"
                  @select="removeGlossary"
                >
                  <Icon name="lucide:trash-2" />
                  {{ $t('components.aiSettings.glossaries.delete') }}
                </DropdownMenuItem>
              </template>
            </DropdownMenuContent>
          </DropdownMenu>
          <input
            ref="fileInput"
            type="file"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            accept=".csv,.xlsx,.tbx,.xml"
            @change="onImport"
          />
        </div>
      </template>
    </ContentHeader>

    <Panel
      v-if="glossary || isLoadingGlossary"
      id="glossary-terms"
      :title="$t('components.aiSettings.glossaries.terms')"
      :meta="glossary ? $t('components.aiSettings.glossaries.termCount', { count: glossary.terms_count ?? 0 }) : undefined"
      flush
    >
      <template #actions>
        <Input
          v-model="search"
          type="search"
          class="w-56"
          :placeholder="$t('components.aiSettings.glossaries.search')"
          :aria-label="$t('components.aiSettings.glossaries.search')"
        />
      </template>
      <AiGlossaryTermsTable
        :terms="terms ?? []"
        :languages="columns"
        :space-languages="space?.settings?.languages"
        :can-manage="canManage"
        :loading="isLoadingGlossary || isLoadingTerms"
        @edit="openTerm"
        @delete="removeTerm"
      />
    </Panel>

    <AiGlossaryTermDialog
      v-model:open="termDialogOpen"
      :term="editingTerm"
      :saving="isSavingTerm"
      @save="onSaveTerm"
    />
  </div>
</template>
