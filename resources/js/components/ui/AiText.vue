<script setup lang="ts">
import type { MentionItem } from '~/api/resources/ai'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import Icon from '~/components/Icon.vue'
import AiConfigSelector from '~/components/ui/AiConfigSelector.vue'
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupTipTap,
} from '~/components/ui/input-group'
import type { StreamCallbacks } from '~/composables/useAiContent'
import { useAiContent } from '~/composables/useAiContent'
import { useAiMentions } from '~/composables/useAiMentions'
import { useAiConfigs } from '~/composables/useAiModels'

export type AttachedFile = never

const modelValue = defineModel<string | null>()
const editorValue = computed({
  get: () => modelValue.value ?? '',
  set: (value: string) => {
    modelValue.value = value
  },
})

const emit = defineEmits<{
  (e: 'send', value: string, files: never[], configId: string | null, mentions: MentionItem[]): void
  (e: 'cancel'): void
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
    defaultConfigId?: string | null
    showConfigSelector?: boolean
    directEmit?: boolean
    extraMentionItems?: AiMentionItem[]
  }>(),
  {
    placeholder: '',
    loading: false,
    contentId: null,
    content: null,
    defaultConfigId: null,
    showConfigSelector: true,
    directEmit: false,
    extraMentionItems: () => [],
  }
)

const { t } = useI18n()
const { showAiError } = useAiErrorToast()
const { streamContentInteraction, cancelStream, isStreaming } = useAiContent(
  toRef(props, 'spaceId')
)
const { useAiConfigsQuery } = useAiConfigs(toRef(props, 'spaceId'))
const { useMentionItemsQuery } = useAiMentions(toRef(props, 'spaceId'))

const { data: aiConfigs, isLoading: isLoadingConfigs } = useAiConfigsQuery()
const { items: mentionItems } = useMentionItemsQuery()
const mergedMentionItems = computed(() => {
  const combined = [...mentionItems.value, ...props.extraMentionItems]
  const seen = new Set<string>()

  return combined.filter((item) => {
    const key = `${item.type}:${item.id}`
    if (seen.has(key)) {
      return false
    }

    seen.add(key)
    return true
  })
})

const canSend = computed(
  () =>
    (modelValue.value?.trim()?.length ?? 0) > 0 &&
    !isStreaming.value &&
    !props.loading &&
    !!selectedConfigId.value
)

const tiptapRef = ref<InstanceType<typeof InputGroupTipTap> | null>(null)

const selectedConfigId = ref<string | null>(props.defaultConfigId ?? null)
const statusMessage = ref<string | null>(null)
const previewContent = ref<string | null>(null)
const isThinking = ref(false)

const selectedConfig = computed(() => {
  if (!selectedConfigId.value || !aiConfigs.value) return null
  return aiConfigs.value.find((c) => c.id === selectedConfigId.value) ?? null
})

const defaultConfig = computed(() => {
  return aiConfigs.value?.find((c) => c.is_default) ?? null
})

watch(
  () => defaultConfig.value,
  (config) => {
    if (config && !selectedConfigId.value) {
      selectedConfigId.value = config.id
    }
  },
  { immediate: true }
)

watch(
  () => props.defaultConfigId,
  (newConfigId) => {
    if (newConfigId) {
      selectedConfigId.value = newConfigId
    }
  }
)

const dynamicPlaceholder = computed(() => {
  if (props.placeholder) return props.placeholder

  if (!selectedConfig.value) {
    return t('components.aiText.placeholderSelectConfig')
  }

  return t('components.aiText.placeholderDefault', { name: selectedConfig.value.name })
})

function stripCodeFences(content: string): string {
  return content
    .replace(/^```(?:json|javascript|js)?\s*\n?/i, '')
    .replace(/\n?```\s*$/i, '')
    .trim()
}

const clear = () => {
  modelValue.value = null
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

  const prompt = (tiptapRef.value?.getTextWithMentions() ?? modelValue.value ?? '').trim()
  if (!prompt) return

  const editorMentions = tiptapRef.value?.getMentions() ?? []

  if (props.directEmit) {
    emit(
      'send',
      prompt,
      [],
      selectedConfigId.value,
      editorMentions.map((m) => ({ type: m.type, id: m.id, content: null, label: m.label }))
    )
    clear()
    return
  }

  isThinking.value = true
  statusMessage.value = t('components.aiText.thinking') as string

  const callbacks: StreamCallbacks = {
    onStatus: (message) => {
      isThinking.value = false
      statusMessage.value = message
    },
    onDelta: (content) => {
      statusMessage.value = null
      previewContent.value = (previewContent.value ?? '') + content
    },
    onDone: (content) => {
      statusMessage.value = null
      previewContent.value = null
      isThinking.value = false
      emit(
        'send',
        stripCodeFences(content),
        [],
        selectedConfigId.value,
        editorMentions.map((m) => ({
          type: m.type,
          id: m.id,
          content: null,
          label: m.label,
        }))
      )
      emit('streamEnd')
    },
    onError: (message, reason) => {
      showAiError(reason, message)
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
      files: [],
      config_id: selectedConfigId.value,
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
  if (props.directEmit) {
    emit('cancel')
    return
  }
  cancelStream()
  statusMessage.value = null
  previewContent.value = null
  isThinking.value = false
  emit('streamEnd')
}

const configError = computed(() => {
  if (!selectedConfigId.value && props.showConfigSelector && !isLoadingConfigs.value) {
    return t('components.aiText.noConfigSelected')
  }
  return null
})

defineExpose({
  clear,
  selectedConfigId,
})
</script>

<template>
  <div class="relative w-full space-y-2">
    <Transition
      mode="out-in"
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0 translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div
        v-if="statusMessage || isThinking"
        :key="statusMessage || 'thinking'"
        class="py-2 text-sm"
      >
        <div class="ai-animate-text">
          {{ statusMessage || $t('components.aiText.thinking') }}
        </div>
      </div>
    </Transition>

    <div
      v-if="previewContent"
      class="max-h-40 overflow-y-auto rounded-lg border border-ai/20 bg-popover/60 px-3 py-2 font-mono text-xs text-muted whitespace-pre-wrap"
    >
      {{ previewContent }}
    </div>

    <InputGroup
      class="relative rounded-2xl! bg-popover/80 backdrop-blur transition-all"
      :class="[
        isStreaming || isThinking
          ? 'ring-1 ring-ai/30'
          : 'focus-within:ring-1 focus-within:ring-ai/30',
      ]"
    >
      <InputGroupTipTap
        ref="tiptapRef"
        v-model="editorValue"
        :placeholder="dynamicPlaceholder"
        :disabled="loading || isStreaming"
        :mention-items="mergedMentionItems"
        @submit="handleSend"
      />

      <InputGroupAddon align="block-end">
        <AiConfigSelector
          v-if="showConfigSelector"
          v-model="selectedConfigId"
          :space-id="spaceId"
          :error="configError"
        />

        <InputGroupButton
          v-if="!isStreaming && !loading"
          variant="ai"
          size="round"
          :disabled="!canSend"
          @click="handleSend"
        >
          <Icon name="lucide:arrow-up" />
          <span class="sr-only">{{ $t('actions.send') }}</span>
        </InputGroupButton>

        <InputGroupButton
          v-if="isStreaming || loading"
          variant="ghost"
          size="round"
          @click="handleCancel"
          class="animate-pulse"
        >
          <Icon name="lucide:circle-stop" />
          <span class="sr-only">{{ $t('actions.cancel') }}</span>
        </InputGroupButton>
      </InputGroupAddon>
    </InputGroup>
  </div>
</template>
