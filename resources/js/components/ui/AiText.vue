<script setup lang="ts">
import type { MentionItem } from '~/api/resources/ai'
import type { AiMentionItem } from '~/components/editor/extensions/AiMention'
import Icon from '~/components/Icon.vue'
import type { TipTapCommandItem } from '~/components/ui/input-group/InputGroupTipTap.vue'
import AiConfigSelector from '~/components/ui/AiConfigSelector.vue'
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupTipTap,
} from '~/components/ui/input-group'
import { Message, MessageContent } from '~/components/ui/message'
import {
  MessageScrollerProvider,
  MessageScroller,
  MessageScrollerViewport,
  MessageScrollerContent,
  MessageScrollerItem,
  MessageScrollerButton,
} from '~/components/ui/message-scroller'
import AiQuestionnaireForm from '~/components/ai/AiQuestionnaireForm.vue'
import { useAiMentions } from '~/composables/useAiMentions'
import { useAiConfigs } from '~/composables/useAiModels'
import { useAiRun } from '~/composables/useAiRun'
import { stripAiCodeFences } from '~/lib/aiJson'

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
  (e: 'command', item: TipTapCommandItem): void
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
    /** `/` commands at the start of the input */
    commands?: TipTapCommandItem[]
    /** Send without text, when a picked skill is the task */
    allowEmpty?: boolean
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
    commands: () => [],
    allowEmpty: false,
  }
)

const { t } = useI18n()
const { showAiError } = useAiErrorToast()
const run = useAiRun(toRef(props, 'spaceId'))
const { isActive: isStreaming, questionnaire, answer } = run
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
    ((modelValue.value?.trim()?.length ?? 0) > 0 || props.allowEmpty) &&
    !isStreaming.value &&
    !isThinking.value &&
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

const clear = () => {
  modelValue.value = null
  statusMessage.value = null
  previewContent.value = null
  tiptapRef.value?.clear()
}

const handleSend = async () => {
  if (!canSend.value) return

  const prompt = (tiptapRef.value?.getTextWithMentions() ?? modelValue.value ?? '').trim()
  if (!prompt && !props.allowEmpty) return

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

  previewContent.value = null
  isThinking.value = true
  statusMessage.value = t('components.aiText.thinking') as string

  const mentions = editorMentions.map((m) => ({
    type: m.type,
    id: m.id,
    content: null,
    label: m.label,
  }))

  emit('streamStart')

  const outcome = await run.start(
    {
      surface: 'canvas',
      skill: 'content-interaction',
      prompt,
      context: {
        content_id: props.contentId ?? null,
        content: props.content,
        mentions,
        files: [],
      },
      config_id: selectedConfigId.value,
    },
    {
      onEvent: (event) => {
        if (event.type === 'text_delta') {
          statusMessage.value = null
          previewContent.value = (previewContent.value ?? '') + event.text
        } else if (event.type === 'tool_started') {
          statusMessage.value = t('components.aiText.working') as string
        } else if (event.type === 'moved_to_background') {
          statusMessage.value = t('components.aiText.background') as string
        } else if (event.type === 'questionnaire') {
          statusMessage.value = null
        }
      },
    }
  )

  statusMessage.value = null
  isThinking.value = false

  if (outcome.status === 'completed' && outcome.result) {
    const { data, text } = outcome.result
    emit(
      'send',
      data !== null ? JSON.stringify(data) : stripAiCodeFences(text),
      [],
      selectedConfigId.value,
      mentions
    )
  } else if (outcome.status === 'failed') {
    showAiError(outcome.error?.reason, outcome.error?.message)
  }

  emit('streamEnd')
}

const handleCancel = () => {
  if (props.directEmit) {
    emit('cancel')
    return
  }
  void run.cancel()
  statusMessage.value = null
  previewContent.value = null
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
  focus: () => tiptapRef.value?.focus(),
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
        v-if="statusMessage || (isThinking && !previewContent)"
        :key="statusMessage || 'thinking'"
        class="py-2 text-sm"
        role="status"
      >
        <div class="ai-animate-text">
          {{ statusMessage || $t('components.aiText.thinking') }}
        </div>
      </div>
    </Transition>

    <MessageScrollerProvider
      v-if="previewContent"
      auto-scroll
    >
      <MessageScroller class="h-40 rounded-lg border border-border bg-popover">
        <MessageScrollerViewport>
          <MessageScrollerContent class="gap-2 p-3">
            <MessageScrollerItem message-id="response">
              <Message>
                <MessageContent class="whitespace-pre-wrap text-sm text-foreground">
                  {{ previewContent }}
                </MessageContent>
              </Message>
            </MessageScrollerItem>
          </MessageScrollerContent>
        </MessageScrollerViewport>
        <MessageScrollerButton />
      </MessageScroller>
    </MessageScrollerProvider>

    <AiQuestionnaireForm
      v-if="questionnaire"
      :questionnaire="questionnaire"
      @submit="answer"
    />

    <InputGroup
      class="relative rounded-xl bg-popover transition-colors"
      :class="[
        isStreaming || isThinking
          ? 'ring-1 ring-ai/30'
          : 'focus-within:ring-1 focus-within:ring-ai/30',
      ]"
    >
      <InputGroupAddon
        v-if="$slots.context"
        align="block-start"
        class="flex-wrap gap-1.5"
      >
        <slot name="context" />
      </InputGroupAddon>
      <InputGroupTipTap
        ref="tiptapRef"
        v-model="editorValue"
        :placeholder="dynamicPlaceholder"
        :disabled="loading || isStreaming || isThinking"
        :mention-items="mergedMentionItems"
        :commands="commands"
        @submit="handleSend"
        @command="(item) => emit('command', item)"
      />

      <InputGroupAddon align="block-end">
        <AiConfigSelector
          v-if="showConfigSelector"
          v-model="selectedConfigId"
          :space-id="spaceId"
          :error="configError"
          :disabled="loading || isStreaming || isThinking"
        />

        <InputGroupButton
          type="button"
          v-if="!isStreaming && !isThinking && !loading"
          variant="ai"
          size="round"
          :disabled="!canSend"
          @click="handleSend"
        >
          <Icon name="lucide:arrow-up" />
          <span class="sr-only">{{ $t('actions.send') }}</span>
        </InputGroupButton>

        <InputGroupButton
          type="button"
          v-if="isStreaming || isThinking || loading"
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
