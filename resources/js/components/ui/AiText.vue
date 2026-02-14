<script setup lang="ts">
import { toast } from 'vue-sonner'
import type { MentionItem } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupTipTap } from '~/components/ui/input-group'
import type { StreamCallbacks } from '~/composables/useAiContent'

import AiModelSelector from '~/components/ui/AiModelSelector.vue'
import { useAiContent } from '~/composables/useAiContent'
import { useAiMentions } from '~/composables/useAiMentions'
import { useAiSettings } from '~/composables/useAiModels'

export interface AttachedFile {
  name: string
  url: string
  type: string
}

const modelValue = defineModel<string | null>()

const emit = defineEmits<{
  (e: 'send', value: string, files: AttachedFile[], model: string | null, mentions: MentionItem[]): void
  (e: 'streamStart'): void
  (e: 'streamEnd'): void
}>()

const props = withDefaults(
  defineProps<{
    placeholder?: string
    loading?: boolean
    spaceId: string
    contentId?: string | null
    content?: object | null
    defaultModel?: string | null
    showModelSelector?: boolean
  }>(),
  {
    placeholder: 'Ask AI...',
    loading: false,
    contentId: null,
    content: null,
    defaultModel: null,
    showModelSelector: true,
  }
)

const { t } = useI18n()
const { streamContentInteraction, cancelStream, isStreaming } = useAiContent(toRef(props, 'spaceId'))
const { useAiSettingsQuery } = useAiSettings(toRef(props, 'spaceId'))
const { useMentionItemsQuery } = useAiMentions(toRef(props, 'spaceId'))

const { data: aiSettings } = useAiSettingsQuery()
const { items: mentionItems } = useMentionItemsQuery()

const canSend = computed(() => (modelValue.value?.trim()?.length ?? 0) > 0 && !isStreaming.value && !props.loading)

const attachedFiles = ref<AttachedFile[]>([])
const isDragOver = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)
const tiptapRef = ref<InstanceType<typeof InputGroupTipTap> | null>(null)

const selectedModel = ref<string | null>(props.defaultModel ?? null)
const statusMessage = ref<string | null>(null)
const previewContent = ref<string | null>(null)
const isThinking = ref(false)

watch(
  () => aiSettings.value?.model,
  (newModel) => {
    if (newModel && !selectedModel.value) {
      selectedModel.value = newModel
    }
  },
  { immediate: true }
)

watch(
  () => props.defaultModel,
  (newModel) => {
    if (newModel) {
      selectedModel.value = newModel
    }
  }
)

const handleDragOver = (e: DragEvent) => {
  e.preventDefault()
  isDragOver.value = true
}

const handleDragLeave = () => {
  isDragOver.value = false
}

const handleDrop = (e: DragEvent) => {
  e.preventDefault()
  isDragOver.value = false
  if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
    // processFiles(Array.from(e.dataTransfer.files))
  }
}

const handleFileBrowse = () => {
  fileInputRef.value?.click()
}

const handleFileChange = (e: Event) => {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    processFiles(Array.from(target.files))
  }
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

const processFiles = async (files: File[]) => {
  for (const file of files) {
    const reader = new FileReader()
    reader.onload = (e) => {
      const result = e.target?.result
      if (typeof result === 'string') {
        attachedFiles.value.push({
          name: file.name,
          url: result,
          type: file.type,
        })
      }
    }
    reader.readAsDataURL(file)
  }
}

const removeFile = (index: number) => {
  attachedFiles.value.splice(index, 1)
}

const clear = () => {
  modelValue.value = null
  attachedFiles.value = []
  statusMessage.value = null
  previewContent.value = null
  tiptapRef.value?.clear()
}

const handleSend = async () => {
  if (!canSend.value) return

  if (isStreaming.value) {
    cancelStream()
    statusMessage.value = null
    previewContent.value = null
    isThinking.value = false
    emit('streamEnd')
    return
  }

  const prompt = modelValue.value?.trim() ?? ''
  if (!prompt) return

  const editorMentions = tiptapRef.value?.getMentions() ?? []

  isThinking.value = true
  statusMessage.value = t('components.aiText.thinking') as string

  const callbacks: StreamCallbacks = {
    onStatus: (message) => {
      isThinking.value = false
      statusMessage.value = message
    },
    onDelta: (content) => {
      previewContent.value = (previewContent.value ?? '') + content
    },
    onDone: (content) => {
      statusMessage.value = null
      previewContent.value = null
      isThinking.value = false
      emit('send', content, attachedFiles.value, selectedModel.value, editorMentions.map((m) => ({
        type: m.type,
        id: m.id,
        content: null,
        label: m.label,
      })))
      emit('streamEnd')
    },
    onError: (message) => {
      toast.error(t('components.aiText.streamError', { error: message }) as string)
      statusMessage.value = null
      previewContent.value = null
      isThinking.value = false
      emit('streamEnd')
    },
  }

  emit('streamStart')

  await streamContentInteraction(
    {
      prompt,
      content: props.content,
      content_id: props.contentId ?? null,
      files: attachedFiles.value,
      model: selectedModel.value,
      mentions: editorMentions.map((m) => ({
        type: m.type,
        id: m.id,
        content: null,
        label: m.label,
      })),
    },
    callbacks
  )
}

const handleCancel = () => {
  cancelStream()
  statusMessage.value = null
  previewContent.value = null
  isThinking.value = false
  emit('streamEnd')
}

defineExpose({
  clear,
  selectedModel,
})
</script>

<template>
  <div
    :class="[
      'relative rounded-xl transition-all w-full',
      isDragOver ? 'bg-primary/10 ring-2 ring-primary' : '',
      statusMessage ? 'pt-8' : ''
    ]"
    @dragover="handleDragOver"
    @dragleave="handleDragLeave"
    @drop="handleDrop"
  >
      <div
        v-if="statusMessage"
        class="absolute top-0 ease-bounce duration-1000 inset-x-3 text-center py-2 animate-in slide-out-to-bottom-full slide-in-from-bottom-full"
      >
        <div
          v-if="statusMessage"
          class="flex items-center justify-center gap-2 text-sm font-medium tracking-wider ai-animate-text"
        >
          <span class="truncate">{{ statusMessage }}</span>
        </div>
        <div
          v-else-if="previewContent && isStreaming"
          class="flex items-start gap-2"
        >
          <Icon
            name="lucide:text-quote"
            class="mt-0.5 shrink-0"
          />
          <pre class="max-h-32 flex-1 overflow-auto text-xs whitespace-pre-wrap">{{ previewContent.slice(-500) }}</pre>
        </div>
      </div>
    <div
      v-if="isStreaming || isThinking"
      class="pointer-events-none absolute inset-0 z-0"
    />

    <div
      v-if="isDragOver"
      class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-primary/10"
    >
      <div class="flex flex-col items-center gap-2 text-primary">
        <Icon
          name="lucide:file-plus"
          size="1.5rem"
        />
        <span class="text-sm font-medium">{{ $t('labels.settings.ai.dropFiles') }}</span>
      </div>
    </div>

    <InputGroup class="relative z-1 rounded-2xl! bg-popover">
      <input
        ref="fileInputRef"
        type="file"
        multiple
        class="hidden"
        :disabled="true || loading || isStreaming"
        @change="handleFileChange"
      />
      <InputGroupTipTap
        ref="tiptapRef"
        v-model="modelValue"
        :placeholder="placeholder"
        :disabled="loading || isStreaming"
        :mention-items="mentionItems"
        @submit="handleSend"
      />

      <div
        v-if="attachedFiles.length > 0"
        class="flex flex-wrap gap-1 px-3 w-full"
      >
        <div
          v-for="(file, index) in attachedFiles"
          :key="index"
          class="flex items-center gap-1 rounded-md bg-secondary px-2 py-1 text-xs"
        >
          <Icon name="lucide:file" />
          <span class="max-w-24 truncate">{{ file.name }}</span>
          <button
            type="button"
            class="ml-1 text-muted-foreground hover:text-foreground"
            :disabled="loading || isStreaming"
            @click="removeFile(index)"
          >
            <Icon name="lucide:x"/>
          </button>
        </div>
      </div>

      <InputGroupAddon align="block-end">
        <InputGroupButton
          size="sm"
          :disabled="true ||loading || isStreaming"
          @click="handleFileBrowse"
        >
          <Icon name="lucide:paperclip" />
        </InputGroupButton>
        <AiModelSelector
          v-if="showModelSelector"
          v-model="selectedModel"
          :space-id="spaceId"
          :show-favourites="true"
          :show-costs="true"
        />
        <InputGroupButton
          variant="ai"
          size="round"
          v-if="!isStreaming"
          :disabled="!canSend"
          @click="handleSend"
        >
          <Icon
            v-if="isStreaming || loading"
            name="lucide:loader"
            class="animate-spin"
          />
          <Icon
            v-else
            name="lucide:arrow-up"
          />
          <span class="sr-only">Send</span>
        </InputGroupButton>
        <InputGroupButton
          v-if="isStreaming || loading"
          variant="ghost"
          size="round"
          @click="handleCancel"
        ><Icon
            name="lucide:circle-stop"
          />
        </InputGroupButton>
      </InputGroupAddon>
    </InputGroup>
  </div>
</template>
