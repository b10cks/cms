<script setup lang="ts">
import type {
  AiKnowledgeDocumentPayload,
  AiKnowledgeDocumentResource,
  AiKnowledgeType,
  AiOwner,
} from '~/api/resources/ai-knowledge'
import AiMarkdownEditor from '~/components/ai-settings/AiMarkdownEditor.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { InputField, SelectField } from '~/components/ui/form'
import { Panel } from '~/components/ui/panel'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { aiSettingsRoutes } from '~/lib/aiSettingsRoutes'
import { slugify } from '~/lib/slug'

/**
 * A style guide or reference document: title, type and markdown draft, with an
 * optional PDF or DOCX source whose text is extracted into the draft. Runs use
 * the published revision. `documentId` is `new` until the first save.
 */
const props = defineProps<{
  owner: AiOwner
  documentId: string
  canManage: boolean
  initialType?: AiKnowledgeType
}>()

const { t } = useI18n()
const router = useRouter()
const { alert } = useAlertDialog()
const knowledge = useAiKnowledge(() => props.owner)
const routes = computed(() => aiSettingsRoutes(props.owner))

const isNew = computed(() => props.documentId === 'new')
const { data: document, isLoading } = knowledge.useDocumentQuery(() => (isNew.value ? null : props.documentId))
const { mutateAsync: createDocument, isPending: isCreating } = knowledge.useCreateDocumentMutation()
const { mutateAsync: updateDocument, isPending: isUpdating } = knowledge.useUpdateDocumentMutation()
const { mutateAsync: publishDocument, isPending: isPublishing } = knowledge.usePublishDocumentMutation()
const { mutate: uploadSource, isPending: isUploading } = knowledge.useUploadDocumentSourceMutation()
const { mutateAsync: deleteDocument } = knowledge.useDeleteDocumentMutation()

const form = ref({ title: '', type: props.initialType ?? ('reference' as AiKnowledgeType), draft: '' })
const saved = ref('')
const snapshot = () => JSON.stringify(form.value)
const isDirty = computed(() => snapshot() !== saved.value)

const fill = (doc: AiKnowledgeDocumentResource | undefined) => {
  form.value = {
    title: doc?.title ?? '',
    type: doc?.type ?? props.initialType ?? 'reference',
    draft: doc?.draft ?? '',
  }
  saved.value = snapshot()
}

fill(undefined)

// Extraction fills the draft on the server; take it over unless the editor has unsaved edits.
watch(
  document,
  (doc, previous) => {
    if (doc && (doc.id !== previous?.id || !isDirty.value)) fill(doc)
  },
  { immediate: true }
)

const typeOptions = computed(() => [
  { value: 'style' as const, label: t('components.aiSettings.knowledge.types.style') },
  { value: 'reference' as const, label: t('components.aiSettings.knowledge.types.reference') },
])

const header = computed(() => {
  if (form.value.title.trim()) return form.value.title
  return isNew.value
    ? t(`components.aiSettings.knowledge.newOfType.${form.value.type}`)
    : (document.value?.title ?? '')
})

useSeoMeta({ title: header })

const isSaving = computed(() => isCreating.value || isUpdating.value || isPublishing.value)

/** Saves the draft; a new document is created and the route moves to its ID. */
const save = async (): Promise<string | null> => {
  if (!form.value.title.trim()) return null
  const payload: AiKnowledgeDocumentPayload = { ...form.value, title: form.value.title.trim() }

  if (isNew.value) {
    const { data } = await createDocument({ ...payload, slug: slugify(payload.title ?? '') })
    saved.value = snapshot()
    void router.replace(routes.value.document(data.id))
    return data.id
  }

  await updateDocument({ id: props.documentId, payload })
  saved.value = snapshot()
  return props.documentId
}

const publish = async () => {
  const id = await save()
  if (id) await publishDocument(id)
}

const onFile = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file && !isNew.value) uploadSource({ id: props.documentId, file })
}

const remove = async () => {
  const current = document.value
  if (!current) return
  if (await alert.confirm(t('components.aiSettings.knowledge.confirmDelete', { title: current.title }))) {
    await deleteDocument(current.id)
    void router.push(routes.value.knowledge)
  }
}

const statusBadge = computed(() => {
  const revision = document.value?.published_revision
  if (!revision) return { label: t('components.aiSettings.status.draft'), variant: 'warning' as const }
  if (document.value?.has_unpublished_changes) {
    return { label: t('components.aiSettings.status.changed', { revision: revision.revision }), variant: 'secondary' as const }
  }
  return { label: t('components.aiSettings.status.published', { revision: revision.revision }), variant: 'success' as const }
})
</script>

<template>
  <div class="grid min-w-0 gap-4">
    <ContentHeader
      :header="header"
      :description="$t('components.aiSettings.knowledge.editorHint')"
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
      <template #before-header>
        <Badge
          v-if="!isNew && document"
          :variant="statusBadge.variant"
          class="order-last"
        >
          {{ statusBadge.label }}
        </Badge>
      </template>
      <template
        v-if="canManage"
        #actions
      >
        <div class="flex items-center gap-2">
          <Button
            variant="outline"
            :disabled="!form.title.trim() || isSaving || (!isNew && !isDirty)"
            :loading="isCreating || isUpdating"
            @click="save"
          >
            {{ $t('components.aiSettings.saveDraft') }}
          </Button>
          <Button
            variant="primary"
            :disabled="!form.title.trim() || isSaving"
            :loading="isPublishing"
            @click="publish"
          >
            {{ $t('components.aiSettings.publish') }}
          </Button>
          <DropdownMenu v-if="!isNew">
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
                class="text-destructive"
                @select="remove"
              >
                <Icon name="lucide:trash-2" />
                {{ $t('components.aiSettings.knowledge.delete') }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </template>
    </ContentHeader>

    <Panel v-if="isNew || document || isLoading">
      <div class="grid gap-6">
        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
          <InputField
            id="knowledge-title"
            v-model="form.title"
            name="title"
            :label="$t('components.aiSettings.columns.title')"
            :disabled="!canManage"
            required
          />
          <SelectField
            id="knowledge-type"
            v-model="form.type"
            name="type"
            :label="$t('components.aiSettings.columns.type')"
            :options="typeOptions"
            :disabled="!canManage"
          />
        </div>

        <div
          v-if="!isNew && canManage"
          class="flex flex-wrap items-center gap-2"
        >
          <span
            v-if="document?.source_name"
            class="inline-flex min-w-0 items-center gap-2 rounded-md bg-chip px-2 py-1 text-sm text-chip-foreground"
          >
            <Icon name="lucide:file-text" />
            <span class="truncate">{{ document.source_name }}</span>
          </span>
          <Badge
            v-if="document?.extraction_status === 'pending' || document?.extraction_status === 'failed'"
            :variant="document.extraction_status === 'failed' ? 'destructive' : 'secondary'"
          >
            {{ $t(`components.aiSettings.knowledge.extraction.${document.extraction_status}`) }}
          </Badge>
          <Button
            as="label"
            variant="outline"
            size="sm"
            class="cursor-pointer focus-within:ring-2 focus-within:ring-ring"
            :loading="isUploading"
          >
            <Icon name="lucide:upload" />
            {{ document?.source_name ? $t('components.aiSettings.knowledge.replaceSource') : $t('components.aiSettings.knowledge.upload') }}
            <input
              type="file"
              class="sr-only"
              accept=".pdf,.docx,.md,.txt"
              :disabled="isUploading"
              @change="onFile"
            />
          </Button>
        </div>

        <AiMarkdownEditor
          id="knowledge-draft"
          v-model="form.draft"
          :label="$t('components.aiSettings.knowledge.markdown')"
          :disabled="!canManage"
        />
      </div>
    </Panel>
    <p
      v-else
      class="text-sm text-muted"
    >
      {{ $t('components.aiSettings.knowledge.notFound') }}
    </p>
  </div>
</template>
