<script setup lang="ts">
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AiGlossaryExportFormat, AiOwner } from '~/api/resources/ai-knowledge'
import GlossaryConceptList from '~/components/ai-settings/glossary/GlossaryConceptList.vue'
import GlossaryConceptSheet from '~/components/ai-settings/glossary/GlossaryConceptSheet.vue'
import GlossaryTryText from '~/components/ai-settings/glossary/GlossaryTryText.vue'
import { NEW_CONCEPT, useGlossaryConceptList } from '~/components/ai-settings/glossary/useGlossaryConceptList'
import GlossarySheetImportDialog from '~/components/ai-settings/glossary-import/GlossarySheetImportDialog.vue'
import Icon from '~/components/Icon.vue'
import { Alert } from '~/components/ui/alert'
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
import { useAlertDialog } from '~/composables/useAlertDialog'
import { aiSettingsRoutes, isOwnedBy } from '~/lib/aiSettingsRoutes'
import { languageName } from '~/lib/languageNames'

/**
 * One glossary: its concepts, one row per meaning across languages, a sheet to edit
 * one, and a dialog to try a text. Space and team routes share it.
 */
const props = defineProps<{
  owner: AiOwner
  glossaryId: string
  canManage: boolean
}>()

const { t, locale } = useI18n()
const router = useRouter()
const { alert } = useAlertDialog()
const knowledge = useAiKnowledge(() => props.owner)
const routes = computed(() => aiSettingsRoutes(props.owner))

const { data: glossaries, isLoading: isLoadingGlossary } = knowledge.useGlossariesQuery()
const glossary = computed(() => glossaries.value?.find((item) => item.id === props.glossaryId) ?? null)
const owned = computed(() => !!glossary.value && isOwnedBy(glossary.value, props.owner))
const canEdit = computed(() => props.canManage && owned.value)

const { mutateAsync: deleteGlossary } = knowledge.useDeleteGlossaryMutation()

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => (props.owner.kind === 'space' ? props.owner.id : null))
const spaceLanguages = computed(() => space.value?.settings?.languages)

const languageLabel = (code: string) => languageName(code, spaceLanguages.value, locale.value)

const list = useGlossaryConceptList(
  () => props.owner,
  () => props.glossaryId
)
const locales = computed(() => list.meta.value?.available_locales ?? [])

useSeoMeta({
  title: computed(() => glossary.value?.name ?? t('components.aiSettings.knowledge.types.glossary')),
})

const listPanel = ref<InstanceType<typeof GlossaryConceptList> | null>(null)
const sheet = ref<InstanceType<typeof GlossaryConceptSheet> | null>(null)
const tryText = ref<InstanceType<typeof GlossaryTryText> | null>(null)
const prefill = ref('')
const hasConcepts = computed(() => (list.meta.value?.facets.total ?? 0) > 0)

/** What opened the sheet from outside the list, so focus can go back there. */
let opener: HTMLElement | null = null
const rememberOpener = () => {
  const active = document.activeElement
  if (!list.concept.value) opener = active instanceof HTMLElement && !active.matches('[data-table-row], body') ? active : null
}

/** The one way to change the open concept, so unsaved changes are asked about first. */
const openConcept = async (rowId: string) => {
  if (rowId === list.concept.value) return
  rememberOpener()
  if (await (sheet.value?.confirmLeave() ?? true)) list.concept.value = rowId
}

const createConcept = async (wording = '') => {
  rememberOpener()
  if (!(await (sheet.value?.confirmLeave() ?? true))) return
  prefill.value = wording
  list.concept.value = NEW_CONCEPT
}

const onSheetClosed = (rowId: string | null) => {
  void nextTick(() => {
    if (opener?.isConnected) opener.focus()
    else if (rowId) listPanel.value?.focusConcept(rowId)
    opener = null
  })
}

/**
 * Moves along the list. A saved row can leave a filtered list, and the next one then
 * slides into its place, so the position from before the save decides.
 */
const stepConcept = async (delta: 1 | -1, rowId: string | null, index: number) => {
  const rows = list.rows.value
  const current = rows.findIndex(({ id }) => id === rowId)
  const target = current >= 0 ? rows[current + delta] : rows[delta === 1 ? index : index - 1]
  if (target) {
    list.concept.value = target.id
    return
  }

  const last = list.meta.value?.last_page ?? 1
  const page = list.page.value + delta
  if (page < 1 || page > last) {
    toast.info(t('glossaryImport.editor.endOfList'))
    return
  }

  await list.goToPage(page)
  const next = delta === 1 ? list.rows.value[0] : list.rows.value[list.rows.value.length - 1]
  if (next) list.concept.value = next.id
}

const sheetDialogOpen = ref(false)

const removeGlossary = async () => {
  const current = glossary.value
  if (!current) return
  if (await alert.confirm(t('components.aiSettings.glossaries.confirmDelete', { name: current.name }))) {
    await deleteGlossary(current.id)
    void router.push(routes.value.knowledge)
  }
}

const exportFormats: AiGlossaryExportFormat[] = ['csv', 'xlsx', 'tbx']
const templateUrl = (format: 'csv' | 'xlsx') =>
  api.aiKnowledge(props.owner).exportUrl(props.glossaryId, format, { template: true })
</script>

<template>
  <div class="grid min-w-0 content-start gap-4">
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
            v-if="canEdit && list.meta.value?.facets.total !== 0"
            variant="primary"
            @click="createConcept()"
          >
            <Icon name="lucide:plus" />
            {{ $t('glossaryImport.editor.add') }}
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
              <template v-if="canEdit">
                <DropdownMenuItem @select="sheetDialogOpen = true">
                  <Icon name="lucide:upload" />
                  {{ $t('glossaryImport.sheet.action') }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
              </template>
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
              <template v-if="hasConcepts">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="tryText?.start()">
                  <Icon name="lucide:text-search" />
                  {{ $t('glossaryImport.editor.tryText.title') }}
                </DropdownMenuItem>
              </template>
              <template v-if="canEdit">
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
        </div>
      </template>
    </ContentHeader>

    <Alert
      v-if="glossary && !owned"
      icon="lucide:lock"
    >
      <span class="text-sm">{{ glossary.owner_name ? $t('glossaryImport.inheritedFrom', { owner: glossary.owner_name }) : $t('glossaryImport.inherited') }}</span>
    </Alert>
    <Alert
      v-else-if="glossary && !canEdit"
      icon="lucide:lock"
    >
      <span class="text-sm">{{ $t('glossaryImport.editor.noPermission') }}</span>
    </Alert>

    <GlossaryConceptList
      v-if="glossary || isLoadingGlossary"
      ref="listPanel"
      :list="list"
      :can-edit="canEdit"
      :language-label="languageLabel"
      :export-template-url="templateUrl"
      @open="openConcept"
      @create="createConcept"
      @import-sheet="sheetDialogOpen = true"
    />

    <GlossaryConceptSheet
      ref="sheet"
      :list="list"
      :owner="owner"
      :can-edit="canEdit"
      :owner-name="owned ? null : (glossary?.owner_name ?? null)"
      :prefill="prefill"
      :language-label="languageLabel"
      @closed="onSheetClosed"
      @step="stepConcept"
      @open-concept="openConcept"
      @try-text="tryText?.start($event)"
    />

    <GlossaryTryText
      ref="tryText"
      :list="list"
      :glossary-id="glossaryId"
      :can-check-space="owner.kind === 'space'"
      :enabled="glossary?.enabled ?? true"
      :locales="locales"
      :default-locale="list.meta.value?.default_locale ?? null"
      :language-label="languageLabel"
      @open-concept="openConcept"
    />

    <GlossarySheetImportDialog
      v-if="canEdit"
      v-model:open="sheetDialogOpen"
      :owner="owner"
      :glossary-id="glossaryId"
    />
  </div>
</template>
