<script setup lang="ts">
import type {
  AiKnowledgeDocumentPayload,
  AiKnowledgeDocumentResource,
  AiOwner,
} from '~/api/resources/ai-knowledge'
import AiKnowledgeDocumentDialog from '~/components/ai-settings/AiKnowledgeDocumentDialog.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
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
const { data: documents } = knowledge.useDocumentsQuery()
const { mutateAsync: createDocument, isPending: isCreating } = knowledge.useCreateDocumentMutation()
const { mutateAsync: updateDocument, isPending: isUpdating } = knowledge.useUpdateDocumentMutation()
const { mutate: publishDocument } = knowledge.usePublishDocumentMutation()
const { mutate: uploadSource } = knowledge.useUploadDocumentSourceMutation()
const { mutate: deleteDocument } = knowledge.useDeleteDocumentMutation()

const dialogOpen = ref(false)
const editingId = ref<string | null>(null)

const openDialog = (document: AiKnowledgeDocumentResource | null) => {
  editingId.value = document?.id ?? null
  dialogOpen.value = true
}

const save = async (payload: AiKnowledgeDocumentPayload) => {
  if (editingId.value) {
    await updateDocument({ id: editingId.value, payload })
    dialogOpen.value = false
  } else {
    editingId.value = (await createDocument(payload)).data.id
  }
}

const remove = async (document: AiKnowledgeDocumentResource) => {
  if (await alert.confirm(t('components.aiSettings.knowledge.confirmDelete', { title: document.title }))) {
    deleteDocument(document.id)
  }
}
</script>

<template>
  <SettingsSection
    id="knowledge"
    :title="$t('components.aiSettings.knowledge.title')"
    :description="$t('components.aiSettings.knowledge.description')"
    wide
  >
    <template #aside>
      <div v-if="canManage">
        <Button
          type="button"
          size="sm"
          variant="primary"
          @click="openDialog(null)"
        >
          <Icon name="lucide:plus" />
          {{ $t('components.aiSettings.knowledge.create') }}
        </Button>
      </div>
    </template>
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ $t('components.aiSettings.columns.title') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.type') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.status') }}</TableHead>
          <TableHead>{{ $t('components.aiSettings.columns.enabled') }}</TableHead>
          <TableHead class="text-right">{{ $t('components.aiSettings.columns.actions') }}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow
          v-for="document in documents ?? []"
          :key="document.id"
        >
          <TableCell>
            <div class="font-medium">{{ document.title }}</div>
            <div class="text-sm text-muted">{{ document.slug }}</div>
          </TableCell>
          <TableCell>{{ $t(`components.aiSettings.knowledge.types.${document.type}`) }}</TableCell>
          <TableCell class="space-x-1">
            <Badge
              v-if="document.published_revision"
              variant="success"
            >
              {{ $t('components.aiSettings.status.published', { revision: document.published_revision.revision }) }}
            </Badge>
            <Badge
              v-else
              variant="warning"
            >
              {{ $t('components.aiSettings.status.draft') }}
            </Badge>
            <Badge
              v-if="document.extraction_status === 'pending' || document.extraction_status === 'failed'"
              :variant="document.extraction_status === 'failed' ? 'destructive' : 'secondary'"
            >
              {{ $t(`components.aiSettings.knowledge.extraction.${document.extraction_status}`) }}
            </Badge>
          </TableCell>
          <TableCell>
            <Switch
              :model-value="document.enabled"
              :disabled="!canManage"
              :aria-label="$t('components.aiSettings.columns.enabled')"
              @update:model-value="(enabled: boolean) => updateDocument({ id: document.id, payload: { enabled } })"
            />
          </TableCell>
          <TableCell class="text-right">
            <div
              v-if="canManage"
              class="flex justify-end gap-1"
            >
              <Button
                type="button"
                size="sm"
                variant="ghost"
                @click="publishDocument(document.id)"
              >
                {{ $t('components.aiSettings.publish') }}
              </Button>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="$t('actions.edit')"
                @click="openDialog(document)"
              >
                <Icon name="lucide:pencil" />
              </Button>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                :aria-label="$t('actions.delete')"
                @click="remove(document)"
              >
                <Icon name="lucide:trash-2" />
              </Button>
            </div>
          </TableCell>
        </TableRow>
        <TableEmpty
          v-if="(documents ?? []).length === 0"
          :colspan="5"
        >
          {{ $t('components.aiSettings.knowledge.empty') }}
        </TableEmpty>
      </TableBody>
    </Table>

    <AiKnowledgeDocumentDialog
      v-model:open="dialogOpen"
      :owner="owner"
      :document-id="editingId"
      :saving="isCreating || isUpdating"
      @save="save"
      @upload="(file: File) => editingId && uploadSource({ id: editingId, file })"
    />
  </SettingsSection>
</template>
