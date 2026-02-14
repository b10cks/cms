<script setup lang="ts">
import AiText, { type AttachedFile } from '~/components/ui/AiText.vue'
import type { ContentResource } from '~/types/contents'
import type { MentionItem } from '~/api/resources/ai'

const content = defineModel<ContentResource | null>('content', { required: true })

const props = defineProps<{
  spaceId: string
  contentId: string
  placeholder?: string
}>()

const emit = defineEmits<{
  (e: 'update:content', value: ContentResource | null): void
}>()

type PreviewUpdateFunction = (data: Record<string, unknown>) => void
const updatePreviewItem = inject<PreviewUpdateFunction>('updatePreviewItem')

const aiTextRef = useTemplateRef('aiTextRef')
const selectedModel = ref<string | null>(null)

const handleSubmit = (resultContent: string, _files: AttachedFile[], model: string | null, _mentions: MentionItem[]) => {
  if (!content.value) return

  selectedModel.value = model

  let updatedContent: ContentResource

  try {
    const parsed = JSON.parse(resultContent)
    updatedContent = {
      ...content.value,
      ...parsed,
    }
  } catch {
    updatedContent = {
      ...content.value,
      content: resultContent,
    }
  }

  emit('update:content', updatedContent)

  if (updatePreviewItem && updatedContent.id) {
    updatePreviewItem({
      id: updatedContent.id,
      ...(typeof updatedContent.content === 'object' ? updatedContent.content : {}),
    })
  }

  aiTextRef.value?.clear()
}

defineExpose({ clear: () => aiTextRef.value?.clear() })
</script>

<template>
  <AiText
    ref="aiTextRef"
    :space-id="spaceId"
    :default-model="selectedModel"
    :placeholder="placeholder"
    :content="content?.content"
    @send="handleSubmit"
  />
</template>