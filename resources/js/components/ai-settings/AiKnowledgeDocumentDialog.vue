<script setup lang="ts">
import type {
  AiKnowledgeDocumentPayload,
  AiKnowledgeDocumentResource,
  AiKnowledgeType,
  AiOwner,
} from '~/api/resources/ai-knowledge'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { InputField, SelectField, TextField } from '~/components/ui/form'
import { slugify } from '~/lib/slug'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  owner: AiOwner
  documentId: string | null
  saving: boolean
}>()

const emit = defineEmits<{
  save: [payload: AiKnowledgeDocumentPayload]
  upload: [file: File]
}>()

const { t } = useI18n()
const knowledge = useAiKnowledge(() => props.owner)
const { data: document } = knowledge.useDocumentQuery(() => (open.value ? props.documentId : null))

const form = ref<{ title: string; slug: string; type: AiKnowledgeType; draft: string }>({
  title: '',
  slug: '',
  type: 'reference',
  draft: '',
})

const fill = (doc: AiKnowledgeDocumentResource | undefined) => {
  form.value = {
    title: doc?.title ?? '',
    slug: doc?.slug ?? '',
    type: doc?.type ?? 'reference',
    draft: doc?.draft ?? '',
  }
}

watch(open, (isOpen) => isOpen && fill(document.value))
watch(
  () => document.value?.draft,
  () => fill(document.value)
)
watch(
  () => form.value.title,
  (title) => {
    if (!props.documentId) form.value.slug = slugify(title)
  }
)

const typeOptions = computed(() => [
  { value: 'reference' as const, label: t('components.aiSettings.knowledge.types.reference') },
  { value: 'style' as const, label: t('components.aiSettings.knowledge.types.style') },
])

const onFile = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) emit('upload', file)
  input.value = ''
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-3xl">
      <DialogHeader>
        <DialogTitle>
          {{ documentId ? $t('components.aiSettings.knowledge.edit') : $t('components.aiSettings.knowledge.create') }}
        </DialogTitle>
        <DialogDescription>{{ $t('components.aiSettings.knowledge.editorHint') }}</DialogDescription>
      </DialogHeader>

      <div class="max-h-[60vh] space-y-4 overflow-y-auto">
        <div class="grid gap-4 sm:grid-cols-3">
          <InputField
            id="knowledge-title"
            name="knowledge-title"
            v-model="form.title"
            :label="$t('components.aiSettings.columns.title')"
            required
          />
          <InputField
            id="knowledge-slug"
            name="knowledge-slug"
            v-model="form.slug"
            label="Slug"
            required
          />
          <SelectField
            id="knowledge-type"
            v-model="form.type"
            name="type"
            :label="$t('components.aiSettings.columns.type')"
            :options="typeOptions"
          />
        </div>
        <div
          v-if="documentId"
          class="flex items-center gap-3"
        >
          <label class="cursor-pointer text-sm font-medium text-primary underline-offset-4 hover:underline">
            {{ $t('components.aiSettings.knowledge.upload') }}
            <input
              type="file"
              class="sr-only"
              accept=".pdf,.docx,.md,.txt"
              @change="onFile"
            />
          </label>
          <span
            v-if="document?.source_name"
            class="text-sm text-muted"
          >
            {{ document.source_name }}
          </span>
          <Badge
            v-if="document?.extraction_status"
            :variant="document.extraction_status === 'failed' ? 'destructive' : 'secondary'"
          >
            {{ $t(`components.aiSettings.knowledge.extraction.${document.extraction_status}`) }}
          </Badge>
        </div>
        <TextField
          id="knowledge-draft"
          name="knowledge-draft"
          v-model="form.draft"
          :label="$t('components.aiSettings.knowledge.markdown')"
          input-class="min-h-80 font-mono text-sm"
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
          @click="emit('save', { ...form })"
        >
          {{ $t('actions.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
